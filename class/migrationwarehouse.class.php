<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationwarehouse.class.php
 * \ingroup aeromigration
 * \brief   Reprise des entrepôts : f_depot et f_emplacements -> objets Entrepot.
 *
 * L'ancien ERP ne connaît qu'un seul dépôt réel — « boutique.aero » — qui porte la
 * totalité du stock, les seuils, les emplacements et les dix-neuf caisses. Le second
 * dépôt déclaré, « Siege boutique.aero », est une coquille : ses 11 826 lignes sont
 * intégralement à zéro. Un troisième dépôt, non déclaré, apparaît sous le numéro 0 avec
 * cinq lignes négatives : il est écarté.
 *
 * Le script crée donc **un entrepôt principal**, puis un **sous-entrepôt par emplacement**
 * réellement utilisé, à plat sous celui-ci. La hiérarchie fine (salle, allée, niveau)
 * reste à construire par le client : les libellés en portent déjà le chemin — « S1-A15-4 »
 * se lit salle 1, allée A15, niveau 4 —, ils n'auront donc jamais à être renommés.
 *
 * Deux particularités commandent la conception :
 *
 * - **Les emplacements n'ont pas de libellé dans la source.** `f_artstock.DP_NoPrincipal`
 *   ne porte qu'un numéro, et la table de correspondance de Sage n'a pas été répliquée.
 *   Les libellés ont été extraits de l'interface de l'ancien ERP et rangés dans
 *   `f_emplacements`, seule table de ce jeu qui ne vienne pas de Sage.
 *
 * - **Dolibarr impose l'unicité du libellé sur toute l'entité**, et non sous le parent :
 *   `uk_entrepot_label (ref, entity)`. Or 70 libellés sont partagés par plusieurs numéros
 *   — huit s'appellent « BOUTIQUE ». Les homonymes sont donc fusionnés, le premier numéro
 *   rencontré l'emportant. Conséquence à connaître : cette contrainte interdit aussi
 *   d'avoir un « RANG-A » sous deux étagères différentes, le jour où la hiérarchie sera
 *   affinée. Les noms devront rester complets.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

