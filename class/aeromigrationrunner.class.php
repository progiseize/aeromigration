<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/aeromigrationrunner.class.php
 * \ingroup aeromigration
 * \brief   Socle commun des scripts de reprise de données.
 *
 * Cette classe abstraite porte tout ce qui est indépendant de l'entité migrée :
 * parcours de la source par lots, reprise après interruption, idempotence, simulation,
 * comptage et collecte des erreurs. Une classe fille n'a plus qu'à décrire sa source et
 * à implémenter migrateRow().
 *
 * Trois principes structurent le socle :
 *
 * 1. ÉCRITURE VIA L'API DOLIBARR UNIQUEMENT. Les classes filles instancient les objets
 *    métier (Societe, Contact, Product…) et appellent create()/update(). Le SQL direct
 *    est réservé à la LECTURE des tables sources, qui ne sont pas des objets Dolibarr.
 *
 * 2. IDEMPOTENCE PAR ref_ext. Chaque objet créé porte dans son champ natif ref_ext la
 *    clé de l'enregistrement source, préfixée (ex : « SAGE:C0001 »). Relancer un script
 *    ne recrée donc pas ce qui existe déjà, sans avoir besoin d'une table de
 *    correspondance dédiée. L'index ref_ext -> rowid est chargé une fois en mémoire au
 *    démarrage, ce qui évite un fetch() par ligne.
 *
 * 3. PARCOURS PAR CURSEUR, PAS PAR OFFSET. La source est lue par tranches ordonnées sur
 *    une colonne entière croissante (WHERE cbMarq > n LIMIT x). Contrairement à
 *    LIMIT/OFFSET, le coût reste constant quelle que soit la profondeur atteinte, et la
 *    reprise après interruption se résume à repartir du dernier curseur affiché.
 */
abstract class AeroMigrationRunner
{
    /** @var DoliDB Handler de base de données */
    public $db;

    /** @var User Utilisateur au nom duquel les objets Dolibarr sont créés */
    public $user;

    /** @var string Identifiant court du script, utilisé en ligne de commande */
    public $code = '';

    /** @var string Clé de traduction du libellé du script */
    public $label = '';

    // ── Description de la source ───────────────────────────────────────────

    /**
     * Base où lire les tables source, vide pour celle de Dolibarr.
     *
     * Les premiers travaux se sont appuyés sur une vingtaine de tables Sage importées à
     * côté des `llx_*`. L'éditeur a depuis livré son dossier entier — plusieurs centaines
     * de tables, dont les tables applicatives qui portent les tarifs par catégorie et les
     * règlements — et les recopier dans la base de Dolibarr reviendrait à y verser
     * plusieurs gigaoctets qui n'ont rien à y faire.
     *
     * Renseignée, elle qualifie toutes les lectures de source. Laissée vide, rien ne change
     * pour les scripts écrits avant elle.
     *
     * @var string
     */
    public $sourceDb = '';

    /** @var string Table source lue (tables Sage, sans préfixe llx_) */
    protected $srcTable = '';

    /** @var string Colonnes sélectionnées */
    protected $srcFields = '*';

    /**
     * Colonne de parcours. Doit être unique, indexée et totalement ordonnée : la clé
     * primaire de la table source est le choix naturel.
     *
     * Attention, la colonne technique cbMarq des tables Sage n'est PAS utilisable
     * partout : selon les tables elle est auto-incrémentée ou constamment nulle. Il faut
     * la vérifier avant de s'en servir.
     *
     * @var string
     */
    protected $srcCursorField = '';

    /**
     * Type de la colonne de parcours : 'int' ou 'string'. Détermine le quotage dans la
     * clause de reprise.
     *
     * @var string
     */
    protected $srcCursorType = 'int';

    /**
     * Expression SQL désignant la colonne de parcours, lorsqu'elle diffère du nom de la
     * propriété lue dans le résultat.
     *
     * Nécessaire dès que la source est une jointure : `cbMarq` y serait ambigu, il faut
     * écrire `k.cbMarq` dans le WHERE et le ORDER BY, alors que la ligne retournée
     * expose toujours la propriété `cbMarq`. Vide, c'est $srcCursorField qui est utilisé.
     *
     * @var string
     */
    protected $srcCursorSqlField = '';

