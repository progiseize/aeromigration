<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationcustomerprice.class.php
 * \ingroup aeromigration
 * \brief   Reprise des tarifs de vente par catégorie : f_article + z_tarifparticulier -> llx_product_price.
 *
 * PRÉREQUIS : la reprise des articles doit avoir été passée. Chaque article est retrouvé
 * par son `ref_ext`.
 *
 * ------------------------------------------------------------------------------
 * UN NIVEAU LAISSÉ VIDE FACTURE ZÉRO
 * ------------------------------------------------------------------------------
 *
 * C'est le fait qui commande toute la conception. `Product::fetch()` pose
 * `multiprices[$i] = null` quand un niveau n'a aucune ligne (product.class.php:3210), et
 * `getSellPrice()` l'utilise **sans repli** (ligne 2469). Un client dont la catégorie n'a
 * pas de prix pour l'article se voit donc facturer 0,00 €, sans le moindre avertissement.
 *
 * Ce script écrit par conséquent **les huit niveaux pour tous les articles**, y compris
 * ceux qui n'ont aucune dérogation : ils reçoivent alors le prix de base. C'est ce qui
 * explique le volume — environ 127 000 lignes de prix pour 15 900 articles.
 *
 * ## Ce que la source dit, et ce qu'elle ne dit pas
 *
 * `z_tarifparticulier` est une table applicative de l'ancien ERP, pas une table Sage. Quatre
 * de ses colonnes ont été écartées après vérification, et il faut savoir pourquoi avant de
 * vouloir les rétablir :
 *
 * - **La catégorie 0 n'est pas le prix public.** Elle coïncide avec `f_article.AR_PrixVen`
 *   sur 15 149 des 15 885 articles comparables ; sur les 736 écarts, **735 fois c'est la
 *   fiche article qui a été modifiée en dernier**. `f_article` compte 15 238 lignes touchées
 *   en 2026 contre 570 pour la catégorie 0, figée en 2023. C'est une strate historique.
 *   Le prix de référence est donc celui de la fiche article — la même source que
 *   `MigrationProduct`, ce qui garantit que les deux scripts ne peuvent pas diverger.
 *
 * - **`coeff` n'est pas un multiplicateur de prix.** Article 78 : coeff 3,0159 pour un coût
 *   standard de 2,99, soit 9,02, alors que le tarif est à 9,50. Article 2502 : 2,3174 × 11,20
 *   = 25,95 contre 29,95. Aucune combinaison avec le prix d'achat, le coût standard ou la TVA
 *   ne le reconstitue : c'est un indicateur de marge. Sur toutes les lignes concernées,
 *   `AR_PrixVen` est de toute façon renseigné et fait foi.
 *
 * - **`statut` est inexploitable.** Ses trois valeurs — `O`, `S`, vide — ne corrèlent ni avec
 *   la péremption ni avec le prix. En catégorie Comptoir, **3 717 lignes sur 5 217 ont un
 *   statut vide** : filtrer sur `statut = 'O'` perdrait 71 % du tarif. Seules les dates de
 *   validité départagent, et elles sont nettes.
 *
 * - **Les 61 règles par famille de la catégorie FFA sont neutres** : toutes portent
 *   `remise = 0` et `coeff = 1`. Sept règles seulement sont agissantes, en catégories
 *   Aéro-Clubs et Marché Enac.
 *
 * ## Idempotence : ni ref_ext, ni import_key
 *
 * C'est le troisième cas du module. `Product::_log_price()` (product.class.php:2332)
 * **n'écrit pas `import_key`** — la colonne existe pourtant dans `llx_product_price`, en
 * varchar(14). Aucun marqueur n'est donc disponible sans écrire en SQL direct, ce que la
 * règle du projet interdit.
 *
 * L'idempotence est portée par **la comparaison des valeurs** : le prix calculé est confronté
 * à celui déjà en base, et `updatePrice()` n'est appelée que s'ils diffèrent. Un second
 * passage n'écrit rien et n'ajoute aucune ligne d'historique. `loadMigratedIndex()` est
 * surchargée pour renvoyer un index vide, faute de quoi le socle sauterait toutes les lignes.
 *
 * @see aeromigration_price_level() pour la correspondance des catégories, et la raison
 *      pour laquelle les deux premières sont permutées.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
