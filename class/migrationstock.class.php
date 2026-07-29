<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationstock.class.php
 * \ingroup aeromigration
 * \brief   Reprise des stocks : f_artstock -> mouvements d'entrée Dolibarr.
 *
 * Le stock est repris comme une **photo d'ouverture** : un mouvement par ligne, à la date
 * de bascule. L'ancien ERP conserve un historique d'environ 581 000 lignes de documents,
 * qui n'est pas rejoué — il reste consultable là-bas.
 *
 * Trois partis pris demandent une explication, chacun tenant à un piège du coeur Dolibarr.
 *
 * **1. `MouvementStock::_create()` plutôt que `Product::correct_stock()`.** Cette dernière
 * est la porte d'entrée habituelle, mais elle ne convient pas ici pour deux raisons qui
 * se cumulent. Elle n'expose pas de paramètre de date, alors qu'une bascule se cale sur
 * une date convenue. Et surtout elle **annonce un succès quand rien n'a été écrit** :
 * `if ($result >= 0) return 1` (product.class.php:6243), or `_create()` retourne `0` —
 * et non un code négatif — lorsqu'il n'a rien fait, notamment sur un produit que Dolibarr
 * ne gère pas en stock. Trois articles de la source sont des services en cible : leur
 * stock aurait disparu sans le moindre message. D'où le test `<= 0` sur le retour.
 *
 * **2. Idempotence par `inventorycode`.** `llx_product_stock` porte bien un `import_key`,
 * et son index unique `(fk_product, fk_entrepot)` en ferait une clé propre — mais aucune
 * classe du coeur n'a `table_element = 'product_stock'`, et `_create()` ne touche pas
 * cette colonne. L'écrire imposerait une requête directe, que la règle du module interdit.
 * `inventorycode` est en revanche écrit par `_create()`, et sa vocation déclarée dans le
 * coeur est précisément celle-là : regrouper plusieurs lignes de mouvement en une même
 * opération. Tous les mouvements de la reprise portent « SAGE:OUVERTURE ».
 *
 * Le client y gagne une poignée concrète : filtrer la colonne « Code inventaire » dans
 * Produits > Stocks > Mouvements sort la reprise en entier, et l'action de masse
 * « contre-passer » du coeur s'y applique telle quelle.
 *
 * **3. `llx_stock_mouvement` n'a ni `entity` ni `ref_ext`.** Les chemins génériques du
 * socle qui appellent `getEntity()` — `loadMigratedIndex()` et `purge()` — sont donc l'un
 * et l'autre surchargés. Ce n'est pas un oubli.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