class MigrationWarehouse extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'warehouse';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptWarehouse';

    /**
     * Table source : les emplacements, reconstitués à part.
     *
     * Ce n'est pas une table de Sage. Les libellés n'existant nulle part dans les données
     * livrées, ils ont été extraits de l'interface de l'ancien ERP, où seul le code HTML
     * portait les identifiants.
     *
     * @var string
     */
    protected $srcTable = 'f_emplacements';

    /** @var string Colonne de parcours : l'identifiant d'emplacement de l'ancien ERP */
    protected $srcCursorField = 'rowid';

    /** @var string Le curseur est un entier */
    protected $srcCursorType = 'int';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'rowid';

    /**
     * Seuls les emplacements réellement utilisés sont repris.
     *
     * La source en compte 1 006, dont 196 que plus aucun article n'occupe. Les créer
     * reviendrait à peupler les sélecteurs d'entrepôt de deux cents entrées vides. Un
     * emplacement qui reviendrait à l'usage sera créé au passage suivant.
     *
     * @var string
     */
    protected $srcWhere = 'rowid IN (SELECT DISTINCT DP_NoPrincipal FROM f_artstock'
        .' WHERE DE_No = 1 AND DP_NoPrincipal IS NOT NULL AND DP_NoPrincipal > 0)';

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'entrepot';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'stock';

    /** @var int Numéro du dépôt réel dans l'ancien ERP */
    protected $mainDepotNo = 1;

    /** @var string Marqueur de l'entrepôt principal, distinct des numéros d'emplacement */
    protected $mainImportKey = 'DEPOT1';

    /** @var int rowid de l'entrepôt principal, sous lequel tout est rattaché */
    protected $mainWarehouseId = 0;

    /** @var string Libellé de l'entrepôt principal */
    protected $mainWarehouseLabel = '';

    /** @var string Marqueur de l'entrepôt de repli */
    protected $orphanImportKey = 'ORPHELIN';

    /**
     * Libellé de l'entrepôt de repli.
     *
     * Une consigne plutôt qu'un constat : ces articles attendent qu'on les retrouve. Le
     * « À » le fait aussi remonter en tête des sélecteurs d'entrepôt, où il restera visible
     * tant que le rangement n'aura pas été fait.
     *
     * @var string
     */
    protected $orphanWarehouseLabel = 'À localiser';

    /** @var int rowid de l'entrepôt de repli, 0 s'il n'y a rien à y mettre */
    protected $orphanWarehouseId = 0;

    /** @var array<int,int> Numéro d'emplacement supprimé -> nombre d'articles concernés */
    protected $orphanLocations = array();

    /** @var array<string,int> Libellé normalisé -> rowid, pour reconnaître les homonymes */
    protected $warehouseByLabel = array();

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Emplacements fusionnés avec un homonyme */
    protected $mergedTwins = 0;

    /** @var array<int,string> Emplacements sans libellé, dotés d'un nom de repli */
    protected $unnamed = array();

    /** @var int Entrepôt principal créé durant ce passage */
    protected $mainCreated = 0;

    /** @var int 1 si l'entrepôt principal préexistait et a été adopté */
    protected $mainAdopted = 0;

    /** @var int 1 si le marqueur de reprise vient d'être posé sur l'entrepôt adopté */
    protected $mainStamped = 0;

    /** @var string Marqueur d'un autre import trouvé sur l'entrepôt, jamais écrasé */
    protected $mainForeignKey = '';

    /** @var int Emplacements de la source qu'aucun article n'occupe */
    protected $unused = 0;

    /**
     * Prépare l'entrepôt principal et l'index des libellés existants.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        if ($this->loadExistingLabels() < 0) {
            return -1;
        }

        if ($this->prepareMainWarehouse() <= 0) {
            return -1;
        }

        if ($this->prepareOrphanWarehouse() < 0) {
            return -1;
        }

        return $this->countUnused();
    }

    /**
     * Crée l'entrepôt de repli des emplacements supprimés.
     *
     * Huit numéros encore portés par des articles n'existent plus dans l'ancien ERP :
     * quelqu'un les a supprimés sans réaffecter leur contenu. Ce ne sont pas des trous
     * d'extraction — leurs voisins immédiats sont bien présents.
     *
     * Ces articles arriveraient sinon dans l'entrepôt principal, mêlés à ceux qui n'ont
     * simplement jamais été rangés. Les regrouper à part les rend identifiables d'un coup
     * d'oeil, ce qui est tout l'intérêt : leur localisation physique est à retrouver.
     *
     * L'entrepôt n'est créé que s'il y a matière.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepareOrphanWarehouse()
    {
        $sql  = 'SELECT s.DP_NoPrincipal as numero, COUNT(*) as nb FROM f_artstock as s';
        $sql .= ' LEFT JOIN '.$this->srcTable.' as e ON e.rowid = s.DP_NoPrincipal';
        $sql .= ' WHERE s.DE_No = '.((int) $this->mainDepotNo);
        $sql .= ' AND s.DP_NoPrincipal > 0 AND e.rowid IS NULL';
        $sql .= ' GROUP BY s.DP_NoPrincipal ORDER BY s.DP_NoPrincipal';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->orphanLocations[(int) $obj->numero] = (int) $obj->nb;
        }
        $this->db->free($resql);

        if (empty($this->orphanLocations)) {
            return 1;
        }

        $existing = $this->findByImportKey($this->buildRefExt($this->orphanImportKey));
        if ($existing > 0) {
            $this->orphanWarehouseId = $existing;
            return 1;
        }

        if ($this->dryrun) {
            return 1;
        }

        $entrepot = new Entrepot($this->db);
        $this->fillWarehouse($entrepot, $this->orphanWarehouseLabel, $this->mainWarehouseId, $this->orphanImportKey);
        $entrepot->description = 'Articles dont l\'emplacement a été supprimé dans l\'ancien ERP (ADD)'
            ." :\nnuméros ".implode(', ', array_keys($this->orphanLocations)).'.'
            ."\nLeur localisation physique est à retrouver, puis à saisir dans Dolibarr.";

        if ($entrepot->create($this->user) <= 0) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Échec de la création de l\'entrepôt de repli : '.$this->objectErrors($entrepot),
            );
            return -1;
        }

        $this->orphanWarehouseId = (int) $entrepot->id;
        $this->warehouseByLabel[$this->labelKey($this->orphanWarehouseLabel)] = $this->orphanWarehouseId;

        return 1;
    }

    /**
     * Charge les libellés d'entrepôt déjà en base.
     *
     * Sert à reconnaître un homonyme avant de créer : l'unicité porte sur toute l'entité,
     * les trois entrepôts de démonstration comme les emplacements déjà repris entrent donc
     * dans le décompte.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadExistingLabels()
    {
        $sql  = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->warehouseByLabel[$this->labelKey($obj->ref)] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Crée l'entrepôt principal, ou le retrouve s'il existe déjà.
     *
     * Son adresse vient de `f_depot`, de sorte que les documents d'expédition portent le
     * bon lieu d'enlèvement.
     *
     * @return int rowid de l'entrepôt principal, -1 en cas d'erreur
     */
    protected function prepareMainWarehouse()
    {
        $sql  = 'SELECT DE_No, DE_Intitule, DE_Adresse, DE_Complement, DE_CodePostal, DE_Ville,';
        $sql .= ' DE_Pays, DE_Telephone, DE_Telecopie FROM f_depot WHERE DE_No = '.((int) $this->mainDepotNo);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$row) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Dépôt '.$this->mainDepotNo.' introuvable dans f_depot : la source est-elle complète ?',
            );
            return -1;
        }

        $this->mainWarehouseLabel = trim((string) $row->DE_Intitule);
        if ($this->mainWarehouseLabel === '') {
            $this->mainWarehouseLabel = 'Depot '.$this->mainDepotNo;
        }

        // Déjà repris lors d'un passage antérieur ?
        $existing = $this->findByImportKey($this->buildRefExt($this->mainImportKey));
        if ($existing > 0) {
            $this->mainWarehouseId = $existing;
            return $existing;
        }

        // Un entrepôt porte déjà ce nom sans venir de la reprise : on l'adopte plutôt que
        // d'échouer sur l'unicité, et on lui pose son marqueur.
        $key = $this->labelKey($this->mainWarehouseLabel);
        if (isset($this->warehouseByLabel[$key])) {
            $this->mainWarehouseId = $this->warehouseByLabel[$key];
            $this->stampMainWarehouse();
            return $this->mainWarehouseId;
        }

        if ($this->dryrun) {
            // Rien n'est écrit : les sous-entrepôts seront simplement annoncés sans parent.
            return 1;
        }

        $entrepot = new Entrepot($this->db);
        $this->fillWarehouse($entrepot, $this->mainWarehouseLabel, 0, $this->mainImportKey);

        $entrepot->description  = 'Dépôt principal repris de l\'ancien ERP (ADD)';
        $entrepot->lieu         = trim((string) $row->DE_Ville);
        $entrepot->address      = trim((string) $row->DE_Adresse);
        $complement             = trim((string) $row->DE_Complement);
        if ($complement !== '') {
            $entrepot->address .= "\n".$complement;
        }
        $entrepot->zip          = trim((string) $row->DE_CodePostal);
        $entrepot->town         = trim((string) $row->DE_Ville);
        $entrepot->country_code = $this->resolveCountryCode($row->DE_Pays);
        $entrepot->phone        = trim((string) $row->DE_Telephone);
        $entrepot->fax          = trim((string) $row->DE_Telecopie);

        if ($entrepot->create($this->user) <= 0) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Échec de la création de l\'entrepôt principal : '.$this->objectErrors($entrepot),
            );
            return -1;
        }

        $this->mainWarehouseId = (int) $entrepot->id;
        $this->warehouseByLabel[$key] = $this->mainWarehouseId;
        $this->mainCreated = 1;

        return $this->mainWarehouseId;
    }

    /**
     * Pose le marqueur de reprise sur l'entrepôt principal adopté.
     *
     * Sans cela, l'adoption reste invisible : l'entrepôt fait office de dépôt principal
     * pendant ce passage, mais rien ne le dit en base. Le script `stock` cherche
     * précisément ce marqueur pour savoir où verser les articles sans emplacement, et
     * s'arrête net s'il ne le trouve pas — c'est arrivé en production, où l'entrepôt de la
     * boutique préexistait à la reprise.
     *
     * C'est la même règle que pour les tiers venus de la boutique : on ne recrée pas, on
     * ne renomme pas, on complète ce qui est vide et on pose son marqueur.
     *
     * Le passage par `Entrepot::update()` réécrit toutes les colonnes de l'entrepôt depuis
     * l'objet, ce qui n'est neutre que parce que `fetch()` les charge toutes — vérifié
     * colonne par colonne, extrafields compris. Les triggers sont en revanche désactivés :
     * poser un marqueur technique n'est pas une modification métier, et WAREHOUSE_MODIFY
     * pourrait déclencher une synchronisation vers la boutique pour rien.
     *
     * @return int 1 si le marqueur est en place, -1 en cas d'échec
     */
    protected function stampMainWarehouse()
    {
        $this->mainAdopted = 1;

        if ($this->dryrun || $this->mainWarehouseId <= 0) {
            return 1;
        }

        $entrepot = new Entrepot($this->db);
        if ($entrepot->fetch($this->mainWarehouseId) <= 0) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Entrepôt principal '.$this->mainWarehouseId.' illisible : '
                    .$this->objectErrors($entrepot),
            );
            return -1;
        }

        $marker = $this->buildRefExt($this->mainImportKey);

        if ((string) $entrepot->import_key === $marker) {
            return 1;
        }

        // Un marqueur étranger n'est jamais écrasé : il appartient à un autre import, et le
        // détruire ferait perdre à celui-ci son idempotence. Signalé au rapport, car « stock »
        // ne trouvera alors pas son dépôt principal.
        if (!empty($entrepot->import_key)) {
            $this->mainForeignKey = (string) $entrepot->import_key;
            return 1;
        }

        $entrepot->import_key = $marker;

        if ($entrepot->update($this->mainWarehouseId, $this->user, 1) <= 0) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Marqueur '.$marker.' non posé sur l\'entrepôt '
                    .$this->mainWarehouseId.' : '.$this->objectErrors($entrepot),
            );
            return -1;
        }

        $this->mainStamped = 1;

        return 1;
    }

    /**
     * Dénombre les emplacements qu'aucun article n'occupe.
     *
     * Ils ne sont pas repris, mais leur nombre a sa place au rapport : c'est ce qui
     * explique l'écart entre les 1 006 emplacements de la source et les entrepôts créés.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countUnused()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.$this->srcTable;
        $sql .= ' WHERE NOT ('.$this->srcWhere.')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $this->unused = (int) $obj->nb;

        return 1;
    }

    /**
     * Positionne les propriétés que `create()` écrase s'il ne les trouve pas.
     *
     * `Entrepot::create()` insère une ligne minimale puis appelle `update()`, lequel écrit
     * sans condition `statut`, `warehouse_usage` et `fk_user_author` à partir des
     * propriétés de l'objet. Les laisser vides donne un entrepôt **fermé**, d'usage `0`
     * — valeur qui n'est ni interne ni externe — et sans auteur. C'est exactement l'état
     * des trois entrepôts de démonstration déjà présents en base.
     *
     * @param Entrepot $entrepot  Objet à préparer
     * @param string   $label     Libellé
     * @param int      $parentId  Entrepôt parent, 0 pour une racine
     * @param string   $sourceKey Clé source, reportée dans import_key
     * @return void
     */
    protected function fillWarehouse(Entrepot $entrepot, $label, $parentId, $sourceKey)
    {
        $entrepot->label            = $label;
        $entrepot->statut           = Entrepot::STATUS_OPEN_ALL;
        $entrepot->warehouse_usage  = Entrepot::USAGE_INTERNAL;
        $entrepot->user_creation_id = (int) $this->user->id;
        $entrepot->fk_parent        = (int) $parentId;
        $entrepot->import_key       = $this->buildRefExt($sourceKey);
    }

    /**
     * Code ISO du pays du dépôt.
     *
     * La source écrit le pays en clair. Un seul dépôt est concerné : une correspondance
     * complète serait disproportionnée, seul le cas français est traité, les autres
     * laissant le pays vide.
     *
     * @param string $label Libellé du pays
     * @return string       Code ISO à deux lettres, chaîne vide si non reconnu
     */
    protected function resolveCountryCode($label)
    {
        return ($this->normalizeLabel($label) === 'france') ? 'FR' : '';
    }

    /**
     * Nombre d'entrepôts déjà repris.
     *
     * Comptés sur import_key, `llx_entrepot` n'ayant pas de ref_ext.
     *
     * @return int Nombre d'entrepôts, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Clé de comparaison d'un libellé d'entrepôt.
     *
     * Reproduit la sémantique de l'index unique de la table, insensible à la casse et aux
     * accents. Sans cela, « BOUTIQUE » et « Boutique » seraient tenus pour distincts ici
     * alors que MySQL les refuserait à l'écriture.
     *
     * @param string $label Libellé
     * @return string       Clé de comparaison
     */
    protected function labelKey($label)
    {
        return strtolower(dol_string_unaccent(trim((string) $label)));
    }

    /**
     * Retrouve un entrepôt par son marqueur de reprise.
     *
     * @param string $importKey Marqueur
     * @return int              rowid, 0 si aucun
     */
    protected function findByImportKey($importKey)
    {
        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key = '".$this->db->escape($importKey)."'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->rowid : 0;
    }

    /**
     * Charge l'index des entrepôts déjà repris.
     *
     * `llx_entrepot` ne porte pas de `ref_ext` : le mécanisme natif du socle est
     * inapplicable. On se rabat sur `import_key`, que `Entrepot::update()` écrit — et
     * `create()` appelant `update()`, le marqueur est posé dès la création, sans seconde
     * passe.
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
     * Libellé retenu pour un emplacement.
     *
     * Trois emplacements utilisés n'ont aucun libellé dans la source. Dolibarr refuse un
     * entrepôt sans nom : leur numéro d'origine en tient lieu, ce qui les rend au moins
     * identifiables. Les libellés fantaisistes de la source — « blibli », « AFFECTER » —
     * sont en revanche conservés tels quels : ce sont des saisies réelles, au client de
     * les corriger en connaissance de cause.
     *
     * @param stdClass $row Ligne source
     * @return string       Libellé
     */
    protected function resolveLabel($row)
    {
        $label = trim((string) $row->label);

        if ($label === '') {
            $label = 'Emplacement '.((int) $row->rowid);
            $this->unnamed[(int) $row->rowid] = $label;
        }

        return $label;
    }

    /**
     * Crée le sous-entrepôt correspondant à un emplacement.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de l'entrepôt déjà repris, 0 si création
     * @return array{action:string,id:int}
     * @throws Exception Si la création échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $label = $this->resolveLabel($row);

        if ($existingId > 0) {
            // Le libellé d'un entrepôt déjà repris n'est pas retouché : le client a pu le
            // renommer, et ce nom sert de clé d'unicité à toute la base.
            return array('action' => 'updated', 'id' => $existingId);
        }

        // Un entrepôt porte déjà ce libellé : les emplacements homonymes de la source sont
        // fusionnés, Dolibarr n'en acceptant qu'un.
        $key = $this->labelKey($label);
        if (isset($this->warehouseByLabel[$key])) {
            $this->mergedTwins++;
            return array('action' => 'skipped', 'id' => $this->warehouseByLabel[$key]);
        }

        $entrepot = new Entrepot($this->db);
        $this->fillWarehouse($entrepot, $label, $this->mainWarehouseId, $this->getSourceKey($row));
        $entrepot->description = 'Emplacement '.((int) $row->rowid).' de l\'ancien ERP (ADD)';

        if ($entrepot->create($this->user) <= 0) {
            throw new Exception('Échec de la création de « '.$label.' » : '.$this->objectErrors($entrepot));
        }

        $this->warehouseByLabel[$key] = (int) $entrepot->id;

        return array('action' => 'created', 'id' => (int) $entrepot->id);
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne est inexploitable
     */
    protected function validateRow($row)
    {
        $this->resolveLabel($row);
    }

    /**
     * Annonce l'action prévue en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de l'entrepôt déjà repris, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'updated';
        }

        // resolveLabel() a déjà été appelée par validateRow() : on ne compte pas deux fois
        // les emplacements sans nom.
        $label = trim((string) $row->label);
        if ($label === '') {
            $label = 'Emplacement '.((int) $row->rowid);
        }

        $key = $this->labelKey($label);
        if (isset($this->warehouseByLabel[$key])) {
            $this->mergedTwins++;
            return 'skipped';
        }

        // L'index suit la simulation, sinon trois emplacements homonymes seraient tous
        // annoncés comme des créations.
        $this->warehouseByLabel[$key] = -1;

        return 'created';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des entrepôts posés par la reprise (table '.MAIN_DB_PREFIX
            .$this->dstTable.', marqueur import_key « '.$this->refExtPrefix.' »)';
    }

    /**
     * Supprime les entrepôts créés par la reprise.
     *
     * Les sous-entrepôts sont traités avant l'entrepôt principal : Dolibarr refuse de
     * supprimer un entrepôt qui en porte d'autres. Il refuse également d'en supprimer un
     * qui contient du stock — c'est voulu, et le message le dit.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $mainKey = $this->buildRefExt($this->mainImportKey);

        // Les enfants d'abord, le principal en dernier.
        $sql  = 'SELECT rowid, ref, import_key FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= " ORDER BY (import_key = '".$this->db->escape($mainKey)."') ASC, rowid DESC";

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

        foreach ($rows as $row) {
            $entrepot = new Entrepot($this->db);
            if ($entrepot->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = $row->ref.' : chargement impossible';
                continue;
            }

            $this->db->begin();
            if ($entrepot->delete($this->user) > 0) {
                $this->db->commit();
                $result['deleted']++;
            } else {
                $this->db->rollback();
                $result['failed']++;
                $result['errors'][] = $row->ref.' : '.$this->objectErrors($entrepot);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 200 === 0)) {
                call_user_func($progress, $result['deleted'] + $result['failed'], $result['count']);
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

        $lines[] = 'Entrepôt principal :';
        if ($this->mainWarehouseId > 0) {
            $lines[] = '          « '.$this->mainWarehouseLabel.' » (rowid '.$this->mainWarehouseId.')'
                .($this->mainCreated ? ', créé durant ce passage' : ', déjà présent');
        } else {
            $lines[] = '          « '.$this->mainWarehouseLabel.' », non créé (simulation)';
        }
        $lines[] = '          Les emplacements sont rattachés à plat en dessous. Leurs libellés';
        $lines[] = '          portent déjà leur chemin (« S1-A15-4 »), la hiérarchie fine peut';
        $lines[] = '          donc être construite ensuite sans jamais les renommer.';

        if ($this->mainStamped) {
            $lines[] = '          Il ne venait pas de la reprise : marqueur « '
                .$this->buildRefExt($this->mainImportKey).' » posé.';
            $lines[] = '          Rien d\'autre n\'a été modifié — ni son nom, ni son adresse.';
        } elseif ($this->mainForeignKey !== '') {
            $lines[] = '';
            $lines[] = '          ATTENTION : cet entrepôt porte déjà le marqueur « '.$this->mainForeignKey.' »,';
            $lines[] = '          venu d\'un autre import. Il n\'a pas été écrasé, mais « migrate.php stock »';
            $lines[] = '          ne trouvera pas son dépôt principal. Posez « '
                .$this->buildRefExt($this->mainImportKey).' »';
            $lines[] = '          sur un entrepôt, ou arbitrez le conflit avant de reprendre les stocks.';
        } elseif ($this->mainAdopted && $this->dryrun) {
            $lines[] = '          Il ne vient pas de la reprise : son marqueur « '
                .$this->buildRefExt($this->mainImportKey).' »';
            $lines[] = '          sera posé hors simulation, sans rien changer d\'autre.';
        }

        if ($this->mergedTwins > 0) {
            $lines[] = '';
            $lines[] = 'Emplacements fusionnés : '.$this->mergedTwins;
            $lines[] = '          Un même libellé sert à plusieurs emplacements de la source — huit';
            $lines[] = '          s\'appellent « BOUTIQUE ». Dolibarr impose l\'unicité du libellé sur';
            $lines[] = '          toute l\'entité : l\'entrepôt existant est réutilisé.';
        }

        if (!empty($this->unnamed)) {
            $lines[] = '';
            $lines[] = 'Emplacements sans libellé : '.count($this->unnamed);
            $lines[] = '          Nommés d\'après leur numéro d\'origine, Dolibarr refusant un entrepôt';
            $lines[] = '          sans nom : '.implode(', ', array_values($this->unnamed));
            $lines[] = '          Ils existent toujours dans l\'ancien ERP : les y nommer suffirait à';
            $lines[] = '          retrouver leur libellé au passage suivant.';
        }

        if (!empty($this->orphanLocations)) {
            $lines[] = '';
            $lines[] = 'Emplacements supprimés dans l\'ancien ERP : '.count($this->orphanLocations)
                .' ('.array_sum($this->orphanLocations).' articles)';
            $lines[] = '          Numéros '.implode(', ', array_keys($this->orphanLocations)).'.';
            $lines[] = '          Ce ne sont pas des trous d\'extraction : leurs voisins immédiats sont';
            $lines[] = '          bien présents. Quelqu\'un les a supprimés sans réaffecter leur contenu.';
            if ($this->orphanWarehouseId > 0) {
                $lines[] = '          Leurs articles sont regroupés dans « '.$this->orphanWarehouseLabel
                    .' » (rowid '.$this->orphanWarehouseId.'),';
                $lines[] = '          pour être retrouvés puis rangés.';
            } else {
                $lines[] = '          Un entrepôt « '.$this->orphanWarehouseLabel.' » les regroupera.';
            }
        }

        if ($this->unused > 0) {
            $lines[] = '';
            $lines[] = 'Emplacements non repris : '.$this->unused;
            $lines[] = '          Aucun article ne les occupe. Ils seront créés au passage suivant';
            $lines[] = '          s\'ils reviennent à l\'usage.';
        }

        return $lines;
    }
}