dol_include_once('/aeromigration/lib/aeromigration.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

class MigrationCustomerPrice extends AeroMigrationRunner
{
    /**
     * Écart en deçà duquel deux prix sont tenus pour identiques.
     *
     * Le seuil est indispensable et n'a rien d'arbitraire : `llx_product.price` est arrondi
     * au centime là où `llx_product_price` conserve huit décimales. Une comparaison stricte
     * ferait rejouer huit lignes d'historique par article à chaque passage, sur des écarts
     * de l'ordre du millionième d'euro.
     */
    const ROUNDING = 0.005;

    /** Nombre de niveaux de prix gérés. */
    const LEVELS = 8;

    /** @var string Identifiant du script en ligne de commande */
    public $code = 'customerprice';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptCustomerPrice';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /**
     * Table source : la fiche article, et non la table des tarifs.
     *
     * Le script raisonne par article et calcule ses huit niveaux d'un bloc. Parcourir les
     * 27 591 lignes de tarif obligerait au contraire à revenir plusieurs fois sur le même
     * article, sans jamais savoir quand ses niveaux sont tous posés — et laisserait sans
     * prix les 10 000 articles qui n'ont aucune dérogation, donc facturés zéro.
     *
     * @var string
     */
    protected $srcTable = 'f_article';

    /** @var string Colonnes lues */
    protected $srcFields = 'AR_Ref, AR_PrixVen, AR_PrixTTC, CL_No1, CL_No2, CL_No3, CL_No4';

    /** @var string Colonne de parcours : la clé primaire de f_article */
    protected $srcCursorField = 'AR_Ref';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle de l'article dans la source */
    protected $srcKeyField = 'AR_Ref';

    /** @var string Filtre sur la source */
    protected $srcWhere = "TRIM(AR_Ref) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'product_price';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'productprice';

    /** @var array<string,array{id:int,ref:string,tva:float,type:int}> Produits cible, par ref_ext */
    protected $productIndex = array();

    /** @var array<string,array<int,stdClass>> Ligne de tarif retenue, par référence puis catégorie */
    protected $tariffs = array();

    /** @var array<int,array<int,float>> Remise par catégorie puis famille de catalogue */
    protected $familyRules = array();

    /** @var array<int,array<int,array{price:float,price_ttc:float,base:string}>> Prix en base, par produit puis niveau */
    protected $existingPrices = array();

    /** @var array<string,int> Origine des niveaux calculés */
    protected $origins = array('fixed' => 0, 'discount' => 0, 'family' => 0, 'default' => 0);

    /** @var int Articles de la source absents du catalogue Dolibarr */
    protected $missingProducts = 0;

    /** @var int Articles dont la fiche source ne porte aucun prix */
    protected $withoutBasePrice = 0;

    /** @var int Niveaux ramenés à zéro par une remise de 100 % ou plus */
    protected $cappedDiscounts = 0;

    /** @var int Niveaux majorés par une remise négative */
    protected $markups = 0;

    /** @var int Règles par famille sans effet, écartées au chargement */
    protected $neutralFamilyRules = 0;

    /** @var array<string,int> Lignes de tarif écartées au chargement, par motif */
    protected $discarded = array();

    /** @var int Nombre total de niveaux écrits */
    protected $writtenLevels = 0;

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur exécutant la reprise
     */
    public function __construct($db, $user)
    {
        parent::__construct($db, $user);

        // Ce script n'écrit pas dans une table porteuse de ref_ext : le socle n'a aucun
        // moyen de savoir ce qui est déjà fait, et sauterait tout sans cette bascule.
        // L'idempotence réelle est assurée par la comparaison des valeurs.
        $this->updateExisting = true;
    }

    /**
     * Charge les index nécessaires au calcul.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        foreach (array(
            'checkConfiguration',
            'loadProductIndex',
            'loadTariffLines',
            'loadFamilyRules',
            'loadExistingPrices',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Vérifie que la cible est configurée pour recevoir les huit niveaux.
     *
     * Écrire un niveau au-delà de `PRODUIT_MULTIPRICES_LIMIT` ne provoque aucune erreur :
     * la ligne est bien insérée, mais `fetch()` ne la relit jamais et l'écran ne l'affiche
     * pas. Le tarif serait perdu sans que rien ne le dise.
     *
     * @return int 1 si la configuration convient, -1 sinon
     */
    protected function checkConfiguration()
    {
        if (!getDolGlobalString('PRODUIT_MULTIPRICES')
            && !getDolGlobalString('PRODUIT_CUSTOMER_PRICES_AND_MULTIPRICES')) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Le multi-prix est désactivé : activez PRODUIT_MULTIPRICES avant de reprendre les tarifs',
            );
            return -1;
        }

        $limit = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
        if ($limit < self::LEVELS) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'PRODUIT_MULTIPRICES_LIMIT vaut '.$limit.' : les niveaux au-delà seraient écrits'
                    .' sans jamais être relus. Portez-la à '.self::LEVELS.'.',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Recense les produits repris, avec leur taux de TVA.
     *
     * La TVA est celle du produit cible, et non un recalcul depuis la famille : elle est
     * repassée telle quelle à `updatePrice()`, qui l'écrirait sinon à sa valeur par défaut.
     * Sans `PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL`, elle est commune aux huit niveaux.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $this->productIndex = array();

        $sql  = 'SELECT rowid, ref, ref_ext, tva_tx, fk_product_type FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productIndex[$obj->ref_ext] = array(
                'id'   => (int) $obj->rowid,
                'ref'  => $obj->ref,
                'tva'  => (float) $obj->tva_tx,
                'type' => (int) $obj->fk_product_type,
            );
        }
        $this->db->free($resql);

        if (empty($this->productIndex)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Aucun article repris en base : lancez d\'abord « migrate.php product »',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Charge la ligne de tarif retenue pour chaque couple (article, catégorie).
     *
     * ## Ce qui est écarté, et pourquoi
     *
     * - `AG_No1 <> 0` : tarifs propres à une agence, notion sans équivalent en cible (509) ;
     * - `CT_Num` renseigné : tarif nominatif, qui relèverait de `llx_product_customer_price`
     *   et non des niveaux — il n'y en a que deux, ils sont laissés de côté (2) ;
     * - `AR_aPartirDe > 1` : palier de quantité, que le multi-prix ne sait pas porter (1) ;
     * - hors période de validité : c'est le seul filtre que la source rend fiable.
     *
     * ## Le départage des doublons
     *
     * Un même couple peut avoir jusqu'à seize lignes en vigueur. L'ordre de tri les classe
     * une fois pour toutes : le dépôt principal avant le dépôt générique — 52 articles
     * portent les deux en catégorie Comptoir —, puis la date d'effet la plus récente, puis
     * le plus grand `cbMarq`. Seule la première lue est conservée.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadTariffLines()
    {
        $this->tariffs = array();

        $now = $this->db->idate($this->resolveDate());

        $sql  = 'SELECT AR_Ref, N_CatTarif, DE_No, AR_PrixVen, AR_PrixTTC, remise, cbMarq';
        $sql .= ' FROM '.$this->src('z_tarifparticulier');
        $sql .= ' WHERE N_CatTarif BETWEEN 1 AND '.self::LEVELS;
        $sql .= "   AND TRIM(COALESCE(AR_Ref, '')) <> ''";
        $sql .= '   AND AG_No1 = 0';
        $sql .= "   AND COALESCE(TRIM(CT_Num), '') = ''";
        $sql .= '   AND AR_aPartirDe <= 1';
        $sql .= "   AND AR_DateDebut <= '".$now."' AND AR_DateFin >= '".$now."'";
        $sql .= ' ORDER BY AR_Ref ASC, N_CatTarif ASC, (DE_No = 1) DESC, AR_DateDebut DESC, cbMarq DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $duplicates = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $ref = trim((string) $obj->AR_Ref);
            $cat = (int) $obj->N_CatTarif;

            if (isset($this->tariffs[$ref][$cat])) {
                // Le tri a déjà désigné la bonne : les suivantes sont des doublons.
                $duplicates++;
                continue;
            }

            $this->tariffs[$ref][$cat] = $obj;
        }
        $this->db->free($resql);

        if ($duplicates > 0) {
            $this->discarded['doublons de tarif, la plus récente l\'emporte'] = $duplicates;
        }

        return $this->countDiscardedTariffs();
    }

    /**
     * Dénombre les lignes de tarif écartées, pour que le rapport le dise.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscardedTariffs()
    {
        $now = $this->db->idate($this->resolveDate());

        $motifs = array(
            'tarifs propres à une agence'          => 'AG_No1 <> 0',
            'tarifs nominatifs, par client'        => "COALESCE(TRIM(CT_Num), '') <> ''",
            'paliers de quantité'                  => 'AR_aPartirDe > 1',
            'lignes hors période de validité'      => "AR_DateDebut > '".$now."' OR AR_DateFin < '".$now."'",
            'lignes de la catégorie 0, historique' => 'N_CatTarif = 0',
        );

        foreach ($motifs as $libelle => $condition) {
            $sql   = 'SELECT COUNT(*) as nb FROM '.$this->src('z_tarifparticulier').' WHERE '.$condition;
            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
                return -1;
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);

            if ((int) $obj->nb > 0) {
                $this->discarded[$libelle] = (int) $obj->nb;
            }
        }

        return 1;
    }

    /**
     * Charge les remises applicables à une famille entière de catalogue.
     *
     * Ces lignes n'ont pas de référence article : elles désignent une famille par `CL_No`,
     * et valent pour tous les articles qui s'y rattachent. Sept sont agissantes ; les
     * soixante et une autres portent `remise = 0` et `coeff = 1`, donc ne font rien, et
     * sont comptées à part pour que leur absence dans le résultat ne surprenne pas.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadFamilyRules()
    {
        $this->familyRules = array();

        $now = $this->db->idate($this->resolveDate());

        $sql  = 'SELECT N_CatTarif, CL_No, remise FROM '.$this->src('z_tarifparticulier');
        $sql .= ' WHERE N_CatTarif BETWEEN 1 AND '.self::LEVELS;
        $sql .= "   AND COALESCE(TRIM(AR_Ref), '') = ''";
        $sql .= '   AND CL_No > 0 AND AG_No1 = 0';
        $sql .= "   AND AR_DateDebut <= '".$now."' AND AR_DateFin >= '".$now."'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $rate = (float) $obj->remise;
            if (abs($rate) < 0.0001) {
                $this->neutralFamilyRules++;
                continue;
            }

            $cat = (int) $obj->N_CatTarif;
            $cl  = (int) $obj->CL_No;

            // Une même famille ne devrait être citée qu'une fois par catégorie ; si elle
            // l'est deux fois, la remise la plus favorable au client l'emporte.
            if (!isset($this->familyRules[$cat][$cl]) || $rate > $this->familyRules[$cat][$cl]) {
                $this->familyRules[$cat][$cl] = $rate;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Charge les prix déjà en base, un par couple (produit, niveau).
     *
     * Le coeur relit chaque niveau par une requête distincte, soit huit par article — plus
     * de cent vingt mille pour le catalogue. Une seule lecture ordonnée suffit : les lignes
     * étant parcourues de la plus ancienne à la plus récente, la dernière écrase les
     * précédentes et c'est bien elle qui reste, exactement comme le
     * `ORDER BY date_price DESC, rowid DESC LIMIT 1` de `Product::fetch()`.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadExistingPrices()
    {
        $this->existingPrices = array();

        $sql  = 'SELECT fk_product, price_level, price, price_ttc, price_base_type';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_price';
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= ' ORDER BY fk_product ASC, price_level ASC, date_price ASC, rowid ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->existingPrices[(int) $obj->fk_product][(int) $obj->price_level] = array(
                'price'     => (float) $obj->price,
                'price_ttc' => (float) $obj->price_ttc,
                'base'      => (string) $obj->price_base_type,
            );
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Date à laquelle la validité des tarifs est appréciée.
     *
     * @return int Timestamp
     */
    protected function resolveDate()
    {
        return ($this->referenceDate > 0) ? $this->referenceDate : dol_now();
    }

    /**
     * Index des lignes déjà reprises : volontairement vide.
     *
     * `llx_product_price` n'a ni `ref_ext` utilisable ni `import_key` écrite par le coeur.
     * Renvoyer un index vide laisse `processRow()` appeler `migrateRow()` pour chaque
     * article ; c'est là que la comparaison des valeurs décide s'il y a quelque chose à
     * écrire. Toute autre solution supposerait un marqueur que le coeur n'écrit pas.
     *
     * @return int Toujours 0
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        return 0;
    }

    /**
     * Nombre d'articles dont les huit niveaux sont posés.
     *
     * Le comptage ne porte pas sur les lignes — un article peut en avoir plusieurs par
     * niveau, l'historique n'étant jamais purgé par le coeur — mais sur les articles dont
     * la grille est complète, seule mesure qui dise quelque chose de l'avancement.
     *
     * @return int Nombre d'articles complets, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM (';
        $sql .= '  SELECT pp.fk_product FROM '.MAIN_DB_PREFIX.'product_price as pp';
        $sql .= '  INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pp.fk_product';
        $sql .= "  WHERE p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= '  AND pp.entity IN ('.getEntity($this->dstElement).')';
        $sql .= '  GROUP BY pp.fk_product';
        $sql .= '  HAVING COUNT(DISTINCT pp.price_level) >= '.self::LEVELS;
        $sql .= ') as complets';

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Prix de base de l'article, sur lequel s'adossent tous les niveaux.
     *
     * La base de saisie de la source est conservée telle quelle plutôt que ramenée au hors
     * taxes : une remise en pourcentage est invariante par changement de base tant que la
     * TVA est commune aux niveaux, ce qui est le cas ici. Convertir ferait courir un écart
     * d'arrondi sur chacun des huit niveaux, sans rien apporter.
     *
     * @param stdClass $row Ligne de f_article
     * @return array{price:float,base:string,origin:string}
     */
    protected function basePrice($row)
    {
        return array(
            'price'  => (float) $row->AR_PrixVen,
            'base'   => !empty($row->AR_PrixTTC) ? 'TTC' : 'HT',
            'origin' => 'default',
        );
    }

    /**
     * Calcule le prix d'un niveau, par ordre de priorité décroissante.
     *
     *   1. prix fixe porté par la ligne de tarif de la catégorie ;
     *   2. remise portée par cette même ligne ;
     *   3. remise valant pour la famille de catalogue de l'article ;
     *   4. à défaut, le prix de base.
     *
     * @param string                                    $ref      Référence source de l'article
     * @param int                                       $cat      Catégorie tarifaire source
     * @param array{price:float,base:string,origin:string} $base   Prix de base de l'article
     * @param array<int,int>                            $families Familles de catalogue de l'article
     * @return array{price:float,base:string,origin:string}
     */
    protected function computeLevel($ref, $cat, array $base, array $families)
    {
        if ($cat > 0 && isset($this->tariffs[$ref][$cat])) {
            $line  = $this->tariffs[$ref][$cat];
            $price = (float) $line->AR_PrixVen;

            if (abs($price) > 0) {
                return array(
                    'price'  => $price,
                    'base'   => !empty($line->AR_PrixTTC) ? 'TTC' : 'HT',
                    'origin' => 'fixed',
                );
            }

            $rate = (float) $line->remise;
            if (abs($rate) > 0.0001) {
                return $this->applyDiscount($base, $rate, 'discount');
            }
        }

        // Règle de famille. Un article porte jusqu'à quatre niveaux de classification et
        // peut relever de plusieurs familles remisées : la remise la plus forte l'emporte,
        // ce qui rend le résultat indépendant de l'ordre des colonnes CL_No1..4.
        if ($cat > 0 && !empty($this->familyRules[$cat])) {
            $best = null;
            foreach ($families as $cl) {
                if (isset($this->familyRules[$cat][$cl])
                    && ($best === null || $this->familyRules[$cat][$cl] > $best)) {
                    $best = $this->familyRules[$cat][$cl];
                }
            }
            if ($best !== null) {
                return $this->applyDiscount($base, $best, 'family');
            }
        }

        return $base;
    }

    /**
     * Applique une remise en pourcentage au prix de base.
     *
     * ## L'arrondi au centime, et pourquoi il n'est pas facultatif
     *
     * L'ancien ERP ne stocke pas de prix remisé : il garde un taux et l'applique à la vente,
     * en arrondissant à l'affichage. Le multi-prix de Dolibarr, lui, exige une valeur figée.
     * 22,20 € moins 9 % donne 20,202 € — un prix catalogue à trois décimales, que la fiche
     * produit affichera « 20,20 » tout en facturant 20,202.
     *
     * Le prix est donc arrondi au centime, dans sa base de saisie. Deux raisons :
     * la grille tarifaire est éditable à l'écran, et une valeur non arrondie y serait
     * « corrigée » au premier passage, créant une divergence que rien ne signalerait ; et
     * un prix affiché doit être celui qu'on paie. L'écart avec le calcul exact reste de
     * l'ordre du centime, du même ordre que ce que produisait l'ancien ERP.
     *
     * Les prix fixes, eux, ne sont jamais retouchés : ils viennent de la source telle quelle.
     *
     * @param array{price:float,base:string,origin:string} $base   Prix de base
     * @param float                                        $rate   Remise en pourcentage
     * @param string                                       $origin Étiquette d'origine, pour le rapport
     * @return array{price:float,base:string,origin:string}
     */
    protected function applyDiscount(array $base, $rate, $origin)
    {
        if ($rate >= 100) {
            // Une remise de 100 % ou plus donne un prix nul, jamais négatif.
            $this->cappedDiscounts++;
            $rate = 100;
        } elseif ($rate < 0) {
            // Une remise négative est une majoration. La source en porte, jusqu'à −30 %.
            $this->markups++;
        }

        return array(
            'price'  => round((float) $base['price'] * (1 - ($rate / 100)), 2),
            'base'   => $base['base'],
            'origin' => $origin,
        );
    }

    /**
     * Calcule les huit niveaux d'un article.
     *
     * @param stdClass $row Ligne de f_article
     * @return array<int,array{price:float,base:string,origin:string}>
     */
    protected function computeLevels($row)
    {
        $ref  = trim((string) $row->AR_Ref);
        $base = $this->basePrice($row);

        $families = array();
        foreach (array('CL_No1', 'CL_No2', 'CL_No3', 'CL_No4') as $column) {
            $cl = isset($row->$column) ? (int) $row->$column : 0;
            if ($cl > 0) {
                $families[] = $cl;
            }
        }

        $levels = array();
        for ($level = 1; $level <= self::LEVELS; $level++) {
            $levels[$level] = $this->computeLevel($ref, aeromigration_price_category($level), $base, $families);
        }

        return $levels;
    }

    /**
     * Écrit les huit niveaux de l'article, en ne touchant que ceux qui changent.
     *
     * @param stdClass $row        Ligne de f_article
     * @param int      $existingId Toujours 0, l'index étant vide
     * @return array{action:string,id:int}
     * @throws Exception Si l'écriture d'un niveau échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $refExt = $this->buildRefExt($this->getSourceKey($row));

        if (!isset($this->productIndex[$refExt])) {
            $this->missingProducts++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $product   = $this->productIndex[$refExt];
        $productId = $product['id'];

        if ((float) $row->AR_PrixVen == 0) {
            // L'article n'a pas de prix dans la source. Ses niveaux seront à zéro, ce qui
            // est exact : rien ne permet d'en inventer un.
            $this->withoutBasePrice++;
        }

        $levels  = $this->computeLevels($row);
        $pending = array();

        foreach ($levels as $level => $target) {
            $this->origins[$target['origin']]++;

            $current = isset($this->existingPrices[$productId][$level])
                ? $this->existingPrices[$productId][$level]
                : null;

            if ($current !== null && $current['base'] === $target['base']) {
                $stored = ($target['base'] === 'TTC') ? $current['price_ttc'] : $current['price'];
                if (abs($stored - $target['price']) <= self::ROUNDING) {
                    continue;
                }
            }

            $pending[$level] = $target;
        }

        if (empty($pending)) {
            return array('action' => 'skipped', 'id' => $productId);
        }

        $isNew = empty($this->existingPrices[$productId]);

        $object = new Product($this->db);
        // Les prix sont déjà en mémoire et les expressions de prix ne sont pas utilisées :
        // les charger coûterait huit requêtes par article, pour rien.
        if ($object->fetch($productId, '', '', '', 1, 1, 1) <= 0) {
            throw new Exception('Article introuvable en cible (rowid '.$productId.') : '.$this->objectErrors($object));
        }

        // L'ordre compte : `updatePrice()` recopie le prix dans `llx_product` sans jamais
        // regarder le niveau qu'elle écrit (product.class.php:2868). Terminer par le
        // niveau 1 laisse donc le prix de base juste, y compris si le trigger de réalignement
        // d'aerotoolbox venait à être désactivé — et c'est ce champ que Prestasync publie.
        $order = array();
        for ($level = 2; $level <= self::LEVELS; $level++) {
            $order[] = $level;
        }
        $order[] = 1;

        foreach ($order as $level) {
            if (!isset($pending[$level])) {
                continue;
            }

            $target = $pending[$level];

            // $ignore_autogen = 1 : l'autogénération régénérerait les niveaux 2 à 8 à partir
            // du premier et écraserait tout le travail (product.class.php:2784).
            $result = $object->updatePrice(
                $target['price'],
                $target['base'],
                $this->user,
                $product['tva'],
                0,
                $level,
                0,
                0,
                1
            );

            if ($result <= 0) {
                throw new Exception('Échec de l\'écriture du niveau '.$level.' : '.$this->objectErrors($object));
            }

            $this->existingPrices[$productId][$level] = array(
                'price'     => ($target['base'] === 'TTC')
                    ? $target['price'] / (1 + ($product['tva'] / 100))
                    : $target['price'],
                'price_ttc' => ($target['base'] === 'TTC')
                    ? $target['price']
                    : $target['price'] * (1 + ($product['tva'] / 100)),
                'base'      => $target['base'],
            );

            $this->writtenLevels++;
        }

        return array('action' => $isNew ? 'created' : 'updated', 'id' => $productId);
    }

    /**
     * Contrôle une ligne en simulation : le calcul est mené, rien n'est écrit.
     *
     * @param stdClass $row Ligne de f_article
     * @return void
     * @throws Exception Si le calcul ne produit pas huit niveaux
     */
    protected function validateRow($row)
    {
        $levels = $this->computeLevels($row);

        if (count($levels) !== self::LEVELS) {
            throw new Exception('Calcul incomplet : '.count($levels).' niveaux au lieu de '.self::LEVELS);
        }
    }

    /**
     * Verdict d'une ligne en simulation.
     *
     * @param stdClass $row        Ligne de f_article
     * @param int      $existingId Toujours 0, l'index étant vide
     * @return string Action que le passage réel produirait
     */
    protected function previewAction($row, $existingId)
    {
        $refExt = $this->buildRefExt($this->getSourceKey($row));

        if (!isset($this->productIndex[$refExt])) {
            $this->missingProducts++;
            return 'skipped';
        }

        $productId = $this->productIndex[$refExt]['id'];

        if ((float) $row->AR_PrixVen == 0) {
            $this->withoutBasePrice++;
        }

        $levels  = $this->computeLevels($row);
        $pending = 0;

        foreach ($levels as $level => $target) {
            $this->origins[$target['origin']]++;

            $current = isset($this->existingPrices[$productId][$level])
                ? $this->existingPrices[$productId][$level]
                : null;

            if ($current !== null && $current['base'] === $target['base']) {
                $stored = ($target['base'] === 'TTC') ? $current['price_ttc'] : $current['price'];
                if (abs($stored - $target['price']) <= self::ROUNDING) {
                    continue;
                }
            }

            $pending++;
        }

        if ($pending === 0) {
            return 'skipped';
        }

        $this->writtenLevels += $pending;

        return empty($this->existingPrices[$productId]) ? 'created' : 'updated';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression de tous les prix de vente des articles repris : historique de '
            .MAIN_DB_PREFIX.'product_price et colonnes de prix de '.MAIN_DB_PREFIX.'product';
    }

    /**
     * Supprime les prix de vente des articles repris.
     *
     * ------------------------------------------------------------------------------
     * SQL DIRECT, ET C'EST ASSUMÉ
     * ------------------------------------------------------------------------------
     *
     * Le coeur n'offre aucun moyen de vider la grille tarifaire d'un article :
     * `Product::log_price_delete()` supprime une ligne d'historique à la fois et laisse
     * `llx_product` intact, si bien que le produit conserverait un prix de base sans plus
     * aucune ligne pour le justifier. La suppression relève ici de la remise à zéro
     * d'environnement, seul cas où la règle du projet admet le SQL direct.
     *
     * ⚠️ Entre cette purge et la fin du rejeu, `llx_product.price` vaut zéro. **Prestasync
     * doit être suspendu**, faute de quoi la boutique publierait un catalogue à zéro euro.
     *
     * Les articles qui ne viennent pas de la reprise — ceux de la boutique, notamment — ne
     * sont pas touchés : leur prix ne nous appartient pas.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        // Le périmètre sert deux fois, sous deux formes. MySQL refuse qu'un UPDATE lise en
        // sous-requête la table qu'il modifie : la mise à zéro de llx_product s'appuie donc
        // sur la condition elle-même, et non sur la liste des rowid.
        $where  = ' entity IN ('.getEntity('product').')';
        $where .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $scope  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE'.$where;

        $sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product_price';
        $sql  .= ' WHERE fk_product IN ('.$scope.')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $result['count'] = (int) $obj->nb;
        if (!$confirm || $result['count'] === 0) {
            return $result;
        }

        $this->db->begin();

        // Les prix par quantité pendent à l'historique : les laisser produirait des lignes
        // orphelines que plus rien ne rattache.
        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'product_price_by_qty WHERE fk_product_price IN ('
            .'SELECT rowid FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product IN ('.$scope.'))';
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            $result['errors'][] = $this->db->lasterror();
            $result['failed']   = $result['count'];
            return $result;
        }

        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product IN ('.$scope.')';
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            $result['errors'][] = $this->db->lasterror();
            $result['failed']   = $result['count'];
            return $result;
        }

        $sql  = 'UPDATE '.MAIN_DB_PREFIX.'product SET price = 0, price_ttc = 0,';
        $sql .= ' price_min = 0, price_min_ttc = 0';
        $sql .= ' WHERE'.$where;
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            $result['errors'][] = $this->db->lasterror();
            $result['failed']   = $result['count'];
            return $result;
        }

        $this->db->commit();

        $result['deleted'] = $result['count'];

        if (is_callable($progress)) {
            call_user_func($progress, $result['deleted'], $result['count']);
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

        $lines[] = 'Niveaux écrits : '.number_format($this->writtenLevels, 0, ',', ' ');

        $libelles = array(
            'fixed'    => 'prix fixe porté par la ligne de tarif',
            'discount' => 'remise portée par la ligne de tarif',
            'family'   => 'remise valant pour la famille de catalogue',
            'default'  => 'prix de base de la fiche article, aucune dérogation',
        );
        $lines[] = 'Origine des niveaux calculés :';
        foreach ($libelles as $key => $libelle) {
            $lines[] = '  '.str_pad(number_format($this->origins[$key], 0, ',', ' '), 10, ' ', STR_PAD_LEFT)
                .'  '.$libelle;
        }

        if ($this->missingProducts > 0) {
            $lines[] = '  '.str_pad((string) $this->missingProducts, 10, ' ', STR_PAD_LEFT)
                .'  articles absents du catalogue Dolibarr : relancez « migrate.php product »';
        }
        if ($this->withoutBasePrice > 0) {
            $lines[] = '  '.str_pad((string) $this->withoutBasePrice, 10, ' ', STR_PAD_LEFT)
                .'  articles sans prix dans la source : leurs huit niveaux sont à zéro';
        }
        if ($this->cappedDiscounts > 0) {
            $lines[] = '  '.str_pad((string) $this->cappedDiscounts, 10, ' ', STR_PAD_LEFT)
                .'  remises de 100 % ou plus, ramenées à un prix nul';
        }
        if ($this->markups > 0) {
            $lines[] = '  '.str_pad((string) $this->markups, 10, ' ', STR_PAD_LEFT)
                .'  remises négatives, appliquées comme des majorations';
        }
        if ($this->neutralFamilyRules > 0) {
            $lines[] = '  '.str_pad((string) $this->neutralFamilyRules, 10, ' ', STR_PAD_LEFT)
                .'  règles par famille sans effet (remise nulle), écartées';
        }

        if (!empty($this->discarded)) {
            $lines[] = 'Lignes de tarif écartées à la lecture :';
            foreach ($this->discarded as $libelle => $nb) {
                $lines[] = '  '.str_pad(number_format($nb, 0, ',', ' '), 10, ' ', STR_PAD_LEFT).'  '.$libelle;
            }
        }

        // Grille obtenue, telle que l'écran des prix l'affichera.
        $sql  = 'SELECT pp.price_level as lvl, COUNT(DISTINCT pp.fk_product) as nb';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_price as pp';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pp.fk_product';
        $sql .= " WHERE p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' AND pp.entity IN ('.getEntity($this->dstElement).')';
        $sql .= ' GROUP BY pp.price_level ORDER BY pp.price_level';

        $resql = $this->db->query($sql);
        if ($resql) {
            $lines[] = 'Articles tarifés, par niveau :';
            while ($obj = $this->db->fetch_object($resql)) {
                $label = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.(int) $obj->lvl);
                $lines[] = '  '.str_pad(number_format($obj->nb, 0, ',', ' '), 10, ' ', STR_PAD_LEFT)
                    .'  niveau '.$obj->lvl.($label !== '' ? ' — '.$label : '');
            }
            $this->db->free($resql);
        }

        return $lines;
    }
}
