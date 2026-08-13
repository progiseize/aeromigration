<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationsupplierprice.class.php
 * \ingroup aeromigration
 * \brief   Reprise des tarifs fournisseurs : f_artfourniss -> llx_product_fournisseur_price.
 *
 * Chaque ligne source associe un article à un fournisseur, avec la référence que celui-ci
 * lui donne et le prix auquel il le vend. Les deux extrémités sont retrouvées par leur
 * `ref_ext` — « SAGE:<AR_Ref> » pour l'article, « SAGE:<CT_Num> » pour le tiers —, jamais
 * par leur référence ni par leur code : les produits portent une référence reformatée
 * (« #00001 ») et les tiers un code régénéré par Dolibarr (« SU2607-00001 »).
 *
 * Deux particularités de la cible commandent toute la conception :
 *
 * - **L'identité d'une ligne tarifaire est (ref_fourn, fk_soc, quantity)**, et non le
 *   couple produit/fournisseur : `uk_product_fournisseur_price_ref`. Dolibarr postule
 *   qu'une référence désigne un seul article chez un fournisseur donné. La source ne
 *   respecte pas ce postulat — 1 033 lignes partagent une référence, 2 128 n'en ont
 *   aucune. Or `update_buyprice()` appelé sans identifiant de ligne SUPPRIME la place
 *   occupée avant d'insérer : sans traitement, près de 2 600 lignes disparaîtraient sans
 *   la moindre erreur. Voir composeRef() et resolveRef().
 *
 * - **Le module multidevise est actif**, donc `update_buyprice()` exécute toujours
 *   `$buyprice = $multicurrency_buyprice / $multicurrency_tx`. Le prix en euros n'est
 *   jamais celui qu'on fournit : il est recalculé. Ne pas renseigner le côté multidevise
 *   écrirait un prix nul. Voir resolveCurrency().
 *
 * La table cible n'ayant pas de `ref_ext`, l'idempotence passe par `import_key`, écrite
 * via l'API `ProductFournisseurPrice` et non par une requête directe.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/productfournisseurprice.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationSupplierPrice extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'supplierprice';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptSupplierPrice';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_artfourniss';

    /**
     * Colonnes lues.
     *
     * La liste est explicite plutôt que « * » : la table porte des colonnes techniques
     * `varbinary` et dix `id_externe` sans emploi, qu'on éviterait de charger 15 962 fois.
     *
     * @var string
     */
    protected $srcFields = 'cbMarq, AR_Ref, CT_Num, AF_RefFourniss, designation_fournisseur,'
        .' AF_PrixAch, AF_PrixDev, AF_Devise, AF_QteMini, AF_DelaiAppro,'
        .' AF_Remise, AF_Principal, AF_CodeBarre';

    /**
     * Colonne de parcours : ici cbMarq est bien auto-incrémentée et sans doublon
     * (15 962 valeurs distinctes de 1 à 17 025), contrairement à d'autres tables Sage.
     *
     * @var string
     */
    protected $srcCursorField = 'cbMarq';

    /** @var string Le curseur est un entier */
    protected $srcCursorType = 'int';

    /**
     * Clé naturelle : LA LIGNE, pas le couple (article, fournisseur).
     *
     * Quatre couples portent deux références distinctes chez le même fournisseur —
     * l'article 11938 chez F22 est référencé « 3424 » à 35,45 € et « 3424W » à 27,99 €.
     * Ce sont des doubles référencements légitimes, que Dolibarr sait représenter par deux
     * lignes. Une clé fondée sur le couple les confondrait et ferait écraser l'une par
     * l'autre à chaque passage. Le triplet (AR_Ref, CT_Num, AF_RefFourniss) n'a lui aucun
     * doublon, mais cbMarq est plus simple et tout aussi sûr.
     *
     * @var string
     */
    protected $srcKeyField = 'cbMarq';

    /** @var string Trois lignes n'ont aucun fournisseur : elles ne sont rattachables à rien */
    protected $srcWhere = "TRIM(AR_Ref) <> '' AND TRIM(CT_Num) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'product_fournisseur_price';

    /** @var string Élément Dolibarr, celui qu'emploie le coeur pour cette table */
    protected $dstElement = 'productsupplierprice';

    /**
     * Quantité du palier tarifaire.
     *
     * Toujours 1 : le prix de la source est unitaire. Reprendre AF_QteMini comme palier
     * priverait le produit de tout prix en dessous du seuil, find_min_price_product_
     * fournisseur() ne retenant que les lignes dont la quantité est inférieure ou égale à
     * celle commandée. Le conditionnement est repris à part, dans `packaging`.
     */
    const QUANTITY = 1;

    /**
     * Écart toléré entre le taux implicite de la source et celui de l'instance.
     *
     * Au-delà, la valeur de la source est jugée fausse et le taux officiel prend le relais.
     */
    const RATE_TOLERANCE = 0.20;

    /**
     * Devises de la source.
     *
     * Même codification que sur les tiers. `1` désigne bien l'euro : sur les 74 lignes
     * concernées portant les deux prix, 68 ont AF_PrixDev égal à AF_PrixAch. NULL et 0 sont
     * des non-renseignés, traités en euros également.
     *
     * @var array<int,string>
     */
    protected $currencyAliases = array(
        1  => 'EUR',
        2  => 'USD',
        4  => 'GBP',
        11 => 'CAD',
    );

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,array{id:int,ref:string,vat:float}> AR_Ref -> produit Dolibarr */
    protected $productBySage = array();

    /** @var array<string,int> CT_Num -> rowid du tiers */
    protected $supplierBySage = array();

    /** @var array<int,Societe> Tiers déjà chargés, pour ne pas les relire à chaque ligne */
    protected $supplierCache = array();

    /** @var array<string,bool> Références présentes dans f_article */
    protected $sourceArticles = array();

    /** @var array<string,bool> Codes tiers présents dans f_comptet */
    protected $sourceSuppliers = array();

    /** @var array<string,bool> Références fournisseur partagées par plusieurs articles */
    protected $collidingRefs = array();

    /** @var array<string,array{rowid:int,product:int}> Lignes tarifaires déjà en base */
    protected $targetLines = array();

    /** @var array<string,float> Code devise -> taux de l'instance */
    protected $currencyRates = array();

    /** @var int Identifiant du type de code-barres EAN13 */
    protected $barcodeTypeId = 0;

    /** @var int Lignes écartées à la lecture faute de fournisseur */
    protected $emptySupplierRows = 0;

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Lignes dont l'article n'est pas encore repris dans Dolibarr */
    protected $productNotMigrated = 0;

    /** @var array<string,bool> Références absentes de f_article */
    protected $productNotInSource = array();

    /** @var int Lignes dont le tiers n'est pas encore repris */
    protected $supplierNotMigrated = 0;

    /** @var array<string,bool> Codes tiers absents de f_comptet */
    protected $supplierNotInSource = array();

    /** @var int Références reprises telles quelles */
    protected $refKept = 0;

    /** @var int Références suffixées par la référence produit, collision dans la source */
    protected $refSuffixed = 0;

    /** @var int Références absentes ou non significatives, remplacées par celle du produit */
    protected $refFromProduct = 0;

    /** @var int Références tronquées à la capacité de la colonne */
    protected $refTruncated = 0;

    /** @var int Collisions détectées en base et non dans la source */
    protected $refConflicts = 0;

    /** @var int Lignes sans prix d'achat exploitable */
    protected $priceMissing = 0;

    /** @var array<string,int> Devises dont le taux de la source a été conservé */
    protected $rateKept = array();

    /** @var array<string,int> Devises dont le taux a été remplacé par celui de l'instance */
    protected $rateForced = array();

    /** @var int Lignes en devise étrangère sans montant en devise, basculées en euros */
    protected $currencyWithoutAmount = 0;

    /** @var int Lignes en devise étrangère sans prix en euros */
    protected $currencyWithoutEuro = 0;

    /** @var array<string,bool> Devises de la source absentes de llx_multicurrency */
    protected $currencyUnknown = array();

    /** @var int Conditionnements repris */
    protected $packagingSet = 0;

    /** @var int Délais d'approvisionnement repris */
    protected $delaySet = 0;

    /** @var int Remises reprises */
    protected $remiseSet = 0;

    /** @var int Lignes marquées « fournisseur principal » */
    protected $favoriteSet = 0;

    /** @var int Descriptions fournisseur reprises */
    protected $descSet = 0;

    /** @var int Codes-barres repris */
    protected $barcodeSet = 0;

    /** @var int Lignes dont le produit ne porte aucun taux de TVA */
    protected $vatDefaulted = 0;

    /** @var int Lignes tarifaires préexistantes, complétées sans être revendiquées */
    protected $adoptedLines = 0;

    /** @var array<string,array{ref:string,buy:float,sell:float}> Achats au-dessus du prix de vente */
    protected $aboveSellPrice = array();

    /** @var float Taux appliqué lorsque le produit n'en porte aucun */
    protected $defaultVat = 20;

    /**
     * Charge tout ce dont le mapping a besoin.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        foreach (array(
            'loadSourceKeys',
            'loadProductIndex',
            'loadSupplierIndex',
            'loadTargetLines',
            'loadCurrencyRates',
            'loadBarcodeType',
            'loadRefCollisions',
            'countDiscardedRows',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Recense les clés réellement présentes dans les tables sources.
     *
     * Sert à distinguer deux situations que les compteurs confondraient : une clé absente
     * de la source — anomalie définitive, rien ne la résorbera — et une clé présente mais
     * pas encore reprise dans Dolibarr, qui relève simplement de l'ordre des passages.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadSourceKeys()
    {
        $queries = array(
            'sourceArticles' => array(
                'sql'   => 'SELECT AR_Ref as k FROM '.$this->src('f_article'),
                'field' => 'k',
            ),
            // f_comptet compte 157 102 lignes : on la restreint aux seuls tiers cités par
            // f_artfourniss, soit un peu plus de 300.
            'sourceSuppliers' => array(
                'sql'   => 'SELECT DISTINCT c.CT_Num as k FROM '.$this->src('f_comptet').' as c'
                    .' INNER JOIN (SELECT DISTINCT CT_Num FROM '.$this->src($this->srcTable).') as f'
                    .' ON f.CT_Num = c.CT_Num',
                'field' => 'k',
            ),
        );

        foreach ($queries as $property => $query) {
            $resql = $this->db->query($query['sql']);
            if (!$resql) {
                $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
                return -1;
            }
            $field = $query['field'];
            while ($obj = $this->db->fetch_object($resql)) {
                $this->{$property}[trim((string) $obj->$field)] = true;
            }
            $this->db->free($resql);
        }

        return 1;
    }

    /**
     * Index des produits déjà repris : AR_Ref -> rowid, référence et taux de TVA.
     *
     * La jointure sur la source limite le chargement aux articles réellement cités, et la
     * référence Dolibarr est nécessaire au calcul des références fournisseur : un produit
     * adopté de la boutique a gardé la sienne, elle ne se recalcule pas depuis AR_Ref.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $prefix = $this->db->escape($this->refExtPrefix);

        $sql  = 'SELECT p.rowid, p.ref, p.tva_tx, p.price, f.AR_Ref FROM '.MAIN_DB_PREFIX.'product as p';
        $sql .= ' INNER JOIN (SELECT DISTINCT AR_Ref FROM '.$this->src($this->srcTable).') as f';
        $sql .= "   ON p.ref_ext = CONCAT('".$prefix."', CONVERT(f.AR_Ref USING utf8mb4))";
        $sql .= ' WHERE p.entity IN ('.getEntity('product').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productBySage[trim((string) $obj->AR_Ref)] = array(
                'id'   => (int) $obj->rowid,
                'ref'  => (string) $obj->ref,
                'vat'  => (float) $obj->tva_tx,
                'sell' => (float) $obj->price,   // prix de vente HT, pour le contrôle de marge
            );
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des tiers déjà repris : CT_Num -> rowid.
     *
     * Le drapeau `fournisseur` n'est pas filtré : la jointure sur `ref_ext` est plus sûre
     * qu'un contrôle de drapeau, et les 335 tiers concernés le portent tous.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadSupplierIndex()
    {
        $prefix = $this->db->escape($this->refExtPrefix);

        $sql  = 'SELECT s.rowid, f.CT_Num FROM '.MAIN_DB_PREFIX.'societe as s';
        $sql .= ' INNER JOIN (SELECT DISTINCT CT_Num FROM '.$this->src($this->srcTable).') as f';
        $sql .= "   ON s.ref_ext = CONCAT('".$prefix."', CONVERT(f.CT_Num USING utf8mb4))";
        $sql .= ' WHERE s.entity IN ('.getEntity('societe').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->supplierBySage[trim((string) $obj->CT_Num)] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des lignes tarifaires déjà en base, hors celles posées par la reprise.
     *
     * Deux emplois : reconnaître une ligne préexistante à compléter plutôt qu'à créer, et
     * repérer une référence déjà occupée par un autre produit avant de tomber dessus.
     *
     * Seules les lignes à quantité 1 sont indexées : c'est le seul palier que la reprise
     * écrit, et la quantité fait partie de la clé d'unicité.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadTargetLines()
    {
        $sql  = 'SELECT rowid, fk_product, fk_soc, ref_fourn FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= ' AND quantity = '.((float) self::QUANTITY);
        $sql .= " AND (import_key IS NULL OR import_key NOT LIKE '".$this->db->escape($this->refExtPrefix)."%')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = $this->refKey($obj->fk_soc, (string) $obj->ref_fourn);
            $this->targetLines[$key] = array(
                'rowid'   => (int) $obj->rowid,
                'product' => (int) $obj->fk_product,
            );
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Charge les taux de change de l'instance.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadCurrencyRates()
    {
        $sql  = 'SELECT m.code, r.rate FROM '.MAIN_DB_PREFIX.'multicurrency as m';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'multicurrency_rate as r ON r.fk_multicurrency = m.rowid';
        $sql .= ' WHERE m.entity IN ('.getEntity('multicurrency').')';
        $sql .= ' ORDER BY r.date_sync ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        // Le tri chronologique fait que le taux le plus récent écrase les précédents.
        while ($obj = $this->db->fetch_object($resql)) {
            $rate = (float) $obj->rate;
            if ($rate > 0) {
                $this->currencyRates[(string) $obj->code] = $rate;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Identifiant du type de code-barres EAN13.
     *
     * La colonne porte une contrainte de clé étrangère : l'identifiant se lit, il ne se
     * devine pas. Son absence n'est pas bloquante, seuls quatre articles sont concernés.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadBarcodeType()
    {
        $sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_barcode_type WHERE code = 'EAN13'";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        if ($obj = $this->db->fetch_object($resql)) {
            $this->barcodeTypeId = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Repère les références fournisseur partagées par plusieurs articles.
     *
     * Le comptage se fait en PHP et non par une agrégation SQL, pour une raison précise :
     * TRIM() de MySQL ne retire pas les tabulations, contrairement à trim() de PHP. Six
     * références en portent, en tête ou en fin. Une carte construite en SQL ne coïnciderait
     * donc pas avec les clés calculées à l'exécution, la collision passerait à travers et
     * l'écriture partirait dans la branche de update_buyprice() qui commence par un DELETE.
     *
     * Seuls les groupes en collision sont conservés : les clés de travail sont libérées.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadRefCollisions()
    {
        $sql = 'SELECT CT_Num, AF_RefFourniss FROM '.$this->src($this->srcTable);
        if ($this->srcWhere !== '') {
            $sql .= ' WHERE '.$this->srcWhere;
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $seen = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ref = $this->cleanSupplierRef($obj->AF_RefFourniss);
            if ($ref === '') {
                // Une référence absente est de toute façon remplacée par celle du produit,
                // qui est unique par construction.
                continue;
            }

            $key = $this->refKey($obj->CT_Num, $ref);
            if (isset($seen[$key])) {
                $this->collidingRefs[$key] = true;
            }
            $seen[$key] = true;
        }
        $this->db->free($resql);
        unset($seen);

        return 1;
    }

    /**
     * Dénombre les lignes que le filtre de lecture écarte.
     *
     * Elles n'apparaissent dans aucun compteur du socle, puisqu'elles ne sont jamais lues :
     * sans ce décompte, le rapport laisserait croire que la source a été traitée en entier.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscardedRows()
    {
        $sql   = 'SELECT COUNT(*) as nb FROM '.$this->src($this->srcTable)." WHERE TRIM(COALESCE(CT_Num,'')) = ''";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $this->emptySupplierRows = (int) $obj->nb;

        return 1;
    }

    /**
     * Charge l'index des lignes déjà reprises.
     *
     * llx_product_fournisseur_price ne porte pas de ref_ext : le mécanisme natif du socle
     * est inapplicable. On se rabat sur `import_key`, prévue pour cela par le coeur et
     * écrite par l'API dans stampLine(). La clé y est cbMarq, donc la ligne source
     * elle-même.
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
     * Nombre de lignes tarifaires déjà reprises.
     *
     * Comptées sur import_key, la table n'ayant pas de ref_ext.
     *
     * @return int Nombre de lignes, -1 si le comptage échoue
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
     * Nettoie une référence fournisseur.
     *
     * Cette méthode est la seule autorité sur la forme d'une référence : elle sert aussi
     * bien à construire la carte des collisions qu'à écrire en base, faute de quoi les deux
     * divergeraient.
     *
     * La source porte des tabulations en tête et en fin, des espaces de bord, et des
     * valeurs qui ne sont pas des références mais des marques de « rien » : « --- »,
     * « /// », « . ». Une chaîne sans le moindre caractère alphanumérique n'en est pas une.
     *
     * @param string $value Valeur brute
     * @return string       Référence nettoyée, chaîne vide si inexploitable
     */
    protected function cleanSupplierRef($value)
    {
        $value = str_replace(array("\t", "\r", "\n", "\0"), ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        if ($value === '' || !preg_match('/[\p{L}\p{N}]/u', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Clé de comparaison d'une référence chez un fournisseur.
     *
     * Reproduit la sémantique de l'index unique de la table, dont la collation
     * utf8mb4_0900_ai_ci est insensible à la casse comme aux accents : « REF-A » et
     * « ref-à » y sont la même valeur, ce qu'une comparaison PHP stricte manquerait.
     *
     * @param int|string $supplier Identifiant du fournisseur, côté source ou côté cible
     * @param string     $ref      Référence nettoyée
     * @return string              Clé de comparaison
     */
    protected function refKey($supplier, $ref)
    {
        return trim((string) $supplier).'|'.strtolower(dol_string_unaccent(trim((string) $ref)));
    }

    /**
     * Construit une référence fournisseur qui ne puisse en écraser aucune autre.
     *
     * Trois cas :
     *
     * 1. Référence absente ou non significative — la référence du produit en tient lieu.
     *    Elle est unique par construction : un article n'apparaît jamais deux fois sans
     *    référence chez le même fournisseur.
     * 2. Référence partagée avec un autre article chez ce fournisseur — elle est
     *    désambiguïsée par la référence du produit, « Chemise (#10475) », ce qui reste
     *    lisible pour l'acheteur tout en respectant l'unicité.
     * 3. Référence unique — reprise telle quelle.
     *
     * La méthode est volontairement sans effet de bord : elle peut être appelée plusieurs
     * fois pour une même ligne, le temps que la référence définitive soit arrêtée. Les
     * compteurs du rapport sont alimentés à part, par commitRefCounters().
     *
     * @param stdClass $row         Ligne source
     * @param string   $productRef  Référence Dolibarr du produit
     * @param bool     $forceSuffix Forcer la désambiguïsation, collision détectée en base
     * @return array{ref:string,kind:string,truncated:bool}
     */
    protected function composeRef($row, $productRef, $forceSuffix = false)
    {
        $ref = $this->cleanSupplierRef($row->AF_RefFourniss);

        if ($ref === '') {
            return $this->fitRef($productRef, '', 'product');
        }

        if ($forceSuffix || isset($this->collidingRefs[$this->refKey($row->CT_Num, $ref)])) {
            return $this->fitRef($ref, ' ('.$productRef.')', 'suffixed');
        }

        return $this->fitRef($ref, '', 'kept');
    }

    /**
     * Ajuste une référence à la capacité de la colonne, sans amputer son suffixe.
     *
     * Sans objet sur les données actuelles — AF_RefFourniss ne dépasse pas 30 caractères et
     * les références produit en font six —, mais llx_product.ref accepte 128 caractères et
     * un produit adopté de la boutique peut en porter une longue.
     *
     * @param string $base   Référence
     * @param string $suffix Suffixe de désambiguïsation, éventuellement vide
     * @param string $kind   Origine de la référence, reportée telle quelle
     * @return array{ref:string,kind:string,truncated:bool}
     */
    protected function fitRef($base, $suffix, $kind)
    {
        $truncated = false;
        $max       = 128;

        if (dol_strlen($base.$suffix) > $max) {
            $base      = dol_trunc($base, $max - dol_strlen($suffix), 'right', 'UTF-8', 1);
            $truncated = true;
        }

        return array('ref' => $base.$suffix, 'kind' => $kind, 'truncated' => $truncated);
    }

    /**
     * Enregistre au rapport la façon dont la référence a finalement été obtenue.
     *
     * Le comptage est dissocié du calcul parce qu'une référence peut être recalculée en
     * cours de route — collision découverte en base plutôt que dans la source. Il n'a lieu
     * qu'une fois la valeur définitive arrêtée.
     *
     * @param array{kind:string,truncated:bool} $ref Référence retenue
     * @return void
     */
    protected function commitRefCounters($ref)
    {
        switch ($ref['kind']) {
            case 'product':
                $this->refFromProduct++;
                break;
            case 'suffixed':
                $this->refSuffixed++;
                break;
            default:
                $this->refKept++;
        }

        if (!empty($ref['truncated'])) {
            $this->refTruncated++;
        }
    }

    /**
     * Arrête la référence fournisseur définitive d'une ligne.
     *
     * Deux sources de collision se cumulent. Celle de la source est connue d'avance, par la
     * carte construite au démarrage. Celle de la cible ne l'est pas : la boutique ou une
     * saisie manuelle ont pu occuper la place avant nous. La seconde est donc vérifiée ici,
     * avant toute écriture — c'est ce qui permet à add_fournisseur() de ne jamais avoir à
     * refuser la référence.
     *
     * @param stdClass $row    Ligne source
     * @param array    $target Entrée d'index du produit (id, ref, vat)
     * @param int      $socid  rowid du fournisseur
     * @return array{ref:string,kind:string,truncated:bool}
     */
    protected function resolveRef($row, $target, $socid)
    {
        $ref = $this->composeRef($row, $target['ref']);
        $key = $this->refKey($socid, $ref['ref']);

        if (isset($this->targetLines[$key]) && $this->targetLines[$key]['product'] !== (int) $target['id']) {
            $this->refConflicts++;
            $ref = $this->composeRef($row, $target['ref'], true);
        }

        return $ref;
    }

    /**
     * Détermine la devise, le taux et le prix à transmettre à Dolibarr.
     *
     * Le prix en euros n'est jamais écrit tel quel : update_buyprice() le recalcule par
     * `prix en devise / taux`. C'est donc le taux qui décide de sa valeur, et la source ne
     * l'a pas. Le taux implicite qu'elle porte — AF_PrixDev / AF_PrixAch — varie de 0,28 à
     * 2,17 sur une même devise : il est crédible sur une partie des lignes, manifestement
     * faux sur le reste.
     *
     * La règle retenue conserve le taux de la source lorsqu'il approche celui de l'instance,
     * auquel cas les deux prix sont reproduits au centime, et bascule sur le taux officiel
     * sinon — le prix en euros est alors recalculé, et l'écart signalé.
     *
     * @param stdClass $row Ligne source
     * @return array{code:string,rate:float,price:float}
     */
    protected function resolveCurrency($row)
    {
        $priceEur = (float) $row->AF_PrixAch;
        $devise   = isset($row->AF_Devise) ? (int) $row->AF_Devise : 0;
        $code     = isset($this->currencyAliases[$devise]) ? $this->currencyAliases[$devise] : 'EUR';

        $euro = array('code' => 'EUR', 'rate' => 1.0, 'price' => $priceEur);

        if ($code === 'EUR') {
            return $euro;
        }

        // Une devise déclarée dans la source mais inconnue de l'instance ne peut pas être
        // écrite : fk_multicurrency resterait vide et la ligne serait incohérente.
        if (!isset($this->currencyRates[$code])) {
            $this->currencyUnknown[$code] = true;
            return $euro;
        }

        $official = $this->currencyRates[$code];
        $priceDev = (float) $row->AF_PrixDev;

        // Devise annoncée sans montant : il n'y a rien à reprendre de ce côté.
        if ($priceDev <= 0) {
            $this->currencyWithoutAmount++;
            return $euro;
        }

        // Pas de prix en euros : le taux officiel est la seule façon d'en obtenir un.
        if ($priceEur <= 0) {
            $this->currencyWithoutEuro++;
            return array('code' => $code, 'rate' => $official, 'price' => $priceDev);
        }

        $rate = $priceDev / $priceEur;

        if (abs(($rate / $official) - 1) < self::RATE_TOLERANCE) {
            $this->increment('rateKept', $code);
            return array('code' => $code, 'rate' => $rate, 'price' => $priceDev);
        }

        $this->increment('rateForced', $code);

        return array('code' => $code, 'rate' => $official, 'price' => $priceDev);
    }

    /**
     * Traduit une ligne source en valeurs prêtes pour l'API Dolibarr.
     *
     * Méthode pure : elle n'écrit rien et alimente les compteurs du rapport, ce qui permet
     * au mode simulation de produire exactement le même rapport qu'un passage réel.
     *
     * La référence fournisseur n'en fait pas partie : elle peut devoir être recalculée
     * plus tard, une fois connue l'occupation réelle de la table cible.
     *
     * @param stdClass $row    Ligne source
     * @param array    $target Entrée d'index du produit (id, ref, vat)
     * @return array<string,mixed> Valeurs de la ligne tarifaire
     */
    protected function mapLine($row, $target)
    {
        $currency = $this->resolveCurrency($row);

        if ($currency['price'] <= 0) {
            // La référence fournisseur vaut à elle seule d'être reprise : la ligne est
            // créée avec un tarif nul, visible et corrigeable dans la fiche produit.
            $this->priceMissing++;
        }

        $this->checkAgainstSellPrice($row, $target, $currency);

        $vat = (float) $target['vat'];
        if ($vat <= 0) {
            $this->vatDefaulted++;
            $vat = $this->defaultVat;
        }

        // Conditionnement : AF_QteMini et AF_Colisage sont rigoureusement identiques sur
        // toute la table, c'est la même information saisie deux fois.
        $packaging = (float) $row->AF_QteMini;
        if ($packaging > 1) {
            $this->packagingSet++;
        } else {
            $packaging = 0;
        }

        // Délai : la valeur vide doit rester vide et non devenir zéro, le coeur écrivant
        // NULL pour l'une et « 0 jour de délai » pour l'autre.
        $delay = '';
        if (!empty($row->AF_DelaiAppro) && (int) $row->AF_DelaiAppro > 0) {
            $delay = (int) $row->AF_DelaiAppro;
            $this->delaySet++;
        }

        // Remise : AF_TypeRem est NULL sur toute la table, mais la nature de AF_Remise est
        // établie — l'ancien ERP affiche « brut 39,00 · remise 30,00 · net 27,30 » sur
        // l'article 13566, dont AF_PrixAch vaut 39 et AF_Remise 30. C'est donc bien un
        // pourcentage, et le brut qui est stocké dans AF_PrixAch.
        //
        // Le modèle cible est identique : `price` reçoit le brut, `remise_percent` le taux,
        // et le coeur applique `unitprice * (1 - remise_percent / 100)` là où le prix net
        // compte — choix du meilleur fournisseur (fournisseur.product.class.php:1013),
        // colonne « Prix d'achat » de la liste des produits, valorisation des nomenclatures,
        // prix d'achat des lignes de document. La remise reste visible et modifiable dans
        // l'onglet « Prix fournisseurs » de la fiche article, colonne « Remise quantité min ».
        //
        // Seules 58 lignes sur 15 962 en portent une, alors que l'ancien ERP en affiche sur
        // d'autres (article 14240 : 17 %, AF_Remise NULL dans l'export). Voir ANOMALIES.md,
        // F8 : les données livrées sont lacunaires sur cette colonne, sans que rien ne
        // permette de reconstituer les taux manquants.
        $remise = 0;
        if (!empty($row->AF_Remise) && (float) $row->AF_Remise > 0) {
            $remise = (float) $row->AF_Remise;
            $this->remiseSet++;
        }

        $reputation = '';
        if (!empty($row->AF_Principal)) {
            $reputation = 'FAVORITE';
            $this->favoriteSet++;
        }

        $desc = trim((string) $row->designation_fournisseur);
        if ($desc !== '') {
            $this->descSet++;
        }

        $barcode     = trim((string) $row->AF_CodeBarre);
        $barcodeType = 0;
        if ($barcode !== '' && $this->barcodeTypeId > 0) {
            $barcodeType = $this->barcodeTypeId;
            $this->barcodeSet++;
        } elseif ($barcode !== '') {
            $barcode = '';
        }

        return array(
            'price'       => $currency['price'],
            'currency'    => $currency['code'],
            'rate'        => $currency['rate'],
            'vat'         => $vat,
            'desc'        => $desc,
            'packaging'   => $packaging,
            'delay'       => $delay,
            'remise'      => $remise,
            'reputation'  => $reputation,
            'barcode'     => $barcode,
            'barcodeType' => $barcodeType,
        );
    }

    /**
     * Signale un prix d'achat supérieur au prix de vente du produit.
     *
     * La reprise n'y touche pas : rien ne dit lequel des deux prix est faux, et la source
     * n'offre aucun moyen de trancher. Mais l'anomalie mérite d'être remontée nommément
     * plutôt que d'être découverte plus tard sur une marge négative.
     *
     * Seul le prix en euros est comparé, le prix de vente Dolibarr étant en euros. Un
     * article sans prix de vente — la boutique n'en donne pas toujours — est écarté du
     * contrôle : la comparaison n'aurait aucun sens.
     *
     * @param stdClass $row      Ligne source
     * @param array    $target   Entrée d'index du produit
     * @param array    $currency Devise, taux et prix retenus
     * @return void
     */
    protected function checkAgainstSellPrice($row, $target, $currency)
    {
        $sell = (float) $target['sell'];
        if ($sell <= 0) {
            return;
        }

        // Le prix en euros est celui que Dolibarr recalculera : prix en devise / taux.
        $buy = ($currency['rate'] > 0) ? ($currency['price'] / $currency['rate']) : 0;
        if ($buy <= $sell) {
            return;
        }

        $this->aboveSellPrice[$target['ref']] = array(
            'ref'  => trim((string) $row->CT_Num),
            'buy'  => $buy,
            'sell' => $sell,
        );
    }

    /**
     * Crée ou met à jour la ligne tarifaire correspondant à une ligne de f_artfourniss.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la ligne déjà reprise, 0 si création
     * @return array{action:string,id:int}
     * @throws Exception Si la ligne ne peut pas être écrite
     */
    protected function migrateRow($row, $existingId)
    {
        $arRef = trim((string) $row->AR_Ref);
        $ctNum = trim((string) $row->CT_Num);

        if (!isset($this->productBySage[$arRef])) {
            $this->countMissingProduct($arRef);
            return array('action' => 'skipped', 'id' => 0);
        }
        if (!isset($this->supplierBySage[$ctNum])) {
            $this->countMissingSupplier($ctNum);
            return array('action' => 'skipped', 'id' => 0);
        }

        $target  = $this->productBySage[$arRef];
        $societe = $this->fetchSupplier($this->supplierBySage[$ctNum]);

        $pf = new ProductFournisseur($this->db);
        if ($pf->fetch($target['id']) <= 0) {
            throw new Exception('Produit introuvable (rowid '.$target['id'].') : '.$this->objectErrors($pf));
        }

        $map     = $this->mapLine($row, $target);
        $ref     = $this->resolveRef($row, $target, (int) $societe->id);
        $adopted = false;

        // Le mode UPDATE de update_buyprice() est le seul sûr : sans identifiant de ligne,
        // la méthode supprime d'abord tout ce qui occupe la place (fk_soc, ref_fourn,
        // quantity) avant d'insérer, et fait disparaître la ligne d'un autre produit.
        $pf->product_fourn_price_id = $existingId;

        if ($existingId <= 0) {
            $res = $pf->add_fournisseur($this->user, $societe->id, $ref['ref'], self::QUANTITY);

            // Dernier filet : add_fournisseur() est le seul point du coeur qui contrôle
            // l'unicité d'une référence entre produits. resolveRef() a déjà consulté la
            // table, ce retour ne devrait donc jamais survenir.
            if ($res == -3) {
                $this->refConflicts++;
                $ref = $this->composeRef($row, $target['ref'], true);
                $pf->product_fourn_price_id = 0;
                $res = $pf->add_fournisseur($this->user, $societe->id, $ref['ref'], self::QUANTITY);
            }

            if ($res < 0) {
                throw new Exception('Référence fournisseur refusée ('.$ref['ref'].') : '
                    .($res == -3
                        ? 'déjà portée par le produit '.$pf->product_id_already_linked
                        : $this->objectErrors($pf)));
            }

            // Retour 0 : la ligne existait déjà pour ce produit. Elle ne vient pas de la
            // reprise, on la complète sans la revendiquer.
            $adopted = ($res === 0);
            if ($adopted) {
                $this->adoptedLines++;
            }

            // L'index cible suit les créations : la ligne suivante verra la place occupée.
            $this->targetLines[$this->refKey($societe->id, $ref['ref'])] = array(
                'rowid'   => (int) $pf->product_fourn_price_id,
                'product' => (int) $target['id'],
            );
        }

        $this->commitRefCounters($ref);

        if ((int) $pf->product_fourn_price_id <= 0) {
            throw new Exception('Identifiant de ligne tarifaire non résolu : update_buyprice() '
                .'repasserait en suppression puis insertion');
        }

        $result = $pf->update_buyprice(
            self::QUANTITY,
            $map['price'],
            $this->user,
            'HT',
            $societe,
            0,                  // fk_availability : dictionnaire Dolibarr sans équivalent source
            $ref['ref'],
            $map['vat'],
            0,                  // charges
            $map['remise'],
            0,                  // remise en montant
            0,                  // NPR
            $map['delay'],
            $map['reputation'],
            array(),            // taxes locales : le coeur ne les écrit pas
            '',                 // code de TVA par défaut
            // Le prix DOIT être fourni ici : le module multidevise étant actif,
            // update_buyprice() écrase systématiquement le prix en euros par
            // « prix en devise / taux ». Sans ces paramètres, la ligne naîtrait à zéro.
            $map['price'],
            'HT',
            $map['rate'],
            $map['currency'],
            $map['desc'],
            $map['barcode'],
            $map['barcodeType']
        );

        if ($result <= 0) {
            throw new Exception('Échec du tarif : '.$this->objectErrors($pf));
        }

        $rowid = (int) $pf->product_fourn_price_id;

        // Une ligne adoptée ne reçoit pas de marqueur : la purge ne doit pas supprimer ce
        // que la reprise n'a pas créé.
        $this->stampLine($rowid, ($adopted ? '' : $this->buildRefExt($this->getSourceKey($row))), $map['packaging']);

        if ($existingId > 0) {
            return array('action' => 'updated', 'id' => $rowid);
        }

        return array('action' => ($adopted ? 'adopted' : 'created'), 'id' => $rowid);
    }

    /**
     * Complète la ligne tarifaire avec ce que update_buyprice() n'écrit pas.
     *
     * `import_key` n'y figure pas, et `packaging` n'y est écrite que si la constante
     * PRODUCT_USE_SUPPLIER_PACKAGING est posée — ce que la reprise se refuse à faire,
     * cette constante modifiant l'arrondi des quantités d'achat pour toute l'instance.
     *
     * ProductFournisseurPrice étant un objet à part entière du coeur, les deux colonnes
     * s'écrivent par son update() : aucune requête directe n'est nécessaire.
     *
     * @param int    $rowid     Identifiant de la ligne tarifaire
     * @param string $importKey Marqueur de reprise, chaîne vide pour ne pas marquer
     * @param float  $packaging Conditionnement, 0 pour ne rien écrire
     * @return void
     * @throws Exception Si la ligne est introuvable ou la mise à jour refusée
     */
    protected function stampLine($rowid, $importKey, $packaging)
    {
        if ($importKey === '' && $packaging <= 0) {
            return;
        }

        $line = new ProductFournisseurPrice($this->db);
        if ($line->fetch($rowid) <= 0) {
            throw new Exception('Ligne tarifaire introuvable (rowid '.$rowid.') : '.$this->objectErrors($line));
        }

        if ($importKey !== '') {
            $line->import_key = $importKey;
        }
        if ($packaging > 0) {
            $line->packaging = $packaging;
        }

        if ($line->update($this->user) <= 0) {
            throw new Exception('Échec du marquage de la ligne '.$rowid.' : '.$this->objectErrors($line));
        }
    }

    /**
     * Charge un tiers, une seule fois par fournisseur.
     *
     * update_buyprice() accède directement à `$fourn->id` : il lui faut un objet, pas un
     * identifiant. Sur 15 962 lignes pour 335 fournisseurs, le cache évite autant de
     * chargements inutiles.
     *
     * @param int $socid rowid du tiers
     * @return Societe
     * @throws Exception Si le tiers est introuvable
     */
    protected function fetchSupplier($socid)
    {
        if (isset($this->supplierCache[$socid])) {
            return $this->supplierCache[$socid];
        }

        $societe = new Societe($this->db);
        if ($societe->fetch($socid) <= 0) {
            throw new Exception('Fournisseur introuvable (rowid '.$socid.') : '.$this->objectErrors($societe));
        }

        $this->supplierCache[$socid] = $societe;

        return $societe;
    }

    /**
     * Contrôle une ligne en simulation, sans rien persister.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de construire un tarif valide
     */
    protected function validateRow($row)
    {
        $arRef = trim((string) $row->AR_Ref);
        $ctNum = trim((string) $row->CT_Num);

        // Une extrémité manquante n'est pas une erreur : previewAction() annonce déjà que
        // la ligne sera ignorée, et le rapport en donne la ventilation.
        if (!isset($this->productBySage[$arRef])) {
            $this->countMissingProduct($arRef);
            return;
        }
        if (!isset($this->supplierBySage[$ctNum])) {
            $this->countMissingSupplier($ctNum);
            return;
        }

        $target = $this->productBySage[$arRef];

        $this->mapLine($row, $target);
        $this->commitRefCounters($this->resolveRef($row, $target, $this->supplierBySage[$ctNum]));
    }

    /**
     * Annonce l'action prévue en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la ligne déjà reprise, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        $arRef = trim((string) $row->AR_Ref);
        $ctNum = trim((string) $row->CT_Num);

        if (!isset($this->productBySage[$arRef]) || !isset($this->supplierBySage[$ctNum])) {
            return 'skipped';
        }

        if ($existingId > 0) {
            return 'updated';
        }

        // composeRef() plutôt que resolveRef() : cette méthode est appelée après
        // validateRow(), qui a déjà alimenté les compteurs du rapport pour cette ligne.
        $target = $this->productBySage[$arRef];
        $ref    = $this->composeRef($row, $target['ref']);
        $key    = $this->refKey($this->supplierBySage[$ctNum], $ref['ref']);

        if (isset($this->targetLines[$key]) && $this->targetLines[$key]['product'] === (int) $target['id']) {
            return 'adopted';
        }

        return 'created';
    }

    /**
     * Compte une ligne dont l'article n'a pas été retrouvé, en distinguant la cause.
     *
     * @param string $arRef Référence de l'article
     * @return void
     */
    protected function countMissingProduct($arRef)
    {
        if (isset($this->sourceArticles[$arRef])) {
            $this->productNotMigrated++;
        } else {
            $this->productNotInSource[$arRef] = true;
        }
    }

    /**
     * Compte une ligne dont le fournisseur n'a pas été retrouvé, en distinguant la cause.
     *
     * @param string $ctNum Code du tiers
     * @return void
     */
    protected function countMissingSupplier($ctNum)
    {
        if (isset($this->sourceSuppliers[$ctNum])) {
            $this->supplierNotMigrated++;
        } else {
            $this->supplierNotInSource[$ctNum] = true;
        }
    }

    /**
     * Incrémente un compteur ventilé par clé.
     *
     * @param string $property Nom de la propriété
     * @param string $key      Clé de ventilation
     * @return void
     */
    protected function increment($property, $key)
    {
        if (!isset($this->{$property}[$key])) {
            $this->{$property}[$key] = 0;
        }
        $this->{$property}[$key]++;
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des tarifs fournisseurs posés par la reprise (table '
            .MAIN_DB_PREFIX.$this->dstTable.', marqueur import_key « '.$this->refExtPrefix.' »)';
    }

    /**
     * Supprime les lignes tarifaires créées par la reprise.
     *
     * Le socle ne sait purger que des objets repérés par leur ref_ext, dans l'une des
     * quatre tables qu'il connaît : ni l'un ni l'autre ne s'applique ici. Le critère est le
     * marqueur que le script a lui-même posé, et la suppression passe par l'API pour que le
     * trigger PRODUCT_BUYPRICE_DELETE soit déclenché.
     *
     * Les lignes adoptées ne portent pas de marqueur : elles sont préservées.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid, import_key FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";
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
            $pf = new ProductFournisseur($this->db);

            // Le chargement renseigne le produit porteur, dont le trigger a besoin.
            if ($pf->fetch_product_fournisseur_price((int) $row->rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = $row->import_key.' : chargement impossible';
                continue;
            }

            if ($pf->remove_product_fournisseur_price((int) $row->rowid) > 0) {
                $result['deleted']++;
            } else {
                $result['failed']++;
                $result['errors'][] = $row->import_key.' : '.$this->objectErrors($pf);
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

        $this->reportAttachment($lines);
        $this->reportReferences($lines);
        $this->reportPrices($lines);
        $this->reportOther($lines);

        return $lines;
    }

    /**
     * Rattachement des lignes aux produits et aux tiers.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportAttachment(array &$lines)
    {
        $block = array();

        if ($this->productNotMigrated > 0) {
            $block[] = $this->countLine($this->productNotMigrated, 'ligne(s) dont l\'article n\'est pas encore repris');
            $block[] = '          lancez « migrate.php product » en entier avant celui-ci.';
        }
        if (!empty($this->productNotInSource)) {
            $refs = array_keys($this->productNotInSource);
            $block[] = $this->countLine(count($refs), 'référence(s) article absente(s) de f_article : '
                .implode(', ', array_slice($refs, 0, 10)).(count($refs) > 10 ? '…' : ''));
        }
        if ($this->supplierNotMigrated > 0) {
            $block[] = $this->countLine($this->supplierNotMigrated, 'ligne(s) dont le tiers n\'est pas encore repris');
        }
        if (!empty($this->supplierNotInSource)) {
            $codes = array_keys($this->supplierNotInSource);
            $block[] = $this->countLine(count($codes), 'code(s) tiers absent(s) de f_comptet : « '
                .implode(' », « ', array_slice($codes, 0, 10)).' »'.(count($codes) > 10 ? '…' : ''));
        }
        if ($this->emptySupplierRows > 0) {
            $block[] = $this->countLine($this->emptySupplierRows, 'ligne(s) écartée(s) à la lecture : aucun fournisseur');
        }

        $this->appendBlock($lines, 'Rattachement :', $block);
    }

    /**
     * Traitement des références fournisseur.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportReferences(array &$lines)
    {
        $block = array();

        if ($this->refKept > 0) {
            $block[] = $this->countLine($this->refKept, 'référence(s) reprise(s) telle(s) quelle(s)');
        }
        if ($this->refSuffixed > 0) {
            $block[] = $this->countLine($this->refSuffixed, 'référence(s) suffixée(s) par celle du produit');
            $block[] = '          plusieurs articles la partagent chez le même fournisseur, or';
            $block[] = '          Dolibarr n\'en accepte qu\'un par référence.';
        }
        if ($this->refFromProduct > 0) {
            $block[] = $this->countLine($this->refFromProduct, 'référence(s) absente(s) ou vide(s) de sens, remplacée(s) par celle du produit');
        }
        if ($this->refConflicts > 0) {
            $block[] = $this->countLine($this->refConflicts, 'référence(s) déjà présente(s) en base pour un autre produit, désambiguïsée(s)');
        }
        if ($this->refTruncated > 0) {
            $block[] = $this->countLine($this->refTruncated, 'référence(s) tronquée(s) à 128 caractères');
        }

        $this->appendBlock($lines, 'Références fournisseur :', $block);
    }

    /**
     * Prix, devises et taux.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportPrices(array &$lines)
    {
        $block = array();

        if ($this->priceMissing > 0) {
            $block[] = $this->countLine($this->priceMissing, 'ligne(s) sans prix d\'achat : tarif créé à zéro');
        }

        foreach ($this->rateKept as $code => $nb) {
            $block[] = $this->countLine($nb, 'ligne(s) en '.$code.' : taux de la source conservé, les deux prix sont ceux de la source');
        }
        foreach ($this->rateForced as $code => $nb) {
            $block[] = $this->countLine($nb, 'ligne(s) en '.$code.' : taux de la source écarté, taux de l\'instance appliqué');
            $block[] = '          le prix en euros est recalculé et s\'écarte de AF_PrixAch.';
        }
        if ($this->currencyWithoutAmount > 0) {
            $block[] = $this->countLine($this->currencyWithoutAmount, 'ligne(s) en devise étrangère sans montant en devise, reprise(s) en euros');
        }
        if ($this->currencyWithoutEuro > 0) {
            $block[] = $this->countLine($this->currencyWithoutEuro, 'ligne(s) en devise étrangère sans prix en euros : taux de l\'instance appliqué');
        }
        if (!empty($this->currencyUnknown)) {
            $block[] = $this->countLine(count($this->currencyUnknown), 'devise(s) de la source absente(s) de Dolibarr, reprise(s) en euros : '
                .implode(', ', array_keys($this->currencyUnknown)));
        }
        if ($this->vatDefaulted > 0) {
            $block[] = $this->countLine($this->vatDefaulted, 'ligne(s) dont le produit ne porte aucun taux de TVA : '
                .rtrim(rtrim(number_format($this->defaultVat, 2, ',', ' '), '0'), ',').' % appliqué');
        }

        if (!empty($this->aboveSellPrice)) {
            $block[] = $this->countLine(count($this->aboveSellPrice), 'produit(s) achetés plus cher qu\'ils ne sont vendus.');
            $block[] = '          Rien n\'indique lequel des deux prix est faux : la reprise n\'y touche';
            $block[] = '          pas, mais la marge sera négative dans Dolibarr. À vérifier :';
            $shown = 0;
            foreach ($this->aboveSellPrice as $productRef => $case) {
                if ($shown++ >= 10) {
                    $block[] = '            … et '.(count($this->aboveSellPrice) - 10).' autre(s)';
                    break;
                }
                $block[] = '            '.str_pad($productRef, 12).' achat '
                    .number_format($case['buy'], 2, ',', ' ').' chez '.$case['ref']
                    .', vente '.number_format($case['sell'], 2, ',', ' ');
            }
        }

        $this->appendBlock($lines, 'Prix et devises :', $block);
    }

    /**
     * Conditionnements, délais, remises et divers.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportOther(array &$lines)
    {
        $block = array();

        if ($this->packagingSet > 0) {
            $block[] = $this->countLine($this->packagingSet, 'conditionnement(s) repris dans « packaging »');
            $block[] = '          exploités par Dolibarr seulement si PRODUCT_USE_SUPPLIER_PACKAGING';
            $block[] = '          est activée, ce que la reprise ne fait pas.';
        }
        if ($this->delaySet > 0) {
            $block[] = $this->countLine($this->delaySet, 'délai(s) d\'approvisionnement repris');
        }
        if ($this->remiseSet > 0) {
            $block[] = $this->countLine($this->remiseSet, 'remise(s) reprise(s) en pourcentage');
            $block[] = '          AF_TypeRem étant vide sur toute la table, rien ne garantit qu\'il';
            $block[] = '          s\'agisse d\'un taux : à faire valider par le client.';
        }
        if ($this->favoriteSet > 0) {
            $block[] = $this->countLine($this->favoriteSet, 'ligne(s) marquée(s) « fournisseur principal »');
        }
        if ($this->descSet > 0) {
            $block[] = $this->countLine($this->descSet, 'description(s) fournisseur reprise(s)');
        }
        if ($this->barcodeSet > 0) {
            $block[] = $this->countLine($this->barcodeSet, 'code(s)-barres repris');
        }
        if ($this->adoptedLines > 0) {
            $block[] = $this->countLine($this->adoptedLines, 'ligne(s) tarifaire(s) préexistante(s), complétée(s) mais non marquée(s)');
            $block[] = '          la purge ne les supprimera pas.';
        }

        $this->appendBlock($lines, 'Conditionnements, délais et divers :', $block);
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