    /** @var string Colonne portant la clé naturelle reportée dans ref_ext */
    protected $srcKeyField = '';

    /** @var string Filtre SQL additionnel sur la source, sans le mot-clé WHERE */
    protected $srcWhere = '';

    // ── Description de la cible ────────────────────────────────────────────

    /** @var string Table Dolibarr cible, sans préfixe (ex : societe) */
    protected $dstTable = '';

    /** @var string Élément Dolibarr, pour getEntity() (ex : societe) */
    protected $dstElement = '';

    /** @var string Préfixe des ref_ext posés par la reprise */
    public $refExtPrefix = 'SAGE:';

    /**
     * Description de la purge, affichée avant toute suppression.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des enregistrements de '.MAIN_DB_PREFIX.$this->dstTable
            .' marqués « '.$this->refExtPrefix.' »';
    }

    /**
     * Annule ce que la reprise a produit.
     *
     * Comportement par défaut : supprimer, via l'API Dolibarr, les objets créés par le
     * script — ceux qui portent le ref_ext de la reprise. Un script qui n'en crée aucun
     * doit surcharger cette méthode pour défaire son propre travail, faute de quoi il
     * détruirait des enregistrements créés par un autre.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        // Chaque classe métier nomme sa désignation différemment, et leurs delete() n'ont
        // pas la même signature : Societe::delete($id, $user) attend l'identifiant en
        // premier, Contact::delete($user) attend l'utilisateur.
        $targets = array(
            'societe' => array(
                'label'      => 'nom',
                'class'      => 'Societe',
                'file'       => '/societe/class/societe.class.php',
                'delete_arg' => 'id',
            ),
            'socpeople' => array(
                'label'      => "CONCAT_WS(' ', lastname, firstname)",
                'class'      => 'Contact',
                'file'       => '/contact/class/contact.class.php',
                'delete_arg' => 'user',
            ),
            'product' => array(
                'label'      => "CONCAT_WS(' - ', ref, label)",
                'class'      => 'Product',
                'file'       => '/product/class/product.class.php',
                'delete_arg' => 'user',
            ),
            'categorie' => array(
                'label'      => 'label',
                'class'      => 'Categorie',
                'file'       => '/categories/class/categorie.class.php',
                'delete_arg' => 'user',
            ),
        );

        if (!isset($targets[$this->dstTable])) {
            $result['errors'][] = 'Table cible non prise en charge par la purge : '.$this->dstTable;
            return $result;
        }

        $target = $targets[$this->dstTable];
        require_once DOL_DOCUMENT_ROOT.$target['file'];

        // L'entité se calcule sur l'élément Dolibarr, pas sur le nom de table : les deux
        // diffèrent parfois — « category » pour la table `categorie ».
        $sql  = 'SELECT rowid, '.$target['label'].' as nom, ref_ext FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' ORDER BY rowid';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        $this->db->free($resql);

        $result['count'] = count($rows);
        if (!$confirm || empty($rows)) {
            return $result;
        }

        $className = $target['class'];

        foreach ($rows as $row) {
            /** @var CommonObject $object */
            $object = new $className($this->db);
            if ($object->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = $row->ref_ext.' : chargement impossible';
                continue;
            }

            $this->db->begin();
            if ($target['delete_arg'] === 'user') {
                $res = $object->delete($this->user);
            } else {
                $res = $object->delete((int) $row->rowid, $this->user);
            }

