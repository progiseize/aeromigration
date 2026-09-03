<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationpackaging.class.php
 * \ingroup aeromigration
 * \brief   Conditionnement d'achat : f_artfourniss.AF_Colisage -> extrafields aerotoolbox.
 *
 * PRÉREQUIS : la reprise des tarifs fournisseurs (« supplierprice ») doit avoir été passée —
 * chaque ligne est retrouvée par l'`import_key` qu'elle y a reçu — et le module aerotoolbox
 * doit être en 1.21.0 ou plus, réactivé : c'est lui qui déclare les trois extrafields.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI UN SCRIPT À PART, ET PAS « SUPPLIERPRICE »
 * ------------------------------------------------------------------------------
 *
 * Depuis aerotoolbox 1.21.0, le conditionnement d'achat vit dans trois extrafields de la
 * ligne de tarif — contenant (`aerotb_pack_unit`), quantité (`aerotb_pack_qty`), caractère
 * imposé (`aerotb_pack_forced`) — et le champ natif `packaging` en est DÉRIVÉ : rempli, il
 * signifie « conditionnement imposé » et fait arrondir les quantités des commandes
 * fournisseur par le coeur (PRODUCT_USE_SUPPLIER_PACKAGING est active).
 *
 * Or ADD ne distingue pas l'imposé de l'informatif : `AF_Colisage` est un nombre, sans
 * rien qui dise s'il contraint. Décision client : REPRISE EN INFORMATIF — un
 * conditionnement indicatif posé à tort n'a aucune conséquence, un imposé à tort bloque
 * des commandes et devient palier tarifaire. Le caractère se durcira fiche par fiche,
 * dans l'écran des prix d'achat.
 *
 * D'où ce script : il pose les trois extrafields (`forced` = non) et laisse le champ natif
 * VIDE. Mieux — il le VIDE s'il le trouve rempli : les bases migrées avant la 0.30.0
 * (`supplierprice` y recopiait AF_QteMini dans `packaging`) portent des conditionnements
 * devenus « imposés » à leur insu le jour où aerotoolbox 1.21.0 a activé la constante.
 *
 * ## Ce que dit la source
 *
 * - `AF_Colisage` et `AF_QteMini` sont rigoureusement identiques sur toute la table (F6
 *   dans ANOMALIES.md) : une seule est lue. 269 lignes portent une valeur supérieure à 1,
 *   sur 268 articles et 34 fournisseurs — par 10, 6, 12, 4, 3, jusqu'à 288.
 * - Le contenant vient de l'unité de vente de l'ARTICLE (`AR_UniteVen`) : l'unité 6 est
 *   « Pack » ; tout le reste — dont l'unité 1, « cL » détourné en colis par l'usage
 *   (1 871 articles), et l'absence d'unité — donne « colis », le vocabulaire de la maison.
 *
 * ## Idempotence : la comparaison des valeurs
 *
 * La ligne cible est retrouvée par `import_key` (« SAGE:cbMarq », posé par
 * `supplierprice ») ; mais c'est la comparaison des trois extrafields et du champ natif
 * qui décide d'écrire. Une ligne déjà conforme n'est pas touchée : un second passage
 * n'écrit rien.
 *
 * ## Le second lot, non repris ici
 *
 * 1 871 articles sont marqués « vendu par colis » dans ADD sans quantité chiffrée. Un
 * contenant sans nombre est purement descriptif : sa reprise attend la décision du client,
 * et ferait l'objet d'une extension de ce script.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/productfournisseurprice.class.php';

class MigrationPackaging extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'packaging';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptPackaging';

    /** @var string Les tables Sage ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_artfourniss';

    /** @var string Colonnes lues */
    protected $srcFields = 'cbMarq, AR_Ref, CT_Num, AF_Colisage';

    /** @var string Colonne de parcours : la clé primaire de f_artfourniss */
    protected $srcCursorField = 'cbMarq';

    /** @var string Clé naturelle de la ligne tarifaire — la même que « supplierprice » */
    protected $srcKeyField = 'cbMarq';

    /**
     * Seules les lignes conditionnées sont parcourues.
     *
     * `AF_Colisage <= 1` signifie « à l'unité » : rien à poser, et le parcours passe de
     * 16 182 lignes à 269. Les clés vides sont écartées comme dans « supplierprice ».
     *
     * @var string
     */
    protected $srcWhere = "AF_Colisage > 1 AND TRIM(COALESCE(AR_Ref, '')) <> '' AND TRIM(COALESCE(CT_Num, '')) <> ''";

    /** @var string Table Dolibarr cible — la ligne porteuse des extrafields */
    protected $dstTable = 'product_fournisseur_price';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'productsupplierprice';

    /** @var array<string,int> AR_UniteVen par référence article (unités 6 = pack) */
    protected $unitByRef = array();

    /** @var array<int,array{unit:?string,qty:int,forced:int,native:float}> État actuel par rowid de ligne */
    protected $current = array();

    /** @var int Lignes source sans ligne de tarif reprise */
    protected $missing = 0;

    /** @var int Lignes déjà conformes */
    protected $alreadyRight = 0;

    /** @var int Champs natifs `packaging` vidés (posés par une reprise antérieure à la 0.30.0) */
    protected $nativeCleared = 0;

    /** @var array<string,int> Écritures par contenant */
    protected $byUnit = array();

    /** @var int Colisages non entiers, arrondis */
    protected $nonInteger = 0;

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur exécutant la reprise
     */
    public function __construct($db, $user)
    {
        parent::__construct($db, $user);

        // Ce script ne crée rien : il complète des lignes déjà reprises, que le socle
        // considérerait sinon comme « déjà migrées » et passerait.
        $this->updateExisting = true;
    }

    /**
     * Charge l'unité de vente des articles et l'état actuel des lignes reprises.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        // ── Garde : les extrafields d'aerotoolbox 1.21.0 doivent exister ───────
        // La requête est muette (le second argument coupe le log) : son échec est un état
        // attendu — module pas encore réactivé — et se traduit par un message clair plutôt
        // qu'une erreur SQL brute.
        $sql   = 'SELECT aerotb_pack_unit, aerotb_pack_qty, aerotb_pack_forced FROM '
            .MAIN_DB_PREFIX.'product_fournisseur_price_extrafields LIMIT 1';
        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Extrafields de conditionnement absents : réactivez le module aerotoolbox (1.21.0 ou plus)',
            );
            return -1;
        }
        $this->db->free($resql);

        // ── Unité de vente des articles conditionnés ───────────────────────────
        // Seule l'unité 6 (« Pack ») change le contenant : inutile de charger les 16 000
        // articles, la sous-requête restreint aux conditionnés.
        $this->unitByRef = array();
        $sql  = 'SELECT TRIM(a.AR_Ref) as ref, a.AR_UniteVen as unit FROM '.$this->src('f_article').' as a';
        $sql .= ' INNER JOIN (SELECT DISTINCT TRIM(AR_Ref) as ref FROM '.$this->src($this->srcTable);
        $sql .= '   WHERE AF_Colisage > 1) as c ON c.ref = TRIM(a.AR_Ref)';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->unitByRef[$obj->ref] = (int) $obj->unit;
        }
        $this->db->free($resql);

        // ── État actuel des lignes reprises : extrafields ET champ natif ───────
        // Le natif est relu ici pour deux raisons : décider de le vider (bases migrées
        // avant la 0.30.0), et le compter dans la comparaison d'idempotence — une ligne
        // n'est conforme que si les trois extrafields sont bons ET le natif vide.
        $this->current = array();
        $sql  = 'SELECT p.rowid, p.packaging, e.aerotb_pack_unit as unit, e.aerotb_pack_qty as qty,';
        $sql .= ' e.aerotb_pack_forced as forced';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price as p';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields as e ON e.fk_object = p.rowid';
        $sql .= ' WHERE p.entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND p.import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->current[(int) $obj->rowid] = array(
                'unit'   => $obj->unit,
                'qty'    => (int) $obj->qty,
                'forced' => (int) $obj->forced,
                'native' => (float) $obj->packaging,
            );
        }
        $this->db->free($resql);

        if (empty($this->current)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Aucune ligne de tarif reprise en base : lancez d\'abord « migrate.php supplierprice »',
            );
            return -1;
        }

        return 1;
    }

    /**
     * L'index des lignes reprises se tient par `import_key`, comme « supplierprice ».
     *
     * `llx_product_fournisseur_price` n'a pas de colonne `ref_ext` : le mécanisme par
     * défaut du socle est inapplicable, `import_key` porte la clé (« SAGE:cbMarq »).
     *
     * @return int Nombre d'entrées chargées, -1 en cas d'erreur SQL
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        $sql  = 'SELECT rowid, import_key FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->migratedIndex[$obj->import_key] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return count($this->migratedIndex);
    }

    /**
     * Nombre de lignes reprises portant déjà leur conditionnement.
     *
     * @return int Nombre de lignes, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable.' as p';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields as e ON e.fk_object = p.rowid';
        $sql .= ' WHERE p.entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND p.import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' AND e.aerotb_pack_qty > 1';

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->nb : 0;
    }

    /**
     * Le conditionnement cible d'une ligne source.
     *
     * @param stdClass $row Ligne de f_artfourniss
     * @return array{unit:string,qty:int}
     */
    protected function computeTarget($row)
    {
        $qty = (float) $row->AF_Colisage;
        if ($qty != (float) (int) round($qty)) {
            $this->nonInteger++;
        }

        $unit = (isset($this->unitByRef[trim((string) $row->AR_Ref)])
            && $this->unitByRef[trim((string) $row->AR_Ref)] === 6) ? 'pack' : 'colis';

        return array('unit' => $unit, 'qty' => (int) round($qty));
    }

    /**
     * Une ligne est conforme si les trois extrafields sont posés et le natif inoffensif.
     *
     * Le seuil du natif est 1, pas 0 : quand PRODUCT_USE_SUPPLIER_PACKAGING est active,
     * `update_buyprice()` écrit d'office `packaging = 1` sur toute ligne enregistrée
     * (fournisseur.product.class.php, « empty($packaging) ? 1 : $packaging »). Un multiple
     * de 1 ne contraint rien : le pourchasser ferait réécrire les 16 000 lignes pour un
     * effet nul. Seul un natif SUPÉRIEUR à 1 impose un conditionnement — c'est lui
     * l'anomalie à vider.
     *
     * @param array{unit:?string,qty:int,forced:int,native:float} $current État en base
     * @param array{unit:string,qty:int}                          $target  Conditionnement cible
     * @return bool
     */
    protected function isAligned($current, $target)
    {
        return $current['qty'] === $target['qty']
            && $current['unit'] === $target['unit']
            && $current['forced'] === 0
            && $current['native'] <= 1;
    }

    /**
     * Pose le conditionnement informatif sur la ligne de tarif reprise.
     *
     * @param stdClass $row        Ligne de f_artfourniss
     * @param int      $existingId rowid de la ligne de tarif, 0 si elle est absente
     * @return array{action:string,id:int}
     * @throws Exception Si l'écriture échoue
     */
    protected function migrateRow($row, $existingId)
    {
        if ($existingId <= 0) {
            // Ligne écartée par « supplierprice » (article non repris, tiers inconnu…) :
            // le conditionnement n'a nulle part où se poser, le signaler suffit.
            $this->missing++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $target  = $this->computeTarget($row);
        $current = isset($this->current[$existingId]) ? $this->current[$existingId]
            : array('unit' => null, 'qty' => 0, 'forced' => 0, 'native' => 0.0);

        if ($this->isAligned($current, $target)) {
            $this->alreadyRight++;
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // fetchCommon() charge aussi les extrafields existants : insertExtraFields()
        // réécrivant la ligne entière, un array_options partiel effacerait les autres
        // champs — la désignation fournisseur d'aerotoolbox notamment.
        $line = new ProductFournisseurPrice($this->db);
        if ($line->fetch($existingId) <= 0) {
            throw new Exception('Ligne tarifaire introuvable (rowid '.$existingId.') : '.$this->objectErrors($line));
        }

        $line->array_options['options_aerotb_pack_unit']   = $target['unit'];
        $line->array_options['options_aerotb_pack_qty']    = $target['qty'];
        $line->array_options['options_aerotb_pack_forced'] = 0;

        // Un natif supérieur à 1 vient d'une reprise antérieure à la 0.30.0 : depuis
        // aerotoolbox 1.21.0 il signifie « imposé », à rebours de la décision client — vidé.
        // Le « 1 » que le coeur pose d'office est inoffensif et laissé (voir isAligned()).
        if ($current['native'] > 1) {
            $line->packaging = null;
            $this->nativeCleared++;
        }

        if ($line->update($this->user) <= 0) {
            throw new Exception('Échec de l\'écriture du conditionnement (rowid '.$existingId.') : '.$this->objectErrors($line));
        }

        $this->current[$existingId] = array(
            'unit'   => $target['unit'],
            'qty'    => $target['qty'],
            'forced' => 0,
            'native' => 0.0,
        );

        if (!isset($this->byUnit[$target['unit']])) {
            $this->byUnit[$target['unit']] = 0;
        }
        $this->byUnit[$target['unit']]++;

        return array('action' => 'updated', 'id' => $existingId);
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     */
    protected function validateRow($row)
    {
        // Rien à invalider : le filtre source garantit la quantité, et le contenant a un
        // défaut. La méthode est là pour le contrat du socle.
    }

    /**
     * Verdict d'une ligne en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la ligne de tarif, 0 si elle est absente
     * @return string Action que le passage réel produirait
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId <= 0) {
            $this->missing++;
            return 'skipped';
        }

        $target  = $this->computeTarget($row);
        $current = isset($this->current[$existingId]) ? $this->current[$existingId]
            : array('unit' => null, 'qty' => 0, 'forced' => 0, 'native' => 0.0);

        if ($this->isAligned($current, $target)) {
            $this->alreadyRight++;
            return 'skipped';
        }

        if ($current['native'] > 1) {
            $this->nativeCleared++;
        }
        if (!isset($this->byUnit[$target['unit']])) {
            $this->byUnit[$target['unit']] = 0;
        }
        $this->byUnit[$target['unit']]++;

        return 'updated';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Effacement du conditionnement (extrafields aerotb_pack_*) des lignes de tarif reprises'
            .' ('.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields)';
    }

    /**
     * Efface le conditionnement des lignes reprises.
     *
     * Le comportement par défaut du socle — supprimer les objets créés — est inapplicable :
     * ce script ne crée aucune ligne, il complète celles de « supplierprice ». Défaire son
     * travail, c'est remettre les trois extrafields à néant. Le champ natif n'est pas
     * restauré : sa valeur d'avant la 0.30.0 était précisément l'anomalie.
     *
     * @param bool          $confirm  false pour dénombrer sans rien écrire
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.$this->dstTable.' as p';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields as e ON e.fk_object = p.rowid';
        $sql .= ' WHERE p.entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND p.import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' AND (e.aerotb_pack_qty IS NOT NULL OR e.aerotb_pack_unit IS NOT NULL OR e.aerotb_pack_forced IS NOT NULL)';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }
        $targets = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $targets[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        $result['count'] = count($targets);
        if (!$confirm || empty($targets)) {
            return $result;
        }

        foreach ($targets as $rowid) {
            $line = new ProductFournisseurPrice($this->db);
            if ($line->fetch($rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : ligne introuvable';
                continue;
            }

            $line->array_options['options_aerotb_pack_unit']   = null;
            $line->array_options['options_aerotb_pack_qty']    = null;
            $line->array_options['options_aerotb_pack_forced'] = null;

            if ($line->update($this->user) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : '.$this->objectErrors($line);
                continue;
            }

            $result['deleted']++;
            if (is_callable($progress) && ($result['deleted'] % 100 === 0)) {
                call_user_func($progress, $result['deleted'], $result['count']);
            }
        }

        return $result;
    }

    /**
     * Rapport de fin de passage.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $lines = array();

        if (!empty($this->byUnit)) {
            $lines[] = 'Conditionnements posés, en informatif (« imposé » se décide fiche par fiche) :';
            ksort($this->byUnit);
            foreach ($this->byUnit as $unit => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 8, ' ', STR_PAD_LEFT).'  contenant « '.$unit.' »';
            }
        }

        if ($this->nativeCleared > 0) {
            $lines[] = '  '.str_pad((string) $this->nativeCleared, 8, ' ', STR_PAD_LEFT)
                .'  champ natif « packaging » vidé — posé par une reprise antérieure à la 0.30.0,';
            $lines[] = '            il signifiait « conditionnement imposé » depuis aerotoolbox 1.21.0';
        }

        if ($this->alreadyRight > 0) {
            $lines[] = '  '.str_pad((string) $this->alreadyRight, 8, ' ', STR_PAD_LEFT)
                .'  ligne(s) déjà conforme(s)';
        }

        if ($this->missing > 0) {
            $lines[] = '  '.str_pad((string) $this->missing, 8, ' ', STR_PAD_LEFT)
                .'  ligne(s) source sans ligne de tarif reprise (écartées par « supplierprice »)';
        }

        if ($this->nonInteger > 0) {
            $lines[] = '  '.str_pad((string) $this->nonInteger, 8, ' ', STR_PAD_LEFT)
                .'  colisage(s) non entier(s), arrondi(s)';
        }

        $lines[] = 'Non repris : les articles marqués « vendu par colis » sans quantité chiffrée';
        $lines[] = '            (1 871 dans la source) — contenant sans nombre, purement descriptif,';
        $lines[] = '            en attente de la décision du client.';

        return $lines;
    }
}
