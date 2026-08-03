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
 *
 * ## Un seul entrepôt, et les emplacements ailleurs
 *
 * Une première version rangeait chaque ligne dans le sous-entrepôt correspondant à son
 * emplacement d'origine — sept cent dix-neuf entrepôts pour un dépôt réel. Le client ne
 * gère qu'un entrepôt : l'emplacement est pour lui un repère de rangement, pas une unité
 * de gestion de stock.
 *
 * Tout le stock va donc dans l'entrepôt principal, celui que la reprise des entrepôts a
 * marqué `SAGE:DEPOT1`. L'emplacement d'origine n'est pas perdu pour autant : le script
 * `productlocation` le porte sur la fiche produit, dans le dictionnaire des emplacements
 * d'aerotoolbox. Les deux informations sont séparées parce qu'elles répondent à deux
 * questions distinctes — **combien** pour l'entrepôt, **où** pour l'emplacement.
 *
 * ## Deux régimes, choisis ligne par ligne
 *
 * La cible n'est pas toujours vierge : Prestasync pousse dans Dolibarr le stock que la
 * boutique tient de l'ancien ERP. Poser la photo par-dessus le doublerait. Le script
 * regarde donc, pour chaque ligne, si le produit porte déjà du stock :
 *
 * - **rien en place** → mouvement d'ouverture, code `SAGE:OUVERTURE` ;
 * - **du stock ailleurs que dans l'entrepôt principal** → **transfert** vers celui-ci,
 *   code `SAGE:RELOCALISATION`, sans jamais toucher aux quantités ;
 * - **du stock déjà dans l'entrepôt principal** → rien, la ligne est comptée en place.
 *
 * Le choix est fait ligne par ligne et non par une option de ligne de commande : c'est ce
 * qui garantit qu'un lancement de trop ne double rien, quel que soit l'état de la cible.
 * Le régime de transfert garde tout son sens après l'abandon des sous-entrepôts : c'est
 * lui qui rapatrie le stock d'une instance déjà reprise dans l'ancien modèle.
 *
 * **Les quantités ne sont pas alignées sur la photo, et c'est une décision.** La quantité
 * en place vient du système vivant, la photo d'une copie datée ; les écarts constatés sont
 * des ventes et des réceptions postérieures. Le script les dénombre et cite les plus gros,
 * sans les corriger — voir ANOMALIES.md S11.
 *
 * Un transfert est une **paire** de mouvements de types 0 et 1, comme le fait l'écran natif
 * de déplacement en masse (massstockmove.php:230) : ce n'est ni une entrée ni une sortie de
 * l'entreprise, et les types 3 et 2 y donneraient un contresens comptable. Le prix n'est
 * porté que sur l'entrée dans l'entrepôt de destination, ce qui **valorise le stock au
 * passage** : les mouvements de Prestasync ayant un prix nul, le coût moyen était resté à
 * zéro, et `_create()` prend `$newpmp = $price` dès lors que l'ancien est nul (ligne 588).
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

    /**
     * Base où lire la source.
     *
     * L'export intégral de l'éditeur, chargé à part, est plus récent que les tables
     * importées lors des premiers travaux : 1 401 quantités y diffèrent et 97 articles s'y
     * ajoutent. Lire l'ancienne copie poserait un stock périmé, et incohérent avec les
     * emplacements que `productlocation` tire de la même base. Modifiable par --source-db.
     *
     * @var string
     */
    public $sourceDb = 'aeroprod';

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

    /** @var string Code d'inventaire des transferts, quand le stock était déjà en place */
    protected $relocationKey = 'RELOCALISATION';

    /** @var string Code d'inventaire des contre-passations, posé par la purge */
    protected $cancelKey = 'ANNULATION';

    /** @var int Nombre d'écarts de quantité cités nominativement au rapport */
    const GAPS_LISTED = 10;

    /**
     * Table rase : vider tout le stock plutôt que défaire la seule reprise.
     *
     * Posée par l'option `--all` du lanceur de purge. À n'employer qu'avant la mise en
     * service : elle efface aussi ce que d'autres canaux ont posé.
     *
     * @var bool
     */
    public $purgeAll = false;

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,array{id:int,ref:string,type:int}> AR_Ref -> produit Dolibarr */
    protected $productBySage = array();

    /** @var int rowid de l'entrepôt principal, seule destination du stock repris */
    protected $mainWarehouseId = 0;

    /** @var string Référence de cet entrepôt, pour le rapport */
    protected $mainWarehouseRef = '';

    /** @var array<string,float> AR_Ref -> coût de valorisation retenu */
    protected $costBySage = array();

    /** @var array<string,bool> Articles valorisés par le prix de revient, faute de coût standard */
    protected $costIsFallback = array();

    /** @var array<string,bool> Articles que la source ne suit pas en stock */
    protected $unmanagedArticles = array();

    /** @var array<string,bool> Références présentes dans f_article */
    protected $sourceArticles = array();

    /**
     * Stock déjà en place à l'ouverture du passage.
     *
     * Préchargé en une requête, comme les autres référentiels : interroger la base par ligne
     * ferait la différence entre quelques minutes et plusieurs heures. L'index est tenu à
     * jour au fil des écritures, sans quoi deux lignes visant le même produit relocaliseraient
     * deux fois la même quantité.
     *
     * @var array<int,array<int,float>> rowid produit -> rowid entrepôt -> quantité
     */
    protected $stockByProduct = array();

    /** @var array<int,string> rowid d'entrepôt -> libellé, pour les libellés de transfert */
    protected $warehouseRefById = array();

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

    /** @var int Lignes reprises sans aucune valorisation possible */
    protected $withoutCost = 0;

    /** @var int Lignes valorisées par le prix de revient, faute de coût standard */
    protected $costFromFallback = 0;

    /** @var int Lignes dont le stock était déjà en place et a été relocalisé */
    protected $relocatedLines = 0;

    /** @var float Unités déplacées vers leur emplacement */
    protected $relocatedUnits = 0;

    /** @var int Mouvements de transfert écrits — deux par entrepôt d'origine */
    protected $relocatedMovements = 0;

    /** @var int Lignes dont le stock était déjà au bon endroit */
    protected $alreadyPlaced = 0;

    /** @var int Lignes dont la quantité en place diffère de la photo */
    protected $gapLines = 0;

    /** @var float Cumul signé des écarts, en place moins photo */
    protected $gapUnits = 0;

    /** @var array<int,array{ref:string,sage:float,current:float}> Les plus gros écarts */
    protected $gapSamples = array();

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
     * Produits que ce passage a pris en charge.
     *
     * Sert au seul bloc « Stock resté ailleurs », qui doit dire ce qui subsistera **après**
     * le passage et non ce qui traîne avant. En simulation, rien n'ayant encore bougé, la
     * requête compterait sinon comme resté sur place tout ce que la reprise s'apprête à
     * rapatrier — soit la quasi-totalité du stock sur une instance déjà reprise.
     *
     * @var array<int,bool>
     */
    protected $handledProducts = array();

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
            'loadArticleIndex',
            'loadStockIndex',
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
     * Index des entrepôts et repérage de l'entrepôt principal.
     *
     * Tous les entrepôts sont lus, mais seul le principal reçoit du stock. Les autres ne
     * servent qu'à nommer l'origine d'un transfert au rapport : sur une instance déjà
     * reprise dans l'ancien modèle, le stock à rapatrier vient de sept cents sous-entrepôts
     * dont il faut pouvoir citer le nom.
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

        $mainKey = $this->buildRefExt('DEPOT1');

        while ($obj = $this->db->fetch_object($resql)) {
            $this->warehouseRefById[(int) $obj->rowid] = (string) $obj->ref;

            if ((string) $obj->import_key === $mainKey) {
                $this->mainWarehouseId  = (int) $obj->rowid;
                $this->mainWarehouseRef = (string) $obj->ref;
            }
        }
        $this->db->free($resql);

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
     * Coût de valorisation et drapeau de suivi de stock, depuis l'article source.
     *
     * Le coût standard est retenu plutôt que `AS_PrixRU`, vide sur 75 % des lignes de
     * stock, et plutôt que `AS_CoutStd`, qui n'en est qu'une recopie.
     *
     * La règle est celle du script `product`, au repli près : coût standard, puis prix de
     * revient unitaire en dessous du centime. Le repli n'est pas cosmétique — 21 lignes de
     * stock n'ont pas de coût standard mais un prix de revient exploitable, soit 93 unités
     * et 14 443,92 € qui entreraient sinon avec un PMP nul. La valorisation du stock doit
     * coïncider avec le coût de revient des fiches produit, sans quoi les deux écrans se
     * contrediraient. Voir ANOMALIES.md, A7.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadArticleIndex()
    {
        $resql = $this->db->query('SELECT AR_Ref, AR_CoutStd, AR_PrixRU, AR_SuiviStock FROM '.$this->src('f_article'));
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $ref = trim((string) $obj->AR_Ref);
            $this->sourceArticles[$ref] = true;

            $cost = (float) $obj->AR_CoutStd;
            if ($cost < 0.01) {
                $cost = (float) $obj->AR_PrixRU;
                if ($cost >= 0.01) {
                    // Marqué, pas compté : le compteur du rapport doit refléter les lignes
                    // de stock réellement valorisées par le repli, non les articles.
                    $this->costIsFallback[$ref] = true;
                }
            }
            $this->costBySage[$ref] = ($cost >= 0.01) ? $cost : 0.0;

            if ((int) $obj->AR_SuiviStock === 0) {
                $this->unmanagedArticles[$ref] = true;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Stock déjà en place, par produit et par entrepôt.
     *
     * C'est cet index qui décide du régime de chaque ligne : mouvement d'ouverture si le
     * produit n'a rien, relocalisation sinon. La lecture est directe faute d'objet métier
     * pour `llx_product_stock` — aucune classe du coeur n'a `table_element = 'product_stock'`
     * —, mais elle reste une lecture : rien n'y est écrit hors de `MouvementStock`.
     *
     * Les lignes à zéro sont écartées : Dolibarr en laisse traîner après un transfert, et
     * elles ne représentent aucun stock à déplacer.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadStockIndex()
    {
        $sql  = 'SELECT ps.fk_product, ps.fk_entrepot, ps.reel';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_stock as ps';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot as e ON e.rowid = ps.fk_entrepot';
        $sql .= ' WHERE e.entity IN ('.getEntity('stock').')';
        $sql .= ' AND ps.reel <> 0';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->stockByProduct[(int) $obj->fk_product][(int) $obj->fk_entrepot] = (float) $obj->reel;
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
                ." FROM ".$this->src('f_artstock')." WHERE DE_No = 1 AND TRIM(AR_Ref) = ''",
            'dépôt 999, toutes à zéro' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM ".$this->src('f_artstock')." WHERE DE_No = 999",
            'dépôt non déclaré' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM ".$this->src('f_artstock')." WHERE DE_No NOT IN (1, 999)",
            'stock en dépôt-vente (f_consigne)' => "SELECT COUNT(*) as nb, COALESCE(SUM(AS_QteSto),0) as qte"
                ." FROM ".$this->src('f_consigne'),
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
     * Nombre de mouvements d'ouverture déjà posés.
     *
     * Comptés sur le code d'inventaire : `llx_stock_mouvement` n'a ni ref_ext, ni
     * import_key, ni entity.
     *
     * @return int Nombre de mouvements, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= " WHERE inventorycode = '".$this->db->escape($this->buildRefExt($this->inventoryKey))."'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
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
     * Toujours l'entrepôt principal. La méthode subsiste plutôt que d'écrire la propriété
     * partout : elle marque l'endroit où une ventilation redeviendrait possible si le
     * client ouvrait un second dépôt, et elle documente qu'il n'y en a pas aujourd'hui.
     *
     * L'emplacement d'origine, lui, n'est pas perdu : `productlocation` le porte sur la
     * fiche produit. Le mélanger au stock reviendrait à faire d'un rangement une écriture.
     *
     * @param stdClass $row Ligne source
     * @return int          rowid de l'entrepôt
     */
    protected function resolveWarehouse($row)
    {
        return $this->mainWarehouseId;
    }

    /**
     * Libellé du mouvement.
     *
     * Le code d'inventaire est constant sur toute la reprise ; le libellé dit d'où vient
     * la ligne. L'emplacement d'origine y figure encore, en clair : c'est la seule trace
     * qu'en garde le mouvement, et elle répond à la question que le client se posera
     * devant une ligne d'historique. Elle n'a aucune portée fonctionnelle — le rangement
     * courant se lit sur la fiche produit, où il peut changer sans réécrire l'histoire.
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

        // Dolibarr ne déplace pas le stock d'un service tant que STOCK_SUPPORTS_SERVICES
        // n'est pas activée, et l'API le tait : _create() retournerait 0 sans rien écrire.
        // Détecté ici plutôt que subi plus loin.
        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            $this->serviceProducts[$target['ref']] = $qty;
            return array('action' => 'skipped', 'id' => 0);
        }

        $this->handledProducts[(int) $target['id']] = true;

        // La destination est constante et vérifiée au démarrage : le test tient lieu de
        // garde, non de branche fonctionnelle.
        $warehouseId = $this->resolveWarehouse($row);
        if ($warehouseId <= 0) {
            throw new Exception('Entrepôt de destination introuvable :'
                .' lancez « migrate.php warehouse » avant celui-ci');
        }

        $cost = isset($this->costBySage[$ref]) ? (float) $this->costBySage[$ref] : 0;

        // Le produit porte déjà du stock, venu d'un autre canal : il ne s'agit plus de le
        // poser mais de le mettre au bon endroit. Testé avant la sortie « seuils seuls »,
        // car un produit peut avoir du stock en place alors que la photo l'annonce à zéro.
        $current = isset($this->stockByProduct[(int) $target['id']])
            ? $this->stockByProduct[(int) $target['id']]
            : array();
        if (array_sum($current) != 0) {
            return $this->relocateStock($target, $current, (int) $warehouseId, $cost, $qty, $mini, $maxi);
        }

        // Ligne venue pour ses seuls seuils : elle ne produit aucun mouvement, donc
        // n'entrera jamais dans l'index et sera retraitée à chaque passage. Sans danger.
        if ($qty == 0) {
            $this->applyThresholds($target, $mini, $maxi);
            $this->thresholdOnly++;
            return array('action' => 'updated', 'id' => (int) $target['id']);
        }

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
        } elseif (isset($this->costIsFallback[$ref])) {
            $this->costFromFallback++;
        }

        return array('action' => 'created', 'id' => (int) $target['id']);
    }

    /**
     * Déplace vers son emplacement un stock déjà en place.
     *
     * Aucune quantité n'est modifiée : ce qui est en base vient du système vivant, la photo
     * d'une copie datée. Les écarts sont relevés pour le rapport, jamais corrigés.
     *
     * L'idempotence ne repose sur aucun marqueur, mais sur la condition elle-même : au
     * passage suivant, il n'y a plus rien hors de l'emplacement de destination, et la
     * méthode ne fait rien. C'est plus solide qu'un index, qui ne dirait pas si quelqu'un a
     * déplacé du stock à la main entre-temps.
     *
     * @param array $target      Produit cible, tel que l'index le porte
     * @param array $current     Stock en place : rowid d'entrepôt -> quantité
     * @param int   $warehouseId Entrepôt de destination
     * @param float $cost        Coût de valorisation
     * @param float $sageQty     Quantité annoncée par la photo, pour le relevé d'écart
     * @param float $mini        Seuil d'alerte
     * @param float $maxi        Stock désiré
     * @return array{action:string,id:int}
     */
    protected function relocateStock(array $target, array $current, $warehouseId, $cost, $sageQty, $mini, $maxi)
    {
        $productId = (int) $target['id'];
        $sources   = 0;
        $units     = 0;

        foreach ($current as $sourceId => $qty) {
            $sourceId = (int) $sourceId;
            $qty      = (float) $qty;

            if ($sourceId === $warehouseId || $qty == 0) {
                continue;
            }

            // Le prix n'accompagne que l'entrée dans la destination, et seulement si elle
            // est positive : les types 1 et 2 ne touchent pas au coût moyen, et un mouvement
            // positif sur l'entrepôt d'origine — cas d'un stock négatif à déplacer — le
            // ferait à tort.
            $this->transfer($productId, $sourceId, -$qty, 0, $warehouseId);
            $this->transfer($productId, $warehouseId, $qty, ($qty > 0 ? $cost : 0), $warehouseId);

            // L'index suit l'écriture : sans cela, deux lignes visant le même produit
            // déplaceraient deux fois la même quantité.
            $this->stockByProduct[$productId][$sourceId] = 0;
            if (!isset($this->stockByProduct[$productId][$warehouseId])) {
                $this->stockByProduct[$productId][$warehouseId] = 0;
            }
            $this->stockByProduct[$productId][$warehouseId] += $qty;

            $sources++;
            $units += $qty;
        }

        $this->applyThresholds($target, $mini, $maxi);
        $this->noteGap($target['ref'], (float) $sageQty, array_sum($current));

        if ($sources === 0) {
            $this->alreadyPlaced++;
            return array('action' => 'skipped', 'id' => $productId);
        }

        $this->relocatedLines++;
        $this->relocatedUnits    += $units;
        $this->relocatedMovements += $sources * 2;

        return array('action' => 'updated', 'id' => $productId);
    }

    /**
     * Écrit une des deux moitiés d'un transfert.
     *
     * Les types 0 et 1 sont ceux du transfert, ceux-là mêmes que l'écran natif de
     * déplacement en masse emploie (massstockmove.php:230) : un déplacement entre entrepôts
     * n'est ni une entrée ni une sortie de l'entreprise. La convention de signe est celle du
     * coeur — `$op[0] = +n`, `$op[1] = -n` (product.class.php, `correct_stock()`) —, d'où le
     * type déduit du signe de la quantité.
     *
     * @param int   $productId   Produit
     * @param int   $warehouseId Entrepôt touché
     * @param float $qty         Quantité signée
     * @param float $price       Prix, pour le coût moyen
     * @param int   $targetId    Entrepôt de destination du transfert, pour le libellé
     * @return int               rowid du mouvement
     * @throws Exception         Si le coeur refuse le mouvement, ou n'écrit rien
     */
    protected function transfer($productId, $warehouseId, $qty, $price, $targetId)
    {
        $movement = new MouvementStock($this->db);
        $mvid = $movement->_create(
            $this->user,
            (int) $productId,
            (int) $warehouseId,
            (float) $qty,
            ($qty > 0) ? 0 : 1,
            (float) $price,
            $this->buildRelocationLabel($targetId),
            $this->buildRefExt($this->relocationKey),
            $this->resolveDate()
        );

        // <= 0 et non < 0 : _create() retourne 0, sans erreur, quand il n'a rien fait.
        if ($mvid <= 0) {
            throw new Exception('Transfert refusé (code '.$mvid.') : '.$this->objectErrors($movement));
        }

        return (int) $mvid;
    }

    /**
     * Libellé d'un mouvement de transfert.
     *
     * Il nomme la destination, seule information que le client n'a pas sous les yeux : la
     * colonne « Entrepôt » de la liste des mouvements donne déjà l'autre moitié.
     *
     * @param int $targetId Entrepôt de destination
     * @return string
     */
    protected function buildRelocationLabel($targetId)
    {
        $name = isset($this->warehouseRefById[(int) $targetId])
            ? $this->warehouseRefById[(int) $targetId]
            : ('entrepôt '.(int) $targetId);

        return 'Mise en place ADD — vers « '.$name.' »';
    }

    /**
     * Relève un écart entre la quantité en place et celle de la photo.
     *
     * Rien n'est corrigé : la décision est de ne pas aligner les quantités. Le relevé sert
     * à ce que le rapport le dise, et à donner au client de quoi contrôler par échantillon.
     *
     * @param string $ref     Référence du produit en cible
     * @param float  $sage    Quantité de la photo
     * @param float  $current Quantité en place
     * @return void
     */
    protected function noteGap($ref, $sage, $current)
    {
        $gap = $current - $sage;
        if (abs($gap) < 0.001) {
            return;
        }

        $this->gapLines++;
        $this->gapUnits += $gap;

        // Les plus gros écarts en valeur absolue, pour un rapport de taille bornée.
        $this->gapSamples[] = array('ref' => (string) $ref, 'sage' => (float) $sage, 'current' => (float) $current);
        if (count($this->gapSamples) > self::GAPS_LISTED * 4) {
            $this->trimGapSamples(self::GAPS_LISTED);
        }
    }

    /**
     * Ne conserve que les plus gros écarts relevés.
     *
     * @param int $keep Nombre d'écarts à garder
     * @return void
     */
    protected function trimGapSamples($keep)
    {
        usort($this->gapSamples, function ($a, $b) {
            $ga = abs($a['current'] - $a['sage']);
            $gb = abs($b['current'] - $b['sage']);
            if ($ga === $gb) {
                return 0;
            }
            return ($ga < $gb) ? 1 : -1;
        });

        $this->gapSamples = array_slice($this->gapSamples, 0, (int) $keep);
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

        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            $this->serviceProducts[$target['ref']] = $qty;
            return;
        }

        $this->handledProducts[(int) $target['id']] = true;

        $warehouseId = $this->resolveWarehouse($row);
        if ($warehouseId <= 0) {
            throw new Exception('Entrepôt de destination introuvable');
        }

        $cost = isset($this->costBySage[$ref]) ? (float) $this->costBySage[$ref] : 0;

        // Même arbitrage que la reprise réelle : relocalisation si du stock est en place.
        $current = isset($this->stockByProduct[(int) $target['id']])
            ? $this->stockByProduct[(int) $target['id']]
            : array();
        if (array_sum($current) != 0) {
            $this->previewRelocation($target, $current, (int) $warehouseId, $qty);
            return;
        }

        if ($qty == 0) {
            $this->thresholdOnly++;
            return;
        }

        $this->movedLines++;
        $this->movedUnits += $qty;
        $this->movedValue += $qty * $cost;
        if ($qty < 0) {
            $this->negativeLines++;
            $this->negativeUnits += $qty;
        }
        if ($cost <= 0) {
            $this->withoutCost++;
        } elseif (isset($this->costIsFallback[$ref])) {
            $this->costFromFallback++;
        }
    }

    /**
     * Dénombre en simulation ce qu'une relocalisation déplacerait.
     *
     * @param array $target      Produit cible
     * @param array $current     Stock en place : rowid d'entrepôt -> quantité
     * @param int   $warehouseId Entrepôt de destination
     * @param float $sageQty     Quantité annoncée par la photo
     * @return void
     */
    protected function previewRelocation(array $target, array $current, $warehouseId, $sageQty)
    {
        $sources = 0;
        $units   = 0;

        foreach ($current as $sourceId => $qty) {
            if ((int) $sourceId === (int) $warehouseId || (float) $qty == 0) {
                continue;
            }
            $sources++;
            $units += (float) $qty;
        }

        $this->noteGap($target['ref'], (float) $sageQty, array_sum($current));

        if ($sources === 0) {
            $this->alreadyPlaced++;
            return;
        }

        $this->relocatedLines++;
        $this->relocatedUnits     += $units;
        $this->relocatedMovements += $sources * 2;
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
        if ($existingId > 0) {
            return 'updated';
        }

        $target = $this->productBySage[$ref];
        if ($target['type'] !== Product::TYPE_PRODUCT && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
            return 'skipped';
        }

        // Du stock en place : relocalisation, ou rien à faire s'il est déjà au bon endroit.
        $current = isset($this->stockByProduct[(int) $target['id']])
            ? $this->stockByProduct[(int) $target['id']]
            : array();
        if (array_sum($current) != 0) {
            $warehouseId = $this->resolveWarehouse($row);
            foreach ($current as $sourceId => $qty) {
                if ((int) $sourceId !== (int) $warehouseId && (float) $qty != 0) {
                    return 'updated';
                }
            }
            return 'skipped';
        }

        if ((float) $row->AS_QteSto == 0) {
            return 'updated';
        }

        return 'created';
    }

    /**
     * Table rase : vide tout le stock, quelle qu'en soit l'origine.
     *
     * La purge ordinaire ne défait que ce que la reprise a posé, en contre-passant ses
     * mouvements. Elle ne peut rien contre un stock venu d'ailleurs — celui que la boutique
     * a poussé, par exemple — faute de savoir quoi contre-passer.
     *
     * Or l'arbitrage a changé de sens : l'export de l'ancien ERP s'est révélé plus récent
     * que l'état de Dolibarr. Ce n'est donc plus la base qu'il faut préserver mais la
     * source qu'il faut imposer, et le plus sûr est de repartir de zéro plutôt que de
     * réconcilier des milliers d'écarts un à un.
     *
     * L'écriture est directe, et c'est assumé : aucune classe du coeur ne porte
     * `table_element = 'product_stock'`, et contre-passer des mouvements dont on ignore
     * l'origine n'aurait aucun sens. Ce n'est pas une reprise de données mais une remise à
     * zéro d'environnement, réservée à l'avant-mise en service.
     *
     * @param  bool          $confirm  Faux pour un simple décompte
     * @param  callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array}
     */
    protected function purgeEverything($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $resql = $this->db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'stock_mouvement');
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $result['count'] = (int) $obj->nb;

        if (!$confirm) {
            return $result;
        }

        $this->db->begin();

        // Les lots avant les lignes de stock qu'ils référencent, les lignes avant les
        // produits qu'elles dénormalisent.
        $steps = array(
            'DELETE FROM '.MAIN_DB_PREFIX.'product_batch',
            'DELETE FROM '.MAIN_DB_PREFIX.'stock_mouvement',
            'DELETE FROM '.MAIN_DB_PREFIX.'product_stock',
            // Le coût moyen part avec le stock : le conserver ferait valoriser à un prix
            // qu'aucun mouvement ne justifie plus. La reprise le reposera.
            'UPDATE '.MAIN_DB_PREFIX.'product SET stock = 0, pmp = 0'
                .' WHERE entity IN ('.getEntity('product').')',
        );

        foreach ($steps as $sql) {
            if (!$this->db->query($sql)) {
                $this->db->rollback();
                $result['failed']   = $result['count'];
                $result['errors'][] = $this->db->lasterror();
                return $result;
            }
        }

        $this->db->commit();

        $result['deleted'] = $result['count'];

        if (is_callable($progress)) {
            call_user_func($progress, $result['deleted'], $result['count']);
        }

        return $result;
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        if ($this->purgeAll) {
            $out  = "TABLE RASE : vide la TOTALITÉ du stock, quelle qu'en soit l'origine.

";
            $out .= "Sont effacés les mouvements, les lignes de stock, les lots, et remis à zéro
";
            $out .= "le stock et le coût moyen de chaque produit. Ce que la boutique a poussé
";
            $out .= "disparaît aussi : il n'y a pas de tri.

";
            $out .= "À n'employer qu'avant la mise en service, quand la source est plus récente
";
            $out .= "que la cible et qu'on préfère la réimposer entièrement plutôt que de
";
            $out .= "réconcilier les écarts un à un.

";
            $out .= "Les entrepôts ne sont pas touchés. Relancez « migrate.php stock » derrière.";

            return $out;
        }

        return 'Contre-passation des mouvements de stock d\'ouverture et de mise en place'
            .' (codes d\'inventaire « '.$this->buildRefExt($this->inventoryKey).' » et « '
            .$this->buildRefExt($this->relocationKey).' »), suppression des lignes de'
            .' mouvement, puis remise à zéro des seuils posés par la reprise';
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

        if ($this->purgeAll) {
            return $this->purgeEverything($confirm, $progress);
        }

        // Les deux régimes sont défaits, et dans l'ordre inverse de leur écriture : la
        // seconde moitié d'un transfert doit être annulée avant la première, sans quoi le
        // stock passerait par un état négatif au milieu de l'opération.
        $codes = array(
            $this->buildRefExt($this->inventoryKey),
            $this->buildRefExt($this->relocationKey),
        );
        foreach ($codes as $i => $code) {
            $codes[$i] = "'".$this->db->escape($code)."'";
        }

        $sql  = 'SELECT rowid, fk_product, fk_entrepot, value, type_mouvement';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement';
        $sql .= ' WHERE inventorycode IN ('.implode(', ', $codes).')';
        $sql .= ' ORDER BY rowid DESC';

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
        $qty = (float) $row->value;

        // L'inverse d'un mouvement reste dans sa famille : 3 et 2 sont l'entrée et la sortie
        // de stock, 0 et 1 les deux moitiés d'un transfert. Contre-passer une entrée par une
        // sortie de transfert donnerait un historique incompréhensible.
        $inverse = array(0 => 1, 1 => 0, 2 => 3, 3 => 2);
        $current = (int) $row->type_mouvement;
        $type    = isset($inverse[$current]) ? $inverse[$current] : 2;

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
        $this->reportRelocation($lines);
        $this->reportLocations($lines);
        $this->reportResidual($lines);
        $this->reportThresholds($lines);
        $this->reportSkipped($lines);
        $this->reportOutOfScope($lines);

        return $lines;
    }

    /**
     * Le stock resté hors de l'entrepôt principal.
     *
     * Le rapatriement ne porte que sur les produits que le script a lus, et le filtre de
     * lecture écarte les lignes entièrement à zéro. Un article vendu depuis la copie de la
     * source s'y trouve donc à zéro alors qu'il porte encore du stock en base : le script
     * ne le voit pas, et ses unités restent où elles sont.
     *
     * Le cas est rare mais il bloque : Dolibarr refuse de supprimer un entrepôt qui porte
     * du stock, et c'est précisément ce qu'on cherche à faire des anciens sous-entrepôts.
     * Mieux vaut le lire ici que le découvrir à la suppression.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportResidual(array &$lines)
    {
        $sql  = 'SELECT e.ref, COUNT(*) as lignes, SUM(ps.reel) as unites';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_stock as ps';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot as e ON e.rowid = ps.fk_entrepot';
        $sql .= ' WHERE e.entity IN ('.getEntity('stock').')';
        $sql .= ' AND ps.fk_entrepot <> '.((int) $this->mainWarehouseId);
        $sql .= ' AND ps.reel <> 0';

        // Ce que le passage rapatrie ne « reste » pas : on l'ôte du décompte, sans quoi la
        // simulation annoncerait comme perdu tout le stock qu'elle s'apprête à déplacer.
        if (!empty($this->handledProducts)) {
            $sql .= ' AND ps.fk_product NOT IN ('.implode(',', array_map('intval', array_keys($this->handledProducts))).')';
        }
        $sql .= ' GROUP BY e.ref ORDER BY ABS(SUM(ps.reel)) DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return;
        }

        $block  = array();
        $shown  = 0;
        $others = 0;
        $totalLines = 0;
        $totalUnits = 0;

        while ($obj = $this->db->fetch_object($resql)) {
            $totalLines += (int) $obj->lignes;
            $totalUnits += (float) $obj->unites;
            if ($shown < 10) {
                $block[] = '          '.str_pad(dol_trunc((string) $obj->ref, 28), 30)
                    .$obj->lignes.' ligne(s), '.((float) $obj->unites).' unité(s)';
                $shown++;
            } else {
                $others++;
            }
        }
        $this->db->free($resql);

        if ($totalLines === 0) {
            return;
        }

        if ($others > 0) {
            $block[] = '          … et '.$others.' autre(s) entrepôt(s)';
        }

        // Le détail par produit, et non seulement par entrepôt : c'est lui qui permet de
        // trancher. Reproduire ce filtre en SQL à la main est illusoire — le script écarte
        // aussi les articles que la source ne suit pas en stock, ceux qu'il n'a pas
        // retrouvés et les services, qu'aucune requête sur f_artstock ne distingue.
        $sql  = 'SELECT p.ref, e.ref as entrepot, ps.reel';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_stock as ps';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot as e ON e.rowid = ps.fk_entrepot';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = ps.fk_product';
        $sql .= ' WHERE e.entity IN ('.getEntity('stock').')';
        $sql .= ' AND ps.fk_entrepot <> '.((int) $this->mainWarehouseId);
        $sql .= ' AND ps.reel <> 0';
        if (!empty($this->handledProducts)) {
            $sql .= ' AND ps.fk_product NOT IN ('.implode(',', array_map('intval', array_keys($this->handledProducts))).')';
        }
        $sql .= ' ORDER BY ABS(ps.reel) DESC';
        $sql .= $this->db->plimit(self::GAPS_LISTED, 0);

        $resql = $this->db->query($sql);
        if ($resql) {
            $detail = array();
            while ($obj = $this->db->fetch_object($resql)) {
                $detail[] = '            '.str_pad((string) $obj->ref, 12)
                    .str_pad(dol_trunc((string) $obj->entrepot, 24), 26)
                    .((float) $obj->reel).' unité(s)';
            }
            $this->db->free($resql);

            if (!empty($detail)) {
                $block[] = '';
                $block[] = '          Les plus grosses lignes :';
                foreach ($detail as $line) {
                    $block[] = $line;
                }
            }
        }

        $block[] = '';
        $block[] = '          Ces articles n\'ont pas été pris en charge : leur ligne source est à';
        $block[] = '          zéro, ou l\'ancien ERP ne les suit pas en stock, ou ils ne sont pas';
        $block[] = '          repris — alors qu\'ils portent du stock en base.';
        $block[] = '          Supprimer ces entrepôts ferait DISPARAÎTRE ce stock : Entrepot::delete()';
        $block[] = '          efface product_stock et stock_mouvement sans vérifier ni avertir.';
        $block[] = '          Déplacez-le à la main si vous y tenez, avant toute suppression.';

        array_unshift(
            $block,
            $this->countLine($totalLines, 'ligne(s) de stock hors de l\'entrepôt principal,'
                .' cumul '.((float) $totalUnits).' unité(s)')
        );

        $this->appendBlock($lines, 'Stock resté ailleurs :', $block);
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
            .price($this->movedValue, 0, '', 1, 2).' € au coût de revient');
        $block[] = '          Code d\'inventaire « '.$this->buildRefExt($this->inventoryKey).' » :';
        $block[] = '          filtrez dessus dans Produits > Stocks > Mouvements pour';
        $block[] = '          retrouver la reprise en entier.';

        if ($this->negativeLines > 0) {
            $block[] = $this->countLine($this->negativeLines, 'ligne(s) de quantité négative, cumul '
                .(int) $this->negativeUnits.', reprises telles quelles');
        }
        if ($this->costFromFallback > 0) {
            $block[] = $this->countLine($this->costFromFallback, 'ligne(s) valorisée(s) au prix de '
                .'revient, faute de coût standard');
        }
        if ($this->withoutCost > 0) {
            $block[] = $this->countLine($this->withoutCost, 'ligne(s) sans aucune valorisation : '
                .'mouvement posé sans prix, le coût moyen reste nul');
        }

        $this->appendBlock($lines, 'Stock d\'ouverture :', $block);
    }

    /**
     * Le stock qui était déjà en place.
     *
     * Bloc silencieux sur une cible vierge : sans stock préexistant, il n'a rien à dire et
     * n'encombre pas le rapport.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportRelocation(array &$lines)
    {
        if ($this->relocatedLines === 0 && $this->alreadyPlaced === 0) {
            return;
        }

        $block = array();

        if ($this->relocatedLines > 0) {
            $block[] = $this->countLine($this->relocatedLines, 'article(s) dont le stock était déjà'
                .' en place, mis dans leur emplacement');
            $block[] = $this->countLine((int) $this->relocatedUnits, 'unité(s) déplacée(s) en '
                .$this->relocatedMovements.' mouvement(s) de transfert');
            $block[] = '          Code d\'inventaire « '.$this->buildRefExt($this->relocationKey).' ».';
            $block[] = '          Aucune quantité n\'est modifiée : seul l\'emplacement change.';
        }
        if ($this->alreadyPlaced > 0) {
            $block[] = $this->countLine($this->alreadyPlaced, 'article(s) déjà au bon endroit,'
                .' rien à déplacer');
            $block[] = '          Un transfert ne laisse pas de trace dans l\'index de reprise,';
            $block[] = '          qui ne connaît que les mouvements d\'ouverture : ces lignes';
            $block[] = '          sont donc relues à chaque passage. Sans conséquence, la';
            $block[] = '          condition de déplacement n\'étant plus remplie.';
        }

        if ($this->gapLines > 0) {
            $this->trimGapSamples(self::GAPS_LISTED);

            $block[] = '';
            $block[] = $this->countLine($this->gapLines, 'article(s) dont la quantité en place'
                .' diffère de la source, écart net '
                .($this->gapUnits > 0 ? '+' : '').(int) $this->gapUnits.' unité(s)');
            $block[] = '          Volontairement NON corrigés, et à arbitrer article par article';
            $block[] = '          plutôt qu\'en masse : un gros écart relève le plus souvent d\'un';
            $block[] = '          article que la source ne suit pas en stock — son zéro ne veut';
            $block[] = '          pas dire « rien », mais « je ne compte pas » — ou d\'une dérive';
            $block[] = '          de la boutique. Les petits écarts sont le décalage normal entre';
            $block[] = '          la copie de la source et l\'état de la cible.';

            foreach ($this->gapSamples as $gap) {
                $delta = $gap['current'] - $gap['sage'];
                $block[] = '            '.str_pad($gap['ref'], 12)
                    .'source '.str_pad((string) (int) $gap['sage'], 7, ' ', STR_PAD_LEFT)
                    .'   en place '.str_pad((string) (int) $gap['current'], 7, ' ', STR_PAD_LEFT)
                    .'   ('.($delta > 0 ? '+' : '').(int) $delta.')';
            }
            if ($this->gapLines > count($this->gapSamples)) {
                $block[] = '          … et '.($this->gapLines - count($this->gapSamples)).' autre(s),'
                    .' les plus gros écarts étant cités ici.';
            }
        }

        $this->appendBlock($lines, 'Stock déjà en place :', $block);
    }

    /**
     * La destination du stock, et le rappel de ce qui se passe ailleurs.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportLocations(array &$lines)
    {
        $block = array();

        $block[] = '          Tout le stock est posé dans « '.$this->mainWarehouseRef.' »'
            .' (#'.$this->mainWarehouseId.').';
        $block[] = '          L\'emplacement d\'origine ne devient pas un entrepôt : il est porté sur';
        $block[] = '          la fiche produit par « migrate.php productlocation ». Le mouvement en';
        $block[] = '          garde la trace dans son libellé, à titre d\'historique.';

        $this->appendBlock($lines, 'Destination :', $block);
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