class MigrationStock extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'stock';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptStock';

    /** @var string Table source */
    protected $srcTable = 'f_artstock';

    /** @var string Colonnes lues : la table en compte 43, dont dix id_externe sans emploi */
    protected $srcFields = 'AR_Ref, DE_No, AS_QteSto, AS_QteMini, AS_QteMaxi, DP_NoPrincipal';

    /**
     * Colonne de parcours.
     *
     * `cbMarq` vaut 0 sur les 27 670 lignes de cette table : inutilisable, contrairement à
     * d'autres tables de la source. `AR_Ref` est la colonne de tête de la clé primaire, et
     * devient unique dès lors que `DE_No` est figé à 1.
     *
     * @var string
     */
    protected $srcCursorField = 'AR_Ref';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'AR_Ref';

    /**
     * Filtre de lecture.
     *
     * Seul le dépôt 1 porte du stock : le 999 est une coquille de 11 826 lignes à zéro, et
     * un dépôt 0 non déclaré traîne cinq lignes négatives.
     *
     * Les lignes sans stock sont lues **si elles portent un seuil** : 236 lignes n'ont que
     * cela à donner, et un filtre sur la seule quantité les perdrait.
     *
     * @var string
     */
    protected $srcWhere = "DE_No = 1 AND TRIM(AR_Ref) <> ''"
        ." AND (AS_QteSto <> 0 OR AS_QteMini <> 0 OR AS_QteMaxi <> 0)";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'stock_mouvement';

    /** @var string Élément Dolibarr */
    protected $dstElement = 'stock_mouvement';

    /** @var int Numéro du dépôt réel dans l'ancien ERP */
    protected $mainDepotNo = 1;

    /** @var string Code d'inventaire porté par tous les mouvements de la reprise */
    protected $inventoryKey = 'OUVERTURE';

    /** @var string Code d'inventaire des contre-passations, posé par la purge */
    protected $cancelKey = 'ANNULATION';

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,array{id:int,ref:string,type:int}> AR_Ref -> produit Dolibarr */
    protected $productBySage = array();

    /** @var array<string,int> import_key -> rowid d'entrepôt */
    protected $warehouseByImportKey = array();

    /** @var array<string,int> Libellé normalisé -> rowid d'entrepôt */
    protected $warehouseByLabel = array();

    /** @var int rowid de l'entrepôt principal */
    protected $mainWarehouseId = 0;

    /** @var int rowid de l'entrepôt « À localiser » */
    protected $orphanWarehouseId = 0;

    /** @var array<int,string> Numéro d'emplacement -> libellé */
    protected $locationLabels = array();

    /** @var array<string,float> AR_Ref -> coût standard */
    protected $costBySage = array();

    /** @var array<string,bool> Articles que la source ne suit pas en stock */
    protected $unmanagedArticles = array();

    /** @var array<string,bool> Références présentes dans f_article */
    protected $sourceArticles = array();

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Mouvements posés */
    protected $movedLines = 0;

    /** @var float Unités reprises */
    protected $movedUnits = 0;

    /** @var float Valeur du stock repris */
    protected $movedValue = 0;

    /** @var int Lignes de quantité négative */
    protected $negativeLines = 0;

    /** @var float Cumul des quantités négatives */
    protected $negativeUnits = 0;

    /** @var int Lignes reprises sans coût standard */
    protected $withoutCost = 0;

    /** @var int Lignes placées dans leur sous-entrepôt par le marqueur */
    protected $byMarker = 0;

    /** @var int Lignes dont l'emplacement avait été fusionné, retrouvé par libellé */
    protected $byLabel = 0;

    /** @var int Lignes sans emplacement d'origine */
    protected $noLocation = 0;

    /** @var array<int,bool> Emplacements supprimés dans l'ancien ERP, rencontrés */
    protected $deletedLocations = array();

    /** @var int Lignes venues pour leurs seuls seuils */
    protected $thresholdOnly = 0;

    /** @var int Seuils d'alerte écrits */
    protected $alertSet = 0;

    /** @var int Stocks désirés écrits */
    protected $desiredSet = 0;

    /** @var array<int,string> Seuils négatifs ramenés à zéro */
    protected $negativeThresholds = array();

    /** @var array<string,float> Produits de type service porteurs de stock */
    protected $serviceProducts = array();

    /** @var int Lignes dont l'article n'est pas encore repris */
    protected $productNotMigrated = 0;

    /** @var array<string,bool> Références absentes de f_article */
    protected $productNotInSource = array();

    /** @var int Lignes écartées faute de suivi de stock dans la source */
    protected $unmanaged = 0;

    /** @var array<string,int> Lignes écartées à la lecture, par motif */
    protected $discarded = array();

    /**
     * Charge tout ce dont la reprise a besoin.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        foreach (array(
            'loadProductIndex',
            'loadWarehouseIndex',
            'loadLocationLabels',
            'loadArticleIndex',
            'countDiscarded',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Index des produits repris : AR_Ref -> rowid, référence et type.
     *
     * Le type sert à écarter les services : Dolibarr ne leur déplace pas de stock tant que
     * STOCK_SUPPORTS_SERVICES n'est pas activée, et l'API le tait.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref, ref_ext, fk_product_type FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = substr((string) $obj->ref_ext, strlen($prefix));
            $this->productBySage[$key] = array(
                'id'   => (int) $obj->rowid,
                'ref'  => (string) $obj->ref,
                'type' => (int) $obj->fk_product_type,
            );
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des entrepôts, par marqueur de reprise et par libellé.
     *
     * Les deux sont nécessaires : le script `warehouse` a fusionné 93 emplacements
     * homonymes, qui n'ont donc pas leur propre entrepôt et ne se retrouvent que par leur
     * libellé.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function loadWarehouseIndex()
    {
        $sql  = 'SELECT rowid, ref, import_key FROM '.MAIN_DB_PREFIX.'entrepot';
        $sql .= ' WHERE entity IN ('.getEntity('stock').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            if (!empty($obj->import_key)) {
                $this->warehouseByImportKey[(string) $obj->import_key] = (int) $obj->rowid;
            }
            $this->warehouseByLabel[$this->labelKey($obj->ref)] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        $mainKey   = $this->buildRefExt('DEPOT1');
        $orphanKey = $this->buildRefExt('ORPHELIN');

        $this->mainWarehouseId   = isset($this->warehouseByImportKey[$mainKey]) ? $this->warehouseByImportKey[$mainKey] : 0;
        $this->orphanWarehouseId = isset($this->warehouseByImportKey[$orphanKey]) ? $this->warehouseByImportKey[$orphanKey] : 0;

        // Seule dépendance dure du script : sans entrepôt, aucun mouvement n'est possible.
        if ($this->mainWarehouseId <= 0) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Entrepôt principal introuvable (marqueur '.$mainKey.') :'
                    .' lancez « migrate.php warehouse » avant celui-ci.',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Libellés des emplacements de l'ancien ERP.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadLocationLabels()
    {
        $resql = $this->db->query('SELECT rowid, label FROM f_emplacements');
        if (!$resql) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Table f_emplacements introuvable : rejouez data/f_emplacements.sql. ('
                    .$this->db->lasterror().')',
            );
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->locationLabels[(int) $obj->rowid] = (string) $obj->label;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Coût standard et drapeau de suivi de stock, depuis l'article source.
     *
     * Le coût standard est retenu plutôt que `AS_PrixRU`, vide sur 75 % des lignes de
     * stock, et plutôt que `AS_CoutStd`, qui n'en est qu'une recopie. C'est la même valeur
     * que celle posée en coût de revient sur la fiche produit.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadArticleIndex()
    {
        $resql = $this->db->query('SELECT AR_Ref, AR_CoutStd, AR_SuiviStock FROM f_article');
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $ref = trim((string) $obj->AR_Ref);
            $this->sourceArticles[$ref] = true;
            $this->costBySage[$ref]     = (float) $obj->AR_CoutStd;
            if ((int) $obj->AR_SuiviStock === 0) {
                $this->unmanagedArticles[$ref] = true;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Dénombre ce que le filtre de lecture écarte.
     *
     * Ces lignes n'apparaissent dans aucun compteur du socle puisqu'elles ne sont jamais
     * lues. Sans ce décompte, le rapport laisserait croire que la source a été traitée en
     * entier — or l'une d'elles porte à elle seule −1 826 unités.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscarded()
    {
        $queries = array(
            'sans référence article' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM f_artstock WHERE DE_No = 1 AND TRIM(AR_Ref) = ''",
            'dépôt 999, toutes à zéro' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM f_artstock WHERE DE_No = 999",
            'dépôt non déclaré' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM f_artstock WHERE DE_No NOT IN (1, 999)",
            'stock en dépôt-vente (f_consigne)' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM f_consigne",
        );

        foreach ($queries as $motif => $sql) {
            $resql = $this->db->query($sql);
            if (!$resql) {
                // f_consigne peut manquer sur une instance allégée : ce n'est pas bloquant.
                continue;
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ((int) $obj->nb > 0) {
                $this->discarded[$motif] = array('nb' => (int) $obj->nb, 'qte' => (float) $obj->qte);
            }
        }

        return 1;
    }

    /**
     * Charge l'index des lignes déjà reprises.
     *
     * `llx_stock_mouvement` n'a ni `ref_ext` ni `entity` : le mécanisme natif du socle est
     * inapplicable, et `getEntity()` n'a rien à filtrer ici. L'index est bâti sur le code
     * d'inventaire, remonté jusqu'au `ref_ext` du produit pour que `processRow()` s'y
     * retrouve. Une ligne source valant un article — `AR_Ref` est unique au dépôt 1 —, la
     * correspondance est bijective.
     *
     * @return int Nombre d'entrées chargées, -1 en cas d'erreur SQL
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        $sql  = 'SELECT DISTINCT p.rowid, p.ref_ext FROM '.MAIN_DB_PREFIX.'stock_mouvement as m';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = m.fk_product';
        $sql .= " WHERE m.inventorycode = '".$this->db->escape($this->buildRefExt($this->inventoryKey))."'";
        $sql .= " AND p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

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
     * Clé de comparaison d'un libellé d'entrepôt.
     *
     * Reproduit celle de MigrationWarehouse, elle-même calquée sur la collation de
     * `uk_entrepot_label` : insensible à la casse et aux accents.
     *
     * @param string $label Libellé
     * @return string       Clé de comparaison
     */
    protected function labelKey($label)
    {
        return strtolower(dol_string_unaccent(trim((string) $label)));
    }

    /**
     * Date des mouvements.
     *
     * @return int Timestamp
     */
    protected function resolveDate()
    {
        return ($this->referenceDate > 0) ? $this->referenceDate : dol_now();
    }

    /**
     * Entrepôt où poser le stock d'une ligne source.
     *
     * Quatre cas, qui couvrent la totalité des lignes. Le marqueur de reprise est
     * interrogé **avant** le libellé, et c'est indispensable : les emplacements dont le
     * libellé était vide dans la source ont été créés sous le nom « Emplacement 512 », une
     * résolution par libellé les manquerait.
     *
     * @param stdClass $row Ligne source
     * @return int          rowid de l'entrepôt, 0 si non résolu
     */
    protected function resolveWarehouse($row)
    {
        $no = (int) $row->DP_NoPrincipal;

        // Aucun emplacement d'origine : l'article n'a jamais été rangé.
        if ($no <= 0) {
            $this->noLocation++;
            return $this->mainWarehouseId;
        }

        $key = $this->buildRefExt((string) $no);
        if (isset($this->warehouseByImportKey[$key])) {
            $this->byMarker++;
            return $this->warehouseByImportKey[$key];
        }

        // L'emplacement existe dans la source mais n'a pas son propre entrepôt : il a été
        // fusionné avec un homonyme, Dolibarr imposant l'unicité du libellé.
        if (isset($this->locationLabels[$no])) {
            $labelKey = $this->labelKey($this->locationLabels[$no]);
            if ($labelKey !== '' && isset($this->warehouseByLabel[$labelKey])) {
                $this->byLabel++;
                return $this->warehouseByLabel[$labelKey];
            }
            return 0;
        }

        // Emplacement supprimé dans l'ancien ERP : regroupé pour être retrouvé.
        $this->deletedLocations[$no] = true;

        return $this->orphanWarehouseId;
    }

    /**
     * Libellé du mouvement, porteur de la provenance de la ligne.
     *
     * Le code d'inventaire étant constant, c'est le libellé qui répond à la question que
     * le client se posera devant chaque ligne : d'où vient ce stock ?
     *
     * @param stdClass $row Ligne source
     * @return string       Libellé, au plus 255 caractères
     */
    protected function buildLabel($row)
    {
        $base = 'Stock d\'ouverture ADD';
        $no   = (int) $row->DP_NoPrincipal;

        if ($no <= 0) {
            return $base.' — sans emplacement d\'origine';
        }

        if (!isset($this->locationLabels[$no])) {
            return $base.' — emplacement '.$no.', supprimé dans l\'ancien ERP';
        }

        $label = trim($this->locationLabels[$no]);
        $known = isset($this->warehouseByImportKey[$this->buildRefExt((string) $no)]);

        // Emplacement fusionné : on précise sous quel nom son stock a été rangé.
        if (!$known && $label !== '') {
            return dol_trunc($base.' — emplacement '.$no.' ('.$label.')', 255, 'right', 'UTF-8', 1);
        }

        return $base.' — emplacement '.$no;
    }

    /**
     * Pose le stock d'ouverture d'une ligne source.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du produit déjà repris, 0 sinon
     * @return array{action:string,id:int}
     * @throws Exception Si le mouvement ou les seuils ne peuvent pas être écrits
     */
    protected function migrateRow($row, $existingId)
    {
        $ref  = trim((string) $row->AR_Ref);
        $qty  = (float) $row->AS_QteSto;
        $mini = (float) $row->AS_QteMini;
        $maxi = (float) $row->AS_QteMaxi;

        if (!isset($this->productBySage[$ref])) {
            $this->countMissingProduct($ref);
            return array('action' => 'skipped', 'id' => 0);
        }
        $target = $this->productBySage[$ref];

        // Garde-fou : les articles non suivis en stock dans la source, dont les sept
        // pseudo-articles de port et de retrait. Aucun ne porte de stock aujourd'hui.
        if (isset($this->unmanagedArticles[$ref])) {
            $this->unmanaged++;
            return array('action' => 'skipped', 'id' => 0);
        }

        // Déjà repris : le mouvement n'est JAMAIS rejoué, ce serait doubler le stock.
        // Seuls les seuils, dont l'écriture est idempotente, sont réappliqués.
        if ($existingId > 0) {
            $this->applyThresholds($target, $mini, $maxi);
            return array('action' => 'updated', 'id' => $existingId);
        }

        // Ligne venue pour ses seuls seuils : elle ne produit aucun mouvement, donc
        // n'entrera jamais dans l'index et sera retraitée à chaque passage. Sans danger.
        if ($qty == 0) {
            $this->applyThresholds($target, $mini, $maxi);
            $this->thresholdOnly++;
            return array('action' => 'updated', 'id' => (int) $target['id']);
        }

        // Dolibarr ne déplace pas le stock d'un service tant que STOCK_SUPPORTS_SERVICES
        // n'est pas activée, et l'API le tait : _create() retournerait 0 sans rien écrire.
        // Détecté ici plutôt que subi plus loin.
        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            $this->serviceProducts[$target['ref']] = $qty;
            return array('action' => 'skipped', 'id' => 0);
        }

        $warehouseId = $this->resolveWarehouse($row);
        if ($warehouseId <= 0) {
            throw new Exception('Emplacement '.((int) $row->DP_NoPrincipal).' non résolu : '
                .'aucun entrepôt correspondant, relancez « migrate.php warehouse »');
        }

        $cost = isset($this->costBySage[$ref]) ? (float) $this->costBySage[$ref] : 0;

        // Types 3 et 2 : entrée et sortie de stock. Les types 0 et 1 désignent des
        // transferts entre entrepôts, ce qu'un stock d'ouverture n'est pas. Seules les
        // entrées alimentent le coût moyen pondéré (mouvementstock.class.php:579), d'où
        // le prix laissé à zéro sur une sortie — il n'y aurait aucun sens à lui en donner.
        $type  = ($qty > 0) ? 3 : 2;
        $price = ($qty > 0) ? $cost : 0;

        $movement = new MouvementStock($this->db);
        $mvid = $movement->_create(
            $this->user,
            (int) $target['id'],
            $warehouseId,
            $qty,
            $type,
            $price,
            $this->buildLabel($row),
            $this->buildRefExt($this->inventoryKey),
            $this->resolveDate()
        );

        // <= 0 et non < 0 : _create() retourne 0, sans erreur, quand il n'a rien fait.
        if ($mvid <= 0) {
            throw new Exception('Mouvement refusé (code '.$mvid.') : '.$this->objectErrors($movement));
        }

        $this->applyThresholds($target, $mini, $maxi);

        $this->movedLines++;
        $this->movedUnits += $qty;
        $this->movedValue += $qty * $cost;
        if ($qty < 0) {
            $this->negativeLines++;
            $this->negativeUnits += $qty;
        }
        if ($cost <= 0) {
            $this->withoutCost++;
        }

        return array('action' => 'created', 'id' => (int) $target['id']);
    }

    /**
     * Écrit les seuils de réapprovisionnement sur l'article.
     *
     * Sur l'article et non par entrepôt : `llx_product_warehouse_properties` n'est lue que
     * par le réapprovisionnement, et seulement si STOCK_ALLOW_ADD_LIMIT_STOCK_BY_WAREHOUSE
     * est activée — elle ne l'est pas. Les seuils y seraient invisibles partout. La source
     * ne les donne d'ailleurs que pour le dépôt 1, seul dépôt réel : ils sont globaux à
     * l'article par construction.
     *
     * `Product::update()` n'écrit ni `stock` ni `pmp`, le coeur le commente explicitement :
     * l'appel ne peut donc pas écraser ce que le mouvement vient de poser.
     *
     * @param array $target Entrée d'index du produit
     * @param float $mini   Seuil d'alerte source
     * @param float $maxi   Stock désiré source
     * @return void
     * @throws Exception Si l'écriture échoue
     */
    protected function applyThresholds($target, $mini, $maxi)
    {
        // Deux articles portent un seuil d'alerte à -200 : il ne se déclencherait jamais
        // et s'afficherait comme une aberration sur la fiche.
        $alert = $mini;
        if ($alert < 0) {
            $this->negativeThresholds[$target['ref']] = $target['ref'];
            $alert = 0;
        }
        $desired = ($maxi > 0) ? $maxi : 0;

        if ($alert <= 0 && $desired <= 0) {
            return;
        }

        $product = new Product($this->db);
        if ($product->fetch((int) $target['id']) <= 0) {
            throw new Exception('Produit introuvable (rowid '.$target['id'].') : '.$this->objectErrors($product));
        }

        $product->seuil_stock_alerte = $alert;
        $product->desiredstock       = $desired;

        if ($product->update($product->id, $this->user) <= 0) {
            throw new Exception('Échec de l\'écriture des seuils : '.$this->objectErrors($product));
        }

        if ($alert > 0) {
            $this->alertSet++;
        }
        if ($desired > 0) {
            $this->desiredSet++;
        }
    }

    /**
     * Compte une ligne dont l'article n'a pas été retrouvé, en distinguant la cause.
     *
     * @param string $ref Référence de l'article
     * @return void
     */
    protected function countMissingProduct($ref)
    {
        if (isset($this->sourceArticles[$ref])) {
            $this->productNotMigrated++;
        } else {
            $this->productNotInSource[$ref] = true;
        }
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de poser un mouvement valide
     */
    protected function validateRow($row)
    {
        $ref = trim((string) $row->AR_Ref);
        $qty = (float) $row->AS_QteSto;

        if (!isset($this->productBySage[$ref])) {
            $this->countMissingProduct($ref);
            return;
        }
        if (isset($this->unmanagedArticles[$ref])) {
            $this->unmanaged++;
            return;
        }

        $target = $this->productBySage[$ref];
        $mini   = (float) $row->AS_QteMini;
        $maxi   = (float) $row->AS_QteMaxi;

        // Les seuils sont comptés sans être écrits.
        $alert = $mini;
        if ($alert < 0) {
            $this->negativeThresholds[$target['ref']] = $target['ref'];
            $alert = 0;
        }
        if ($alert > 0) {
            $this->alertSet++;
        }
        if ($maxi > 0) {
            $this->desiredSet++;
        }

        if ($qty == 0) {
            $this->thresholdOnly++;
            return;
        }

        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            $this->serviceProducts[$target['ref']] = $qty;
            return;
        }

        if ($this->resolveWarehouse($row) <= 0) {
            throw new Exception('Emplacement '.((int) $row->DP_NoPrincipal).' non résolu');
        }

        $cost = isset($this->costBySage[$ref]) ? (float) $this->costBySage[$ref] : 0;

        $this->movedLines++;
        $this->movedUnits += $qty;
        $this->movedValue += $qty * $cost;
        if ($qty < 0) {
            $this->negativeLines++;
            $this->negativeUnits += $qty;
        }
        if ($cost <= 0) {
            $this->withoutCost++;
        }
    }

    /**
     * Annonce l'action prévue en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du produit déjà repris, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        $ref = trim((string) $row->AR_Ref);

        if (!isset($this->productBySage[$ref]) || isset($this->unmanagedArticles[$ref])) {
            return 'skipped';
        }
        if ($existingId > 0 || (float) $row->AS_QteSto == 0) {
            return 'updated';
        }

        $target = $this->productBySage[$ref];
        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            return 'skipped';
        }

        return 'created';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Contre-passation des mouvements de stock d\'ouverture (code d\'inventaire « '
            .$this->buildRefExt($this->inventoryKey).' »), suppression des lignes de mouvement,'
            .' puis remise à zéro des seuils posés par la reprise';
    }

    /**
     * Défait le stock d'ouverture.
     *
     * Supprimer les mouvements ne suffirait pas : `MouvementStock::delete()` retire la
     * ligne d'historique **et rien d'autre** — ni `llx_product_stock`, ni le stock
     * dénormalisé du produit, ni le coût moyen ne sont recalculés. Le stock resterait en
     * place, privé de sa trace d'origine : le pire des deux mondes.
     *
     * Chaque mouvement est donc d'abord contre-passé, ce qui fait repasser le coeur par
     * `_create()` et remet `product_stock` en état, puis les deux lignes sont supprimées.
     *
     * `reverseMovement()` du coeur n'est pas employée : elle s'appuie sur `global $user`
     * et laisse la ligne d'origine en place, que l'index d'idempotence verrait encore —
     * une purge qui empêche de rejouer manquerait son objet.
     *
     * À réserver à la mise au point : la contre-passation retire ce que la reprise a
     * ajouté, non ce qui est présent aujourd'hui. Après des ventes, le stock passerait en
     * négatif. Le coût moyen n'est pas davantage restauré, `_create()` ne le recalculant
     * que sur les entrées.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid, fk_product, fk_entrepot, value, type_mouvement';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement';
        $sql .= " WHERE inventorycode = '".$this->db->escape($this->buildRefExt($this->inventoryKey))."'";
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

        foreach ($rows as $row) {
            if ($this->reverseAndDelete($row, $result)) {
                $result['deleted']++;
            } else {
                $result['failed']++;
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 200 === 0)) {
                call_user_func($progress, $result['deleted'] + $result['failed'], $result['count']);
            }
        }

        $this->purgeThresholds($result);

        return $result;
    }

    /**
     * Contre-passe un mouvement puis supprime les deux lignes.
     *
     * @param stdClass $row    Ligne de mouvement
     * @param array    $result Résultat de la purge, alimenté en cas d'échec
     * @return bool            true si le mouvement a été défait
     */
    protected function reverseAndDelete($row, array &$result)
    {
        $qty  = (float) $row->value;
        $type = ((int) $row->type_mouvement === 3) ? 2 : 3;

        $this->db->begin();

        $reverse = new MouvementStock($this->db);
        $mvid = $reverse->_create(
            $this->user,
            (int) $row->fk_product,
            (int) $row->fk_entrepot,
            -$qty,
            $type,
            0,
            'Annulation du stock d\'ouverture ADD',
            $this->buildRefExt($this->cancelKey),
            dol_now()
        );

        if ($mvid <= 0) {
            $this->db->rollback();
            $result['errors'][] = 'Mouvement '.$row->rowid.' : contre-passation refusée ('
                .$this->objectErrors($reverse).')';
            return false;
        }

        // Le stock est revenu à son état antérieur : les deux lignes ne sont plus que de
        // l'historique, on les retire pour que la reprise puisse être rejouée.
        foreach (array((int) $mvid, (int) $row->rowid) as $id) {
            $movement = new MouvementStock($this->db);
            if ($movement->fetch($id) > 0) {
                $movement->delete($this->user);
            }
        }

        $this->db->commit();

        return true;
    }

    /**
     * Remet à zéro les seuils posés par la reprise.
     *
     * @param array $result Résultat de la purge, alimenté en cas d'échec
     * @return void
     */
    protected function purgeThresholds(array &$result)
    {
        $sql  = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.'product as p';
        $sql .= ' INNER JOIN f_artstock as s ON CONCAT(\''.$this->db->escape($this->refExtPrefix).'\','
            .' CONVERT(s.AR_Ref USING utf8mb4)) = p.ref_ext';
        $sql .= ' WHERE s.DE_No = '.((int) $this->mainDepotNo);
        $sql .= ' AND (s.AS_QteMini <> 0 OR s.AS_QteMaxi <> 0)';
        $sql .= ' AND (p.seuil_stock_alerte <> 0 OR p.desiredstock <> 0)';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = 'Seuils : '.$this->db->lasterror();
            return;
        }

        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        foreach ($ids as $id) {
            $product = new Product($this->db);
            if ($product->fetch($id) <= 0) {
                continue;
            }
            $product->seuil_stock_alerte = 0;
            $product->desiredstock       = 0;
            if ($product->update($product->id, $this->user) <= 0) {
                $result['errors'][] = 'Seuils du produit '.$product->ref.' : '.$this->objectErrors($product);
            }
        }
    }

    /**
     * Rapport de fin de passage.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $lines = array();

        $this->reportStock($lines);
        $this->reportLocations($lines);
        $this->reportThresholds($lines);
        $this->reportSkipped($lines);
        $this->reportOutOfScope($lines);

        return $lines;
    }

    /**
     * Le stock repris.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportStock(array &$lines)
    {
        $block = array();

        $block[] = $this->countLine($this->movedLines, 'mouvement(s) d\'ouverture au '
            .dol_print_date($this->resolveDate(), 'day'));
        $block[] = $this->countLine((int) $this->movedUnits, 'unité(s), valorisées '
            .price($this->movedValue, 0, '', 1, 2).' € au coût standard');
        $block[] = '          Code d\'inventaire « '.$this->buildRefExt($this->inventoryKey).' » :';
        $block[] = '          filtrez dessus dans Produits > Stocks > Mouvements pour';
        $block[] = '          retrouver la reprise en entier.';

        if ($this->negativeLines > 0) {
            $block[] = $this->countLine($this->negativeLines, 'ligne(s) de quantité négative, cumul '
                .(int) $this->negativeUnits.', reprises telles quelles');
        }
        if ($this->withoutCost > 0) {
            $block[] = $this->countLine($this->withoutCost, 'ligne(s) sans coût standard : mouvement '
                .'posé sans prix, le coût moyen reste nul');
        }

        $this->appendBlock($lines, 'Stock d\'ouverture :', $block);
    }

    /**
     * La ventilation par emplacement.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportLocations(array &$lines)
    {
        $block = array();

        if ($this->byMarker > 0) {
            $block[] = $this->countLine($this->byMarker, 'ligne(s) placée(s) dans leur sous-entrepôt');
        }
        if ($this->byLabel > 0) {
            $block[] = $this->countLine($this->byLabel, 'ligne(s) dont l\'emplacement avait été fusionné');
            $block[] = '          avec un homonyme, retrouvé par son libellé.';
        }
        if ($this->noLocation > 0) {
            $block[] = $this->countLine($this->noLocation, 'ligne(s) sans emplacement d\'origine,'
                .' versées dans l\'entrepôt principal');
        }
        if (!empty($this->deletedLocations)) {
            $ids = array_keys($this->deletedLocations);
            sort($ids);
            $block[] = $this->countLine(count($ids), 'emplacement(s) supprimé(s) dans l\'ancien ERP,'
                .' regroupés dans « À localiser » :');
            $block[] = '          numéros '.implode(', ', $ids).'.';
        }

        $this->appendBlock($lines, 'Emplacements :', $block);
    }

    /**
     * Les seuils de réapprovisionnement.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportThresholds(array &$lines)
    {
        $block = array();

        if ($this->alertSet > 0 || $this->desiredSet > 0) {
            $block[] = $this->countLine($this->alertSet, 'seuil(s) d\'alerte et '
                .$this->desiredSet.' stock(s) désiré(s) repris');
            $block[] = '          Écrits sur l\'article, pas par entrepôt : la source ne les donne';
            $block[] = '          que pour le dépôt principal, seul dépôt réel.';
        }
        if ($this->thresholdOnly > 0) {
            $block[] = $this->countLine($this->thresholdOnly, 'ligne(s) sans stock, reprises pour'
                .' leurs seuls seuils.');
            $block[] = '          N\'ayant pas de mouvement, elles sont retraitées à chaque passage';
            $block[] = '          et comptées en « mis à jour ». L\'écriture est idempotente.';
        }
        if (!empty($this->negativeThresholds)) {
            $block[] = $this->countLine(count($this->negativeThresholds), 'seuil(s) d\'alerte négatif(s)'
                .' ramené(s) à zéro : '.implode(', ', array_values($this->negativeThresholds)));
        }

        $this->appendBlock($lines, 'Seuils de réapprovisionnement :', $block);
    }

    /**
     * Les lignes écartées.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportSkipped(array &$lines)
    {
        $block = array();

        if (!empty($this->productNotInSource)) {
            $refs = array_keys($this->productNotInSource);
            $block[] = $this->countLine(count($refs), 'référence(s) absente(s) de f_article : '
                .implode(', ', array_slice($refs, 0, 12)).(count($refs) > 12 ? '…' : ''));
        }
        if ($this->productNotMigrated > 0) {
            $block[] = $this->countLine($this->productNotMigrated, 'ligne(s) dont l\'article n\'est'
                .' pas encore repris');
            $block[] = '          lancez « migrate.php product » en entier avant celui-ci.';
        }
        if ($this->unmanaged > 0) {
            $block[] = $this->countLine($this->unmanaged, 'ligne(s) d\'articles non suivis en stock'
                .' dans la source');
        }
        if (!empty($this->serviceProducts)) {
            $block[] = $this->countLine(count($this->serviceProducts), 'article(s) de type « service »'
                .' porteurs de stock :');
            foreach ($this->serviceProducts as $ref => $qty) {
                $block[] = '            '.str_pad($ref, 12).(int) $qty.' unité(s)';
            }
            $block[] = '          Dolibarr ne déplace pas le stock d\'un service. Passez-les en type';
            $block[] = '          « Produit » puis relancez, ou renoncez à ce stock.';
        }

        $this->appendBlock($lines, 'Lignes non reprises :', $block);
    }

    /**
     * Ce que le script ne traite pas, et qu'il ne faut pas croire repris.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportOutOfScope(array &$lines)
    {
        $block = array();

        foreach ($this->discarded as $motif => $info) {
            $suffix = ($info['qte'] != 0) ? ', cumul '.(int) $info['qte'].' unité(s)' : '';
            $block[] = $this->countLine($info['nb'], 'ligne(s) — '.$motif.$suffix);
        }

        if (!empty($block)) {
            $block[] = '';
            $block[] = '          Le stock en dépôt-vente demande un modèle de gestion à part —';
            $block[] = '          un entrepôt par dépositaire — et reste à arbitrer (ANOMALIES A8).';
            $block[] = '          Aucun historique de mouvement n\'est rejoué : il reste dans l\'ancien ERP.';
        }

        $this->appendBlock($lines, 'Hors périmètre :', $block);
    }

    /**
     * Ligne de rapport alignée sur le format des autres scripts.
     *
     * @param int    $count Nombre
     * @param string $text  Libellé
     * @return string
     */
    protected function countLine($count, $text)
    {
        return '  '.str_pad((string) $count, 6, ' ', STR_PAD_LEFT).'  '.$text;
    }

    /**
     * Ajoute un bloc titré au rapport, s'il a du contenu.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @param string            $title Titre du bloc
     * @param array<int,string> $block Lignes du bloc
     * @return void
     */
    protected function appendBlock(array &$lines, $title, array $block)
    {
        if (empty($block)) {
            return;
        }

        if ($lines) {
            $lines[] = '';
        }
        $lines[] = $title;
        foreach ($block as $line) {
            $lines[] = $line;
        }
    }
}