            if ($res > 0) {
                $this->db->commit();
                $result['deleted']++;
            } else {
                $this->db->rollback();
                $result['failed']++;
                $result['errors'][] = $row->ref_ext.' : '.$this->objectErrors($object);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 200 === 0)) {
                call_user_func($progress, $result['deleted'] + $result['failed'], $result['count']);
            }
        }

        return $result;
    }

    // ── Options d'exécution ────────────────────────────────────────────────

    /** @var int Nombre d'enregistrements lus par tranche */
    public $batchSize = 200;

    /** @var int Nombre maximum d'enregistrements à traiter (0 = pas de limite) */
    public $limit = 0;

    /** @var bool Simulation : aucune écriture, seuls les compteurs sont alimentés */
    public $dryrun = false;

    /** @var bool Mettre à jour les objets déjà migrés au lieu de les ignorer */
    public $updateExisting = false;

    /** @var int|string|null Curseur de départ : reprend là où un passage s'est arrêté */
    public $startCursor = null;

    /** @var callable|null Rappel de progression, reçoit ($stats, $cursor) après chaque lot */
    public $progressCallback = null;

    /**
     * Date de référence des écritures datées produites par la reprise, 0 pour l'instant
     * du passage.
     *
     * La plupart des scripts n'en ont pas l'usage : les objets qu'ils créent portent la
     * date d'origine lue dans la source. Elle sert aux écritures qui n'en ont aucune —
     * un mouvement de stock d'ouverture, par exemple — et qu'on veut pouvoir caler sur la
     * date de bascule convenue plutôt que sur le moment où le script a tourné.
     *
     * @var int Timestamp
     */
    public $referenceDate = 0;

    /**
     * Condition SQL ajoutée au filtre de lecture du script.
     *
     * Posée par l'option `--filter` du lanceur. Elle ne peut que restreindre la portée de
     * `$srcWhere`, jamais l'élargir : les deux sont combinées par un ET.
     *
     * Sert à reprendre un sous-ensemble comparable d'une instance à l'autre — c'est le seul
     * moyen de confronter une reprise locale à une reprise en ligne sans rejouer l'ensemble —
     * ainsi qu'aux rattrapages ciblés.
     *
     * @var string
     */
    public $extraWhere = '';

    // ── Résultats ──────────────────────────────────────────────────────────

    /**
     * Compteurs du passage.
     *
     * « adopted » distingue les objets qui existaient déjà dans Dolibarr sans venir de la
     * reprise — typiquement remontés par une autre synchronisation — et que le script a
     * rattachés plutôt que recréés.
     *
     * @var array{read:int,created:int,adopted:int,updated:int,skipped:int,error:int}
     */
    public $stats = array('read' => 0, 'created' => 0, 'adopted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0);

    /** @var array<int,array{key:string,message:string}> Détail des lignes en échec */
    public $errors = array();

    /**
     * Nombre maximum d'erreurs conservées en mémoire.
     *
     * Le compteur $stats['error'] reste exact : seule la liste détaillée est bornée.
     * Sans cela, une reprise lancée dans le désordre (contacts avant tiers) accumulerait
     * une entrée par ligne, soit plus de 150 000 objets pour rien.
     *
     * @var int
     */
    public $maxStoredErrors = 1000;

    /** @var int|string|null Dernier curseur atteint, à réutiliser pour reprendre */
    public $lastCursor = null;

    /** @var array<string,int> Index ref_ext -> rowid des objets déjà migrés */
    protected $migratedIndex = array();

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur au nom duquel les objets sont créés
     */
    /**
     * Sentinelle : distingue « constante absente » de « constante posée à vide ».
     *
     * Une chaîne vide est une valeur SIGNIFIANTE — elle veut dire « la source est dans la
     * base de Dolibarr ». Sans sentinelle, impossible de la différencier d'un réglage jamais
     * fait, et l'hébergement mono-base deviendrait inatteignable.
     */
    const SOURCE_DB_UNSET = "\0unset";

    /**
     * Tables présentes dans chaque base source, relevées une fois pour toutes.
     *
     * Statique à dessein : la page de configuration instancie les seize scripts, qui partagent
     * la même base source. L'inventaire est le même pour tous.
     *
     * @var array<string,array<string,bool>>
     */
    protected static $sourceTablesCache = array();

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur au nom duquel les objets sont créés
     */
    public function __construct($db, $user)
    {
        $this->db   = $db;
        $this->user = $user;

        $this->resolveSourceDb();
    }

    /**
     * Arrête la base où lire la source, sans qu'il faille toucher au code.
     *
     * ------------------------------------------------------------------------------
     * TROIS ENVIRONNEMENTS, UNE SEULE RÈGLE
     * ------------------------------------------------------------------------------
     *
     * Les scripts déclarent `aeroprod`, la base à part où l'export de l'éditeur a été chargé
     * en développement. Cette valeur est fausse ailleurs, et le rester silencieusement est
     * précisément ce qui a rendu huit scripts inopérants sans que rien ne le dise.
     *
     * Trois cas, dans cet ordre de priorité :
     *
     * 1. `--source-db=NOM` en ligne de commande — surcharge ponctuelle, appliquée par
     *    migrate.php après l'instanciation ;
     * 2. la constante **`AEROMIG_SOURCE_DB`**, réglable depuis la page de configuration —
     *    c'est elle qui vaut pour l'exploitation courante ;
     * 3. à défaut, la valeur déclarée par le script.
     *
     * **Sur un hébergement qui n'autorise qu'une base — Plesk, où chaque base a son propre
     * phpMyAdmin —, les tables de l'ancien ERP cohabitent avec les `llx_*`.** Aucune ne porte
     * ce préfixe, la cohabitation est donc sans risque, mais elles ne doivent alors plus être
     * qualifiées. Deux façons de le dire : poser la constante à vide, ou lui donner le nom de
     * la base de Dolibarr — les deux reviennent au même, et la seconde est la plus lisible
     * pour qui relit la configuration six mois plus tard.
     *
     * @return void
     */
    protected function resolveSourceDb()
    {
        $configured = getDolGlobalString('AEROMIG_SOURCE_DB', self::SOURCE_DB_UNSET);
        if ($configured !== self::SOURCE_DB_UNSET) {
            $this->sourceDb = trim($configured);
        }

        // Qualifier une table de la base courante ne sert à rien et casse dès que la base est
        // renommée : on ramène ce cas au comportement « pas de préfixe ».
        if ($this->sourceDb !== '' && $this->sourceDb === $this->db->database_name) {
            $this->sourceDb = '';
        }
    }

    /**
     * Vérifie que la table source est réellement atteignable.
     *
     * Sans ce contrôle, une base source mal réglée ne produit qu'un comptage négatif et un
     * parcours vide : le script annonce « 0 enregistrement » et s'arrête, ce qui se confond
     * avec une reprise déjà faite. Le diagnostic doit être explicite, et dire quoi corriger.
     *
     * @return string Message d'anomalie, chaîne vide si la source répond
     */
    public function sourceError()
    {
        if ($this->srcTable === '') {
            return '';
        }

        // La table principale, même quand $srcTable porte une jointure.
        $table = preg_split('/\s/', trim($this->srcTable));
        $table = $table[0];

        // L'inventaire des tables est relu une fois par base, puis partagé par toutes les
        // instances : la page de configuration en crée seize, et seize allers-retours pour
        // poser seize fois la même question se paient sur un serveur distant.
        if (!isset(self::$sourceTablesCache[$this->sourceDb])) {
            $known = array();
            $sql   = 'SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = '
                .($this->sourceDb !== '' ? "'".$this->db->escape($this->sourceDb)."'" : 'DATABASE()');
            $resql = $this->db->query($sql, 1);
            while ($resql && ($obj = $this->db->fetch_object($resql))) {
                $known[strtolower($obj->tn)] = true;
            }
            self::$sourceTablesCache[$this->sourceDb] = $known;
        }

        if (isset(self::$sourceTablesCache[$this->sourceDb][strtolower($table)])) {
            return '';
        }

        // La table n'a pas été vue : une lecture réelle dira si elle est absente ou seulement
        // interdite — information_schema tait ce que l'utilisateur n'a pas le droit de voir.
        $resql = $this->db->query('SELECT 1 FROM '.$this->src($this->srcTable).' LIMIT 1', 1);
        if ($resql) {
            $this->db->free($resql);
            return '';
        }

        $current = $this->db->database_name;
        $errno   = (int) $this->db->lasterrno();

        // 1044 et 1142 : la base ou la table existe, mais l'utilisateur n'a pas le droit de la
        // lire. C'est le cas courant d'un hébergement où chaque base a son propre compte —
        // Plesk en tête. Le module n'ouvre qu'une connexion, celle de Dolibarr : il n'y a pas
        // d'identifiants à renseigner, c'est ce compte-là qui doit voir les deux bases.
        if ($errno === 1044 || $errno === 1142) {
            return 'Accès refusé à la base « '.($this->sourceDb !== '' ? $this->sourceDb : $current).' ».'
                .' Le module lit la source par la connexion de Dolibarr : accordez à son'
                .' utilisateur MySQL le droit SELECT sur cette base — sous Plesk, en l\'ajoutant'
                .' comme utilisateur de la base dans « Bases de données ». Il n\'y a pas de second'
                .' identifiant à renseigner.';
        }

        if ($this->sourceDb !== '') {
            return 'Source introuvable dans la base « '.$this->sourceDb.' ».'
                .' Si les tables de l\'ancien ERP cohabitent avec celles de Dolibarr, posez la'
                .' constante AEROMIG_SOURCE_DB sur « '.$current.' » depuis la configuration du'
                .' module, ou lancez avec « --source-db= ».';
        }

        return 'Source introuvable dans la base « '.$current.' », celle de Dolibarr.'
            .' Si les tables de l\'ancien ERP sont dans une base séparée, renseignez la'
            .' constante AEROMIG_SOURCE_DB depuis la configuration du module, ou lancez avec'
            .' « --source-db=NOM ».';
    }

    /**
     * Crée ou met à jour l'objet Dolibarr correspondant à une ligne source.
     *
     * L'implémentation ne gère ni la transaction, ni les compteurs, ni le ref_ext :
     * le socle s'en charge. Elle se contente de construire l'objet et de le persister.
     * Toute erreur doit être signalée en levant une exception.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de l'objet déjà migré, 0 si création
     * @return array{action:string,id:int}  action parmi created, updated, skipped
     * @throws Exception En cas d'échec de création ou de mise à jour
     */
    abstract protected function migrateRow($row, $existingId);

    /**
     * Préparation appelée une fois avant le parcours.
     *
     * Point d'extension pour charger les référentiels dont le mapping a besoin (pays,
     * départements, dictionnaires…), une seule fois plutôt qu'à chaque ligne.
     *
     * @return int 1 si OK, <=0 pour interrompre la reprise
     */
    protected function prepare()
    {
        return 1;
    }

    /**
     * Contrôle une ligne source sans rien écrire, en mode simulation.
     *
     * Permet à un --dry-run de valider réellement le mapping (résolution des
     * référentiels, champs obligatoires, valeurs hors nomenclature) au lieu de se
     * limiter à compter les lignes. Toute anomalie doit être signalée en levant une
     * exception, exactement comme dans migrateRow().
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de construire un objet valide
     */
    protected function validateRow($row)
    {
    }

    /**
     * Action qu'aurait produite cette ligne, en mode simulation.
     *
     * Le socle ne distingue que création et mise à jour. Une classe fille capable de
     * rattacher un objet préexistant surcharge cette méthode pour que le --dry-run
     * annonce la bonne ventilation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de l'objet déjà migré, 0 sinon
     * @return string              Clé de $stats à incrémenter
     */
    protected function previewAction($row, $existingId)
    {
        return ($existingId > 0) ? 'updated' : 'created';
    }

    /**
     * Informations complémentaires à afficher en fin de passage.
     *
     * Permet à une classe fille de remonter ce que les compteurs ne disent pas : valeurs
     * source non reconnues, cas limites rencontrés, etc.
     *
     * @return array<int,string> Lignes de rapport, vide si rien à signaler
     */
    public function getReport()
    {
        return array();
    }

    /**
     * Clé naturelle de la ligne source, reportée dans ref_ext.
     *
     * @param stdClass $row Ligne source
     * @return string       Clé, chaîne vide si la ligne est inexploitable
     */
    protected function getSourceKey($row)
    {
        $field = $this->srcKeyField;

        return isset($row->$field) ? trim((string) $row->$field) : '';
    }

    /**
     * Construit la valeur de ref_ext à partir d'une clé source.
     *
     * @param string $key Clé naturelle
     * @return string     Valeur de ref_ext
     */
    public function buildRefExt($key)
    {
        return $this->refExtPrefix.$key;
    }

    /**
     * Clause SQL de reprise après un curseur.
     *
     * Partagée par la lecture et le décompte, pour qu'ils portent toujours sur le même
     * ensemble : sans cela, une reprise au curseur rapporterait sa progression à la
     * source entière et afficherait un pourcentage dénué de sens.
     *
     * @param int|string|null $cursor Dernière valeur de curseur déjà traitée
     * @return string                 Condition SQL, chaîne vide s'il n'y a pas de reprise
     */
    protected function buildCursorCondition($cursor)
    {
        // Pas de clause au premier passage : une comparaison à '' écarterait
        // silencieusement une éventuelle clé vide.
        if ($cursor === null || $cursor === '') {
            return '';
        }

        $field = ($this->srcCursorSqlField !== '') ? $this->srcCursorSqlField : $this->srcCursorField;

        if ($this->srcCursorType === 'string') {
            return $field." > '".$this->db->escape($cursor)."'";
        }

        return $field.' > '.((int) $cursor);
    }

    /**
     * Qualifie une table source de la base où elle se trouve.
     *
     * À employer partout où un script lit la source, y compris dans les requêtes qu'il
     * écrit lui-même pour ses index : sans cela, une partie des lectures irait chercher
     * dans la base de Dolibarr des tables qui n'y sont plus.
     *
     * @param  string $table Nom de la table source
     * @return string        Nom qualifié, prêt à entrer dans un FROM
     */
    protected function src($table)
    {
        if ($this->sourceDb === '') {
            return $table;
        }
        return '`'.$this->sourceDb.'`.'.$table;
    }

    /**
     * Nombre d'enregistrements que ce passage a devant lui.
     *
     * Tient compte du curseur de départ : sur une reprise, ce qui le précède a déjà été
     * traité et n'a pas à entrer dans le décompte. La limite éventuelle, elle, reste du
     * ressort de l'appelant.
     *
     * @return int Nombre de lignes, -1 en cas d'erreur SQL
     */
    public function countSource()
    {
        $conditions = array();
        if ($this->srcWhere !== '') {
            $conditions[] = '('.$this->srcWhere.')';
        }
        if ($this->extraWhere !== '') {
            $conditions[] = '('.$this->extraWhere.')';
        }
        $cursorCondition = $this->buildCursorCondition($this->startCursor);
        if ($cursorCondition !== '') {
            $conditions[] = $cursorCondition;
        }

        $sql = 'SELECT COUNT(*) as nb FROM '.$this->src($this->srcTable);
        if ($conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Nombre d'objets que ce script a déjà repris.
     *
     * Sert à afficher l'état d'avancement sans rien lancer. Volontairement distincte de
     * loadMigratedIndex(), qui charge l'index complet en mémoire : sur les huit scripts
     * réunis, cela représenterait plus de 350 000 entrées pour afficher huit nombres.
     *
     * Les scripts qui ne se repèrent pas par ref_ext — la table cible n'en ayant pas
     * toujours — surchargent cette méthode.
     *
     * @return int Nombre d'objets repris, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Charge en mémoire l'index des objets déjà migrés (ref_ext -> rowid).
     *
     * Une seule requête au lieu d'un fetch() par ligne : sur plus de 150 000 tiers,
     * c'est la différence entre quelques secondes et plusieurs heures. L'empreinte
     * mémoire reste modeste (de l'ordre de 20 Mo pour 150 000 entrées) ; pour une
     * source beaucoup plus volumineuse, il faudra passer à une vérification par
     * tranche.
     *
     * @return int Nombre d'entrées chargées, -1 en cas d'erreur SQL
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        $sql = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= " WHERE entity IN (".getEntity($this->dstElement).")";
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->migratedIndex[$obj->ref_ext] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return count($this->migratedIndex);
    }

    /**
     * Lit une tranche de la source à partir d'un curseur.
     *
     * @param int|string|null $cursor Dernière valeur de curseur déjà traitée, null au départ
     * @return array<int,stdClass>|null Lignes lues, null en cas d'erreur SQL
     */
    protected function fetchBatch($cursor)
    {
        $cursorSql = ($this->srcCursorSqlField !== '') ? $this->srcCursorSqlField : $this->srcCursorField;

        $conditions = array();
        if ($this->srcWhere !== '') {
            $conditions[] = '('.$this->srcWhere.')';
        }
        if ($this->extraWhere !== '') {
            $conditions[] = '('.$this->extraWhere.')';
        }
        $cursorCondition = $this->buildCursorCondition($cursor);
        if ($cursorCondition !== '') {
            $conditions[] = $cursorCondition;
        }

        $sql  = 'SELECT '.$this->srcFields.' FROM '.$this->src($this->srcTable);
        if ($conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY '.$cursorSql.' ASC';
        $sql .= ' LIMIT '.((int) $this->batchSize);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return null;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Exécute la reprise.
     *
     * @return int 1 si tout s'est bien passé, -1 si au moins une ligne est en erreur
     */
    public function run()
    {
        $this->stats  = array('read' => 0, 'created' => 0, 'adopted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0);
        $this->errors = array();

        // Avant tout le reste : une source injoignable ne doit pas se traduire par un
        // parcours vide, qui se confondrait avec une reprise déjà faite.
        $sourceError = $this->sourceError();
        if ($sourceError !== '') {
            $this->errors[] = array('key' => '', 'message' => $sourceError);
            return -1;
        }

        if ($this->prepare() <= 0) {
            return -1;
        }

        if ($this->loadMigratedIndex() < 0) {
            return -1;
        }

        $cursor    = $this->startCursor;
        $processed = 0;
        $stop      = false;

        while (!$stop) {
            $rows = $this->fetchBatch($cursor);
            if ($rows === null) {
                return -1;
            }
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $cursorField = $this->srcCursorField;
                $cursor      = $row->$cursorField;

                $this->stats['read']++;
                $this->processRow($row);
                $processed++;

                if ($this->limit > 0 && $processed >= $this->limit) {
                    $stop = true;
                    break;
                }
            }

            $this->lastCursor = $cursor;

            if (is_callable($this->progressCallback)) {
                call_user_func($this->progressCallback, $this->stats, $cursor);
            }

            unset($rows);
        }

        $this->lastCursor = $cursor;

        return ($this->stats['error'] > 0) ? -1 : 1;
    }

    /**
     * Traite une ligne source : décide de l'action, encadre l'écriture et compte.
     *
     * Chaque ligne est écrite dans sa propre transaction. Une ligne en échec est
     * annulée puis enregistrée, et le parcours continue : sur une reprise de masse, on
     * veut la liste complète des cas à corriger, pas un arrêt à la première anomalie.
     *
     * @param stdClass $row Ligne source
     * @return void
     */
    protected function processRow($row)
    {
        $key = $this->getSourceKey($row);
        if ($key === '') {
            $this->stats['error']++;
            $this->addError('(vide)', 'Clé source absente ou vide');
            return;
        }

        $refExt     = $this->buildRefExt($key);
        $existingId = isset($this->migratedIndex[$refExt]) ? $this->migratedIndex[$refExt] : 0;

        // Déjà migré et pas de mise à jour demandée : on passe.
        if ($existingId > 0 && !$this->updateExisting) {
            $this->stats['skipped']++;
            return;
        }

        // Simulation : le mapping est réellement exécuté, mais rien n'est persisté. Une
        // ligne qui échouerait à l'écriture est ainsi détectée avant de toucher la base.
        if ($this->dryrun) {
            try {
                $this->validateRow($row);
            } catch (Exception $e) {
                $this->stats['error']++;
                $this->addError($key, $e->getMessage());
                return;
            }

            $this->stats[$this->previewAction($row, $existingId)]++;
            return;
        }

        $this->db->begin();

        try {
            $result = $this->migrateRow($row, $existingId);

            $action = isset($result['action']) ? $result['action'] : 'skipped';
            $id     = isset($result['id']) ? (int) $result['id'] : 0;

            if ($action === 'created' && $id > 0) {
                // L'index est tenu à jour en cours de route : si la source contient deux
                // fois la même clé, le doublon est détecté dès le second passage.
                $this->migratedIndex[$refExt] = $id;
            }

            if (!isset($this->stats[$action])) {
                throw new Exception('Action inconnue retournée par migrateRow() : '.$action);
            }

            $this->stats[$action]++;
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            $this->stats['error']++;
            $this->addError($key, $e->getMessage());
        }
    }

    /**
     * Table Dolibarr alimentée par ce script, sans préfixe.
     *
     * Exposée pour que les outils transverses (purge) sachent où chercher les
     * enregistrements marqués par la reprise.
     *
     * @return string
     */
    public function getDstTable()
    {
        return $this->dstTable;
    }

    /**
     * Normalise un libellé pour un rapprochement : minuscules, sans accent, ponctuation
     * et séparateurs ramenés à des espaces simples.
     *
     * C'est ce qui permet de traiter « Pays-Bas », « Pays bas » et « PAYS BAS » comme
     * une seule et même valeur.
     *
     * @param string $label Libellé brut
     * @return string       Libellé normalisé
     */
    protected function normalizeLabel($label)
    {
        $label = dol_string_unaccent((string) $label);
        $label = strtolower($label);
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label);

        return trim($label);
    }

    /**
     * Met un numéro de téléphone au format international lorsqu'un préfixe pays est
     * renseigné dans la source.
     *
     * Le zéro de tête d'un numéro national disparaît en notation internationale :
     * « 0671834495 » avec le préfixe « 33 » devient « +33 671834495 ». Sans préfixe, le
     * numéro est repris tel quel.
     *
     * Dolibarr retire de lui-même les espaces et les points à l'enregistrement.
     *
     * @param string $number Numéro brut
     * @param string $prefix Préfixe pays brut (« 33 », « +33 », « 852 »…)
     * @return string        Numéro mis en forme, chaîne vide si la source est vide
     */
    protected function formatPhone($number, $prefix)
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }

        // Le préfixe est saisi tantôt « 33 », tantôt « +33 » : on ne garde que les
        // chiffres avant de reconstruire la notation.
        $prefix = preg_replace('/[^0-9]/', '', (string) $prefix);
        if ($prefix === '') {
            return $number;
        }

        if (substr($number, 0, 1) === '0') {
            $number = ltrim(substr($number, 1));
        }

        return '+'.$prefix.' '.$number;
    }

    /**
     * Enregistre une ligne en échec, dans la limite de $maxStoredErrors.
     *
     * @param string $key     Clé source
     * @param string $message Message d'erreur
     * @return void
     */
    protected function addError($key, $message)
    {
        if (count($this->errors) < $this->maxStoredErrors) {
            $this->errors[] = array('key' => $key, 'message' => $message);
        }
    }

    /**
     * Message d'erreur exploitable à partir d'un objet Dolibarr en échec.
     *
     * @param CommonObject $object Objet dont l'opération a échoué
     * @return string              Erreurs concaténées
     */
    protected function objectErrors($object)
    {
        $messages = array();
        if (!empty($object->error)) {
            $messages[] = $object->error;
        }
        if (!empty($object->errors)) {
            $messages = array_merge($messages, $object->errors);
        }

        return $messages ? implode(' | ', $messages) : 'Erreur inconnue';
    }
}
