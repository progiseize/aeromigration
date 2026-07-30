<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationproduct.class.php
 * \ingroup aeromigration
 * \brief   Reprise des articles : f_article -> objets Product de Dolibarr.
 *
 * La table est parcourue en entier : les articles déjà remontés par la boutique sont
 * complétés, les autres créés. Trois traitements se distinguent selon l'origine :
 *
 *   1. `ref_ext` déjà posé          → la reprise l'a déjà traité
 *   2. lien dans llx_prestasync_product → adoption, seuls les champs vides sont remplis
 *   3. référence déjà portée par un produit → adoption également, garde-fou contre les
 *      doublons lorsqu'un lien boutique manque
 *   4. aucun des trois              → création, puis déclaration à la boutique
 *
 * Le troisième cas n'est pas théorique : sans lui, un passage sur la source complète avec
 * une table de liaison incomplète créerait près de 15 000 produits en double.
 *
 * Trois particularités de la source méritent d'être connues :
 *
 * - **Les champs libres sont doublement encodés.** `PA_ChampLibre_Intitule_2` stocke
 *   « ArrÃªt Ã  Ã©puisement du stock » là où on attend « Arrêt à épuisement du stock » :
 *   l'UTF-8 a été encodé une seconde fois. 306 valeurs sur le champ 2, 13 sur le champ 3,
 *   2 sur le champ 4. `AR_Design` en est indemne.
 *
 * - **La disponibilité et le suivi vivent dans les champs libres**, pas dans les colonnes
 *   qui semblent prévues pour. `disponibilite_origine` et `suivi_origine` sont des champs
 *   de travail du connecteur boutique, incohérents avec les libellés : le même
 *   « COMMERCIALISATION_ARRETEE » y apparaît tantôt en 0, tantôt en 6.
 *
 * - **Le taux de TVA n'est pas dans la table.** Il découle de la famille (`FA_CodeFamille`),
 *   dont le référentiel Sage n'a pas été importé. La correspondance V20 → 20 % et
 *   V5 → 5,5 % a été arrêtée avec le client.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

class MigrationProduct extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'product';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptProduct';

    /** @var string Table source */
    protected $srcTable = 'f_article';

    /** @var string Colonne de parcours : la clé primaire de f_article */
    protected $srcCursorField = 'AR_Ref';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle de l'article dans la source */
    protected $srcKeyField = 'AR_Ref';

    /** @var string La table comporte une ligne technique sans référence */
    protected $srcWhere = "TRIM(AR_Ref) <> ''";

    /** @var int Identifiant de la boutique dans les tables de liaison */
    protected $prestaShopId = 1;

    /** @var array<string,int> Référence produit -> rowid, pour le rapprochement de secours */
    protected $productByRef = array();

    /** @var int Produits rattachés par leur référence, faute de lien boutique */
    protected $matchedByRef = 0;

    /** @var array<string,bool> Identifiants boutique déjà liés à un produit */
    protected $prestaIdsLinked = array();

    /** @var int Liens boutique créés durant le passage */
    protected $prestaLinksCreated = 0;

    /** @var int Produits non liés : leur identifiant boutique est déjà attribué */
    protected $prestaIdConflicts = 0;

    /** @var int Produits créés sans identifiant boutique exploitable */
    protected $withoutPrestaId = 0;

    /** @var bool La table de liaison des produits est-elle disponible ? */
    protected $prestaLinkAvailable = false;

    /** @var array<string,int> id produit boutique -> rowid du produit Dolibarr */
    protected $productByPrestaId = array();

    /** @var array<int,bool> Produits déjà adoptés, pour n'en adopter aucun deux fois */
    protected $adoptedProductIds = array();


    /** @var string Table Dolibarr cible */
    protected $dstTable = 'product';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'product';

    /**
     * Taux de TVA par famille Sage.
     *
     * Le référentiel des familles n'ayant pas été importé, la correspondance a été
     * arrêtée avec le client : taux réduit du livre pour V5, taux normal ailleurs.
     *
     * @var array<string,float>
     */
    protected $vatByFamily = array(
        'V20' => 20,
        'V5'  => 5.5,
    );

    /** @var float Taux appliqué aux familles hors correspondance (AREAFFECTER, DIVERS) */
    protected $defaultVat = 20;

    /**
     * Préfixe des catégories créées à partir des familles d'articles.
     *
     * « ADD » est le nom de l'ancien ERP tel que le client le désigne : les catégories
     * lui parlent ainsi davantage qu'une référence à l'éditeur du logiciel.
     *
     * @var string
     */
    protected $categoryPrefix = 'ADD';

    /**
     * Correspondance des libellés de disponibilité vers le dictionnaire du module
     * aerotoolbox. Clés normalisées (minuscules, sans accent, ponctuation réduite).
     *
     * @var array<string,string>
     */
    protected $availabilityAliases = array(
        'disponible'                  => 'DISPO',
        'sur commande'                => 'SUR_CMD',
        'uniqumenent en magasin'      => 'MAGASIN', // faute de frappe présente dans la source
        'uniquement en magasin'       => 'MAGASIN',
        'indisponible temporairement' => 'INDISPO_TMP',
        'indisponible definitivement' => 'INDISPO_DEF',
        'commercialisation arretee'   => 'COM_ARRET',
        'commercialisation suspendue' => 'COM_SUSPENDU',
        'prestation'                  => 'PRESTATION',
        'composant uniquement'        => 'COMPOSANT',
        'produit composant uniquement' => 'COMPOSANT',
        'en cours de referencement'   => 'REFERENCEMENT',
    );

    /**
     * Correspondance des libellés de suivi vers le dictionnaire du module aerotoolbox.
     *
     * @var array<string,string>
     */
    protected $trackingAliases = array(
        'suivi'                       => 'SUIVI',
        'non suivi'                   => 'NON_SUIVI',
        'arret a epuisement du stock' => 'ARRET_STOCK',
    );

    /** @var array<string,int> Code du dictionnaire de disponibilité -> rowid */
    protected $availabilityByCode = array();

    /** @var array<string,int> Code du dictionnaire de suivi -> rowid */
    protected $trackingByCode = array();

    /** @var array<string,int> Code famille Sage -> rowid de la catégorie produit */
    protected $categoryByFamily = array();

    /** @var array<int,int> CL_No du catalogue -> rowid de la catégorie Dolibarr */
    protected $categoryByClNo = array();

    /** @var array<int,bool> Catégories du catalogue introuvables, signalées une fois */
    protected $missingCatalogue = array();

    /**
     * Codes-barres déjà attribués : valeur -> ref_ext du produit qui la porte.
     *
     * Le porteur est mémorisé, et non un simple drapeau : un produit déjà repris porte
     * légitimement son propre code-barres, ce n'est pas un conflit. Sans cette distinction,
     * un second passage écarterait les 8 700 codes-barres qu'il vient lui-même de poser.
     *
     * @var array<string,string>
     */
    protected $barcodesInUse = array();

    /** @var int Codes-barres écartés parce que déjà portés par un autre produit */
    protected $barcodeDuplicated = 0;

    /** @var array<string,int> Valeurs de code-barres écartées car non significatives */
    protected $barcodeInvalid = array();

    /** @var array<string,int> Libellés de disponibilité non reconnus */
    protected $unresolvedAvailability = array();

    /** @var array<string,int> Libellés de suivi non reconnus */
    protected $unresolvedTracking = array();

    /**
     * Charge les dictionnaires et prépare les catégories de familles.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        foreach (array(
            'c_aerotoolbox_availability' => 'availabilityByCode',
            'c_aerotoolbox_tracking'     => 'trackingByCode',
        ) as $table => $property) {
            $sql   = 'SELECT rowid, code FROM '.MAIN_DB_PREFIX.$table.' WHERE active = 1';
            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->errors[] = array(
                    'key'     => '',
                    'message' => 'Dictionnaire '.$table.' introuvable : le module aerotoolbox est-il activé ? ('.$this->db->lasterror().')',
                );
                return -1;
            }
            while ($obj = $this->db->fetch_object($resql)) {
                $this->{$property}[$obj->code] = (int) $obj->rowid;
            }
            $this->db->free($resql);
        }

        if ($this->loadPrestaLinks() < 0) {
            return -1;
        }

        if ($this->loadProductRefs() < 0) {
            return -1;
        }

        if ($this->loadBarcodes() < 0) {
            return -1;
        }

        if ($this->loadCatalogueCategories() < 0) {
            return -1;
        }

        return $this->prepareCategories();
    }

    /**
     * Charge les catégories du catalogue déjà reprises.
     *
     * Ce sont celles que le script `category` a créées à partir de `f_catalogue`, et
     * auxquelles les articles se rattachent par `CL_No1`, `CL_No2` et `CL_No3`. Le
     * marqueur de reprise porte le CL_No d'origine, ce qui suffit à les retrouver.
     *
     * L'absence de catégories n'est pas bloquante — un article sans classement reste un
     * article valide — mais elle est signalée : elle trahit presque toujours un `category`
     * qu'on a oublié de passer avant.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadCatalogueCategories()
    {
        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'categorie';
        $sql .= ' WHERE entity IN ('.getEntity('category').') AND type = 0';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $clNo = (int) substr($obj->ref_ext, strlen($this->refExtPrefix));
            if ($clNo > 0) {
                $this->categoryByClNo[$clNo] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        if (empty($this->categoryByClNo)) {
            dol_syslog('MigrationProduct : aucune catégorie de catalogue reprise, lancez « migrate.php category » avant', LOG_WARNING);
        }

        return 1;
    }

    /**
     * Charge les références des produits existants, pour le rapprochement de secours.
     *
     * Un article dont le lien boutique manque serait sinon créé en double. On vérifie
     * donc, avant toute création, qu'aucun produit ne porte déjà cette référence — sous
     * sa forme d'origine comme sous sa forme normalisée.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductRefs()
    {
        $sql  = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND (ref_ext IS NULL OR ref_ext NOT LIKE '".$this->db->escape($this->refExtPrefix)."%')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productByRef[(string) $obj->ref] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Charge les codes-barres déjà attribués.
     *
     * Dolibarr impose l'unicité du code-barres sur toute l'entité
     * (`uk_product_barcode`), et refuse la fiche entière lorsqu'elle en porte un déjà pris
     * — pas seulement le champ. Un doublon fait donc échouer la création ou l'adoption du
     * produit, avec le reste de ses données.
     *
     * La source en compte 8 706 pour 8 637 valeurs distinctes : 69 doublons, dont un
     * « à compléter » partagé par cinq articles.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadBarcodes()
    {
        $sql  = 'SELECT barcode, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').")";
        $sql .= " AND barcode IS NOT NULL AND TRIM(barcode) <> ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->barcodesInUse[trim((string) $obj->barcode)] = (string) $obj->ref_ext;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Code-barres exploitable d'un article, ou chaîne vide.
     *
     * La colonne sert aussi de pense-bête dans la source : « à compléter », « code barre »,
     * ou des références fournisseur comme « PNR ASA-AVIDYNE ». Un code-barres ne comporte
     * ni espace ni lettre seule : ces deux règles suffisent à écarter le bruit sans risquer
     * d'exclure une valeur légitime, les 8 572 codes réels faisant 12 ou 13 caractères.
     *
     * @param string $value Valeur brute
     * @return string       Code-barres exploitable, chaîne vide sinon
     */
    protected function cleanBarcode($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (strpos($value, ' ') !== false || !preg_match('/[0-9]/', $value)) {
            if (!isset($this->barcodeInvalid[$value])) {
                $this->barcodeInvalid[$value] = 0;
            }
            $this->barcodeInvalid[$value]++;
            return '';
        }

        return $value;
    }

    /**
     * Cherche un produit existant portant la référence de l'article.
     *
     * Les deux formes sont testées : celle de la source (« 00001 ») et celle appliquée
     * aux créations (« #00001 »), le produit ayant pu être créé par l'une ou l'autre voie.
     *
     * @param stdClass $row Ligne source
     * @return int          rowid du produit, 0 si aucun
     */
    protected function findProductByRef($row)
    {
        $ref = trim((string) $row->AR_Ref);
        if ($ref === '') {
            return 0;
        }

        foreach (array($ref, $this->formatRef($ref)) as $candidate) {
            if (isset($this->productByRef[$candidate])) {
                return $this->productByRef[$candidate];
            }
        }

        return 0;
    }

    /**
     * Charge les correspondances avec les produits de la boutique en ligne.
     *
     * Comme pour les tiers, l'index va de l'identifiant boutique vers le produit :
     * plusieurs liens peuvent désigner le même produit. Les déclinaisons sont écartées
     * (`fk_product_presta_attribute` non nul), seul le produit simple nous intéresse.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadPrestaLinks()
    {
        $table = MAIN_DB_PREFIX.'prestasync_product';

        if (!$this->db->query('SELECT rowid FROM '.$table.' LIMIT 1', 1)) {
            dol_syslog('MigrationProduct : table '.$table.' absente, aucune adoption possible', LOG_NOTICE);
            return 1;
        }

        $this->prestaLinkAvailable = true;

        $sql  = 'SELECT l.fk_product_presta, l.fk_product_doli FROM '.$table.' as l';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = l.fk_product_doli';
        $sql .= ' WHERE l.fk_presta = '.((int) $this->prestaShopId);
        $sql .= ' AND l.fk_product_presta_attribute = 0';
        $sql .= ' AND p.entity IN ('.getEntity('product').')';
        $sql .= " AND (p.ref_ext IS NULL OR p.ref_ext NOT LIKE '".$this->db->escape($this->refExtPrefix)."%')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productByPrestaId[(string) $obj->fk_product_presta] = (int) $obj->fk_product_doli;
        }
        $this->db->free($resql);

        // Tous les identifiants boutique déjà employés, y compris ceux dont le produit a
        // été repris : un identifiant ne doit jamais désigner deux produits.
        $sql   = 'SELECT fk_product_presta FROM '.$table.' WHERE fk_presta = '.((int) $this->prestaShopId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->prestaIdsLinked[(string) $obj->fk_product_presta] = true;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Déclare le produit auprès de la boutique en ligne.
     *
     * Même intention que pour les tiers : sans ce lien, un produit repris qui viendrait
     * ensuite à être synchronisé serait créé une seconde fois par le module. L'insertion
     * reproduit ce que fait PrestaProduct::setCustomDolLink(), un simple INSERT sans
     * traitement annexe.
     *
     * `fk_product_presta_attribute` vaut 0 : il désigne le produit lui-même, non l'une de
     * ses déclinaisons.
     *
     * @param int      $productId rowid du produit créé
     * @param stdClass $row       Ligne source
     * @return void
     * @throws Exception Si l'insertion échoue
     */
    protected function registerPrestaLink($productId, $row)
    {
        if (!$this->prestaLinkAvailable || $productId <= 0) {
            return;
        }

        $prestaId = $this->getPrestaProductId($row);
        if ($prestaId === '') {
            // Les articles absents de la boutique n'ont pas d'identifiant : rien à lier.
            $this->withoutPrestaId++;
            return;
        }

        if (isset($this->prestaIdsLinked[$prestaId])) {
            $this->prestaIdConflicts++;
            return;
        }

        $sql  = 'INSERT INTO '.MAIN_DB_PREFIX.'prestasync_product';
        $sql .= ' (fk_presta, fk_product_presta, fk_product_doli, fk_product_presta_attribute, date_creation, tms)';
        $sql .= ' VALUES ('.((int) $this->prestaShopId).', '.((int) $prestaId).', '.((int) $productId).', 0, NOW(), NOW())';

        if (!$this->db->query($sql)) {
            throw new Exception('Échec de l\'enregistrement du lien boutique : '.$this->db->lasterror());
        }

        $this->prestaIdsLinked[$prestaId] = true;
        $this->prestaLinksCreated++;
    }

    /**
     * Identifiant du produit dans la boutique, pour une ligne source.
     *
     * @param stdClass $row Ligne source
     * @return string       Identifiant, chaîne vide si inexploitable
     */
    protected function getPrestaProductId($row)
    {
        $id = trim((string) $row->id_externe);

        if ($id === '' || $id === '0' || !ctype_digit($id)) {
            return '';
        }

        return $id;
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
        if ($existingId > 0) {
            return 'updated';
        }

        $prestaId = $this->getPrestaProductId($row);
        if ($prestaId !== '' && isset($this->productByPrestaId[$prestaId])) {
            return 'adopted';
        }

        if ($this->findProductByRef($row) > 0) {
            return 'adopted';
        }

        return 'created';
    }

    /**
     * Crée si besoin une catégorie produit par famille Sage, et mémorise leur rowid.
     *
     * Les catégories passent par l'API Categorie : elles restent ainsi manipulables
     * normalement par le client, qui pourra les renommer ou les réorganiser.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepareCategories()
    {
        $sql   = "SELECT DISTINCT FA_CodeFamille FROM ".$this->srcTable;
        $sql  .= " WHERE TRIM(COALESCE(FA_CodeFamille,'')) <> ''";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $families = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $families[] = $obj->FA_CodeFamille;
        }
        $this->db->free($resql);

        foreach ($families as $family) {
            $label = $this->categoryPrefix.' '.$family;

            $categorie = new Categorie($this->db);
            $existing  = $categorie->fetch(0, $label, Categorie::TYPE_PRODUCT);

            if ($existing > 0) {
                $this->categoryByFamily[$family] = (int) $categorie->id;
                continue;
            }

            if ($this->dryrun) {
                // En simulation, on ne crée rien : l'absence de catégorie ne doit pas
                // faire échouer le contrôle du mapping.
                $this->categoryByFamily[$family] = 0;
                continue;
            }

            $categorie              = new Categorie($this->db);
            $categorie->label       = $label;
            $categorie->type        = Categorie::TYPE_PRODUCT;
            $categorie->description = 'Famille d\'articles reprise de l\'ancien ERP (ADD)';
            $categorie->visible     = 1;

            if ($categorie->create($this->user) <= 0) {
                $this->errors[] = array(
                    'key'     => '',
                    'message' => 'Échec de la création de la catégorie '.$label.' : '.$this->objectErrors($categorie),
                );
                return -1;
            }

            $this->categoryByFamily[$family] = (int) $categorie->id;
        }

        return 1;
    }

    /**
     * Crée ou met à jour le produit correspondant à une ligne de f_article.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du produit déjà migré, 0 si création
     * @return array{action:string,id:int}
     * @throws Exception Si la création ou la mise à jour échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $product = new Product($this->db);

        if ($existingId > 0) {
            if ($product->fetch($existingId) <= 0) {
                throw new Exception('Produit introuvable (rowid '.$existingId.') : '.$this->objectErrors($product));
            }

            $this->mapFields($product, $row);

            if ($product->update($existingId, $this->user) <= 0) {
                throw new Exception('Échec de la mise à jour : '.$this->objectErrors($product));
            }

            $this->applyCategory($product, $row);

            return array('action' => 'updated', 'id' => $existingId);
        }

        // ── Adoption d'un produit venu de la boutique ──────────────────────
        // Le produit existe déjà : la boutique l'a créé, mais sans les caractéristiques
        // que seul l'ancien ERP porte. On complète ce qui manque, sans rien écraser.
        $prestaId  = $this->getPrestaProductId($row);
        $adoptedId = ($prestaId !== '' && isset($this->productByPrestaId[$prestaId]))
            ? $this->productByPrestaId[$prestaId]
            : 0;

        // Rapprochement de secours : un lien boutique peut manquer alors que le produit
        // existe bel et bien. Le créer une seconde fois produirait un doublon.
        if ($adoptedId <= 0) {
            $adoptedId = $this->findProductByRef($row);
            if ($adoptedId > 0) {
                $this->matchedByRef++;
            }
        }

        if ($adoptedId > 0 && isset($this->adoptedProductIds[$adoptedId])) {
            $adoptedId = 0;
        }

        if ($adoptedId > 0) {
            if ($product->fetch($adoptedId) <= 0) {
                throw new Exception('Produit boutique introuvable (rowid '.$adoptedId.') : '.$this->objectErrors($product));
            }

            $this->mapFields($product, $row, true);
            $product->ref_ext = $this->buildRefExt($this->getSourceKey($row));

            if ($product->update($adoptedId, $this->user) <= 0) {
                throw new Exception('Échec de l\'adoption : '.$this->objectErrors($product));
            }

            $this->applyCategory($product, $row);

            unset($this->productByPrestaId[$prestaId]);
            $this->adoptedProductIds[$adoptedId] = true;

            return array('action' => 'adopted', 'id' => $adoptedId);
        }

        // ── Création ───────────────────────────────────────────────────────
        // L'article n'est ni lié à la boutique, ni déjà présent sous sa référence : il
        // n'existe donc nulle part dans Dolibarr.
        $this->mapFields($product, $row);
        $product->ref_ext = $this->buildRefExt($this->getSourceKey($row));

        if ($product->create($this->user) <= 0) {
            throw new Exception('Échec de la création : '.$this->objectErrors($product));
        }

        $this->applyCategory($product, $row);

        // Le cache des références suit les créations : si la source contient deux fois la
        // même référence, la seconde sera rattachée au lieu d'échouer sur l'unicité.
        $this->productByRef[$product->ref] = (int) $product->id;

        // Déclaration à la boutique, pour qu'une synchronisation ultérieure retrouve ce
        // produit au lieu d'en créer un second.
        $this->registerPrestaLink((int) $product->id, $row);

        return array('action' => 'created', 'id' => (int) $product->id);
    }

    /**
     * Contrôle une ligne en simulation, sans rien persister.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de construire un produit valide
     */
    protected function validateRow($row)
    {
        $product = new Product($this->db);
        $this->mapFields($product, $row);
    }

    /**
     * Reporte les champs de la source sur l'objet Product.
     *
     * @param Product  $product  Objet à alimenter
     * @param stdClass $row      Ligne source
     * @param bool     $fillOnly Mode complétion : ne renseigner que les champs vides
     * @return void
     * @throws Exception Si la ligne source est inexploitable
     */
    protected function mapFields(Product $product, $row, $fillOnly = false)
    {
        $ref = trim((string) $row->AR_Ref);
        if ($ref === '') {
            throw new Exception('Référence article absente');
        }

        // Référence et libellé d'un produit adopté ne sont jamais remplacés : ce sont
        // ceux sous lesquels la boutique et ses clients le connaissent.
        if (!$fillOnly) {
            $product->ref = $this->formatRef($ref);

            // Le libellé est obligatoire : à défaut, la référence en tient lieu.
            $label          = trim((string) $row->AR_Design);
            $product->label = ($label !== '') ? $label : $ref;
        }

        // ── Nature ─────────────────────────────────────────────────────────
        // Un seul article est marqué PRESTATION dans la source : il devient un service,
        // que Dolibarr ne gère pas en stock. Le type d'un produit adopté n'est pas
        // touché : le changer bouleverserait sa gestion de stock.
        $availability = $this->resolveDictionary($row->PA_ChampLibre_Intitule_1, 'availability');
        if (!$fillOnly) {
            $product->type = ($availability === 'PRESTATION') ? Product::TYPE_SERVICE : Product::TYPE_PRODUCT;
        }

        // ── Tarification ───────────────────────────────────────────────────
        // Le taux découle de la famille : le référentiel Sage des familles n'a pas été
        // importé, la correspondance a été arrêtée avec le client.
        $family = trim((string) $row->FA_CodeFamille);
        $vat    = isset($this->vatByFamily[$family]) ? $this->vatByFamily[$family] : $this->defaultVat;

        // Les prix d'un produit adopté font autorité : ce sont ceux de la boutique.
        if (!$fillOnly) {
            $product->tva_tx  = $vat;
            $product->tva_npr = 0;

            $price = (float) $row->AR_PrixVen;
            if ($price > 0) {
                // AR_PrixTTC indique si le prix a été saisi toutes taxes comprises.
                // Dolibarr sait recevoir l'un ou l'autre et recalcule le second.
                $product->price_base_type = !empty($row->AR_PrixTTC) ? 'TTC' : 'HT';
                if ($product->price_base_type === 'TTC') {
                    $product->price_ttc = $price;
                    $product->price     = $price / (1 + ($vat / 100));
                } else {
                    $product->price     = $price;
                    $product->price_ttc = $price * (1 + ($vat / 100));
                }
            }
        }

        // ── Coût de revient ────────────────────────────────────────────────
        // Dolibarr n'a qu'un `cost_price`, là où la source distingue trois notions : le
        // prix d'achat brut (AR_PrixAch), le prix de revient unitaire (AR_PrixRU) et le
        // coût standard (AR_CoutStd). Le prix d'achat relève du couple produit/fournisseur
        // et est repris par le script `supplierprice` ; restent les deux autres.
        //
        // C'est le COÛT STANDARD qui est retenu, et non le prix de revient malgré son nom
        // plus prometteur : celui-ci n'est jamais recalculé quand le tarif fournisseur
        // change. Sur les 13 814 articles ayant un fournisseur principal tarifé, 2 767
        // portent un prix de revient INFÉRIEUR à leur propre prix d'achat — l'article
        // 10514 est à 35,26 € pour un achat à 75,92 €. Le coût standard, lui, suit : il
        // égale exactement le prix d'achat sur 11 836 articles.
        //
        // Trois mesures départagent les deux colonnes :
        //
        // - propreté : 14 906 valeurs exploitables contre 13 152, et aucune sous le centime
        //   contre 1 628 — jusqu'à 0,000001 € pour un article à 4,19 € ;
        // - cohérence avec le prix payé : le coût descend sous le prix d'achat net 1 097 fois
        //   pour le coût standard, 1 379 fois pour le prix de revient, qui tombe de surcroît
        //   à la moitié exacte du prix payé sur des dizaines d'articles ;
        // - prise en compte de la remise, la seule mesure contrôlable à l'écran de l'ancien
        //   ERP : sur les 53 articles à remise connue, le coût standard reproduit le prix
        //   d'achat NET 15 fois contre 11. L'article 13566 est exemplaire — achat brut 39,00,
        //   remise 30 %, net 27,30, et AR_CoutStd vaut 27,30 au centime.
        //
        // Un coût sous-évalué gonfle la marge affichée par Dolibarr, qui s'appuie sur ce
        // champ. Le prix de revient ne sert donc que de repli, pour les 14 articles sans
        // coût standard exploitable ; 837 articles n'ont ni l'un ni l'autre.
        //
        // À ne PAS reprendre comme argument : le coefficient prix de vente / coût, qui donne
        // « plusieurs millions » pour AR_PrixRU. Le chiffre est exact mais n'est que l'effet
        // des divisions par les 1 628 valeurs microscopiques ; hors celles-ci, les deux
        // colonnes donnent 1,93 et 2,00. Voir ANOMALIES.md, A7.
        $cost = (float) $row->AR_CoutStd;
        if ($cost < 0.01) {
            $cost = (float) $row->AR_PrixRU;
        }

        if ($cost >= 0.01 && (!$fillOnly || empty($product->cost_price))) {
            $product->cost_price = $cost;
        }

        // ── Caractéristiques ───────────────────────────────────────────────
        // C'est ici que la reprise apporte le plus aux produits de la boutique : poids et
        // code-barres n'y figurent généralement pas.
        $weight = (float) $row->AR_PoidsNet;
        if ($weight <= 0) {
            $weight = (float) $row->AR_PoidsBrut;
        }
        if ($weight > 0 && (!$fillOnly || empty($product->weight))) {
            $product->weight       = $weight;
            $product->weight_units = 0; // kilogramme
        }

        // Le code-barres n'est posé que s'il est encore libre : Dolibarr en impose
        // l'unicité et refuse la fiche entière lorsqu'il est déjà pris, faisant perdre
        // toutes les autres données de l'article pour un champ accessoire.
        $barcode = $this->cleanBarcode($row->AR_CodeBarre);
        if ($barcode !== '' && (!$fillOnly || empty($product->barcode))) {
            $expected = $this->buildRefExt($ref);
            $owner    = isset($this->barcodesInUse[$barcode]) ? $this->barcodesInUse[$barcode] : '';

            // Le porteur actuel est-il ce même article ? Alors il n'y a pas de conflit :
            // c'est un second passage sur un produit déjà repris.
            if ($owner !== '' && $owner !== $expected) {
                $this->barcodeDuplicated++;
            } else {
                $product->barcode = $barcode;
                $this->barcodesInUse[$barcode] = $expected;
            }
        }

        // ── Statut ─────────────────────────────────────────────────────────
        // AR_Sommeil marque les articles mis en sommeil : logique inversée par rapport à
        // Dolibarr, où status = 1 signifie « en vente ». Le statut d'un produit adopté
        // n'est pas modifié : retirer de la vente un article actif en boutique aurait des
        // conséquences immédiates.
        if (!$fillOnly) {
            $product->status     = empty($row->AR_Sommeil) ? 1 : 0;
            $product->status_buy = empty($row->AR_Sommeil) ? 1 : 0;
        }

        // ── Code douanier ──────────────────────────────────────────────────
        // `lib_fiscal` porte la nomenclature douanière : 4 725 articles en NC8 (huit
        // chiffres, « 49011000 » pour les livres) et une centaine en TARIC, plus long.
        // Les valeurs « undefined » (5 655) sont des non-renseignés déguisés.
        $customCode = $this->fixDoubleEncoding($row->lib_fiscal);
        if ($customCode !== '' && strcasecmp($customCode, 'undefined') !== 0
            && (!$fillOnly || empty($product->customcode))) {
            $product->customcode = $customCode;
        }

        // ── Garantie ───────────────────────────────────────────────────────
        // `sous_garantie` est un simple drapeau : « O » pour 227 articles, « N » pour
        // 8 054, vide pour le reste. La source ne porte aucune durée — `AR_Garantie` est
        // NULL sur 15 799 lignes et vaut 0 sur les 13 autres —, l'extrafield
        // `aerotb_warranty_months` reste donc à renseigner par le client.
        $warranty = strtoupper(trim((string) $row->sous_garantie));
        if (($warranty === 'O' || $warranty === 'N')
            && (!$fillOnly || !isset($product->array_options['options_aerotb_warranty'])
                || $product->array_options['options_aerotb_warranty'] === '')) {
            $product->array_options['options_aerotb_warranty'] = ($warranty === 'O') ? 1 : 0;
        }

        // ── Ouvrage : auteur et éditeur ────────────────────────────────────
        // Le fonds est très largement composé de livres : ces deux champs libres portent
        // 2 923 auteurs et 3 270 éditeurs réels. Les « #N/A » sont des non-renseignés
        // déguisés, comme les « undefined » du code douanier.
        $author = $this->cleanFreeField($row->PA_ChampLibre_Intitule_4);
        if ($author !== '' && (!$fillOnly || empty($product->array_options['options_aerotb_auteur']))) {
            $product->array_options['options_aerotb_auteur'] = $author;
        }

        $editor = $this->cleanFreeField($row->PA_ChampLibre_Intitule_3);
        if ($editor !== '' && (!$fillOnly || empty($product->array_options['options_aerotb_editeur']))) {
            $product->array_options['options_aerotb_editeur'] = $editor;
        }

        // Date de création d'origine : sans objet pour un produit qui existe déjà.
        if (!$fillOnly && !empty($row->AR_DateCreation)) {
            $date = $this->db->jdate($row->AR_DateCreation);
            if ($date > 0) {
                $product->date_creation = $date;
            }
        }

        // ── Extrafields aerotoolbox ────────────────────────────────────────
        // Disponibilité et suivi alimentent les champs déjà en place sur la fiche produit.
        // La boutique ne les renseigne pas : c'est l'apport principal de la reprise sur un
        // produit adopté.
        if ($availability !== '' && isset($this->availabilityByCode[$availability])
            && (!$fillOnly || empty($product->array_options['options_aerotb_availability']))) {
            $product->array_options['options_aerotb_availability'] = $this->availabilityByCode[$availability];
        }

        $tracking = $this->resolveDictionary($row->PA_ChampLibre_Intitule_2, 'tracking');
        if ($tracking !== '' && isset($this->trackingByCode[$tracking])
            && (!$fillOnly || empty($product->array_options['options_aerotb_tracking']))) {
            $product->array_options['options_aerotb_tracking'] = $this->trackingByCode[$tracking];
        }
    }

    /**
     * Range le produit dans la catégorie de sa famille Sage.
     *
     * @param Product  $product Produit persisté
     * @param stdClass $row     Ligne source
     * @return void
     * @throws Exception Si le rattachement échoue
     */
    protected function applyCategory(Product $product, $row)
    {
        $categoryIds = array();

        // Famille de TVA : conservée à la demande du client, en parallèle du classement
        // commercial.
        $family = trim((string) $row->FA_CodeFamille);
        if ($family !== '' && !empty($this->categoryByFamily[$family])) {
            $categoryIds[] = $this->categoryByFamily[$family];
        }

        // Classement commercial : un article porte jusqu'à trois catégories du catalogue.
        foreach (array('CL_No1', 'CL_No2', 'CL_No3') as $column) {
            $clNo = isset($row->$column) ? (int) $row->$column : 0;
            if ($clNo <= 0) {
                continue;
            }

            if (isset($this->categoryByClNo[$clNo])) {
                $categoryIds[] = $this->categoryByClNo[$clNo];
            } else {
                // Signalé une fois par catégorie : soit `category` n'a pas été passé, soit
                // l'article référence une rubrique absente du catalogue.
                $this->missingCatalogue[$clNo] = true;
            }
        }

        foreach (array_unique($categoryIds) as $categoryId) {
            $categorie = new Categorie($this->db);
            if ($categorie->fetch($categoryId) <= 0) {
                throw new Exception('Catégorie introuvable (rowid '.$categoryId.')');
            }

            // containsObject() évite d'ajouter deux fois le même rattachement.
            if ($categorie->containsObject('product', $product->id)) {
                continue;
            }

            if ($categorie->add_type($product, 'product') < 0
                && $categorie->error !== 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                throw new Exception('Échec du rattachement à « '.$categorie->label.' » : '.$this->objectErrors($categorie));
            }
        }
    }

    /**
     * Résout un libellé de champ libre vers un code de dictionnaire.
     *
     * @param string $rawLabel  Valeur du champ libre
     * @param string $dictionary 'availability' ou 'tracking'
     * @return string           Code du dictionnaire, chaîne vide si non résolu
     */
    protected function resolveDictionary($rawLabel, $dictionary)
    {
        $rawLabel = $this->fixDoubleEncoding($rawLabel);
        if ($rawLabel === '') {
            return '';
        }

        $aliases  = ($dictionary === 'tracking') ? $this->trackingAliases : $this->availabilityAliases;
        $unsolved = ($dictionary === 'tracking') ? 'unresolvedTracking' : 'unresolvedAvailability';

        // Les libellés de disponibilité sont écrits en majuscules avec des soulignés :
        // la normalisation les ramène à la même forme que ceux du dictionnaire.
        $normalized = $this->normalizeLabel(str_replace('_', ' ', $rawLabel));
        if ($normalized === '') {
            return '';
        }

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        if (!isset($this->{$unsolved}[$rawLabel])) {
            $this->{$unsolved}[$rawLabel] = 0;
        }
        $this->{$unsolved}[$rawLabel]++;

        return '';
    }

    /**
     * Nettoie un champ libre : double encodage corrigé, non-renseignés écartés.
     *
     * La source déguise ses valeurs vides derrière des marqueurs venus de tableurs ou de
     * scripts : « #N/A » pour les champs libres, « undefined » pour le code douanier.
     *
     * @param string $value Valeur brute
     * @return string       Valeur exploitable, chaîne vide sinon
     */
    protected function cleanFreeField($value)
    {
        $value = $this->fixDoubleEncoding($value);

        if ($value === '' || strcasecmp($value, '#N/A') === 0 || strcasecmp($value, 'undefined') === 0) {
            return '';
        }

        return $value;
    }

    /**
     * Met une référence article au format retenu par le client.
     *
     * Les références numériques sont préfixées d'un croisillon et complétées à cinq
     * chiffres : « 10 » devient « #00010 ». Celles qui comportent des lettres ou des
     * séparateurs sont conservées telles quelles — 21 dans la source, dont `PACK`,
     * `EMBALLAGE`, `ADD10000` ou `9142-9145`.
     *
     * Deux propriétés de la source rendent la règle sûre : aucune référence numérique ne
     * dépasse cinq chiffres, et la normalisation ne provoque aucune collision — vérifié,
     * il n'existe pas de couple du type « 10 » et « 0010 ».
     *
     * La référence d'origine reste accessible dans `ref_ext` (« SAGE:<AR_Ref> »), qui
     * servira de clé de rattachement aux reprises suivantes, stocks compris.
     *
     * @param string $ref Référence source
     * @return string     Référence mise en forme
     */
    protected function formatRef($ref)
    {
        $ref = trim((string) $ref);

        if ($ref === '' || !ctype_digit($ref)) {
            return $ref;
        }

        return '#'.str_pad($ref, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Corrige un texte encodé deux fois en UTF-8.
     *
     * Les champs libres de la source contiennent « ArrÃªt Ã  Ã©puisement du stock » là où
     * on attend « Arrêt à épuisement du stock » : chaque octet de l'UTF-8 d'origine a été
     * traité comme un caractère latin-1 puis réencodé.
     *
     * La détection ne repose pas sur un motif — une expression régulière sur ces octets
     * s'est révélée peu fiable — mais sur la conversion elle-même : un texte doublement
     * encodé redevient de l'UTF-8 valide une fois reconverti, tandis qu'un texte sain
     * produit des octets latin-1 qui n'en forment pas. Le contrôle suffit donc à
     * distinguer les deux, et une chaîne saine ressort inchangée.
     *
     * @param string $value Valeur brute
     * @return string       Valeur corrigée et nettoyée
     */
    protected function fixDoubleEncoding($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $decoded = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        if ($decoded !== false && $decoded !== '' && $decoded !== $value && mb_check_encoding($decoded, 'UTF-8')) {
            return trim($decoded);
        }

        return $value;
    }

    /**
     * Rapport de fin de passage : libellés non reconnus.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $lines = array();

        foreach (array(
            'unresolvedAvailability' => 'Disponibilités non reconnues (produits sans disponibilité) :',
            'unresolvedTracking'     => 'Suivis non reconnus (produits sans suivi) :',
        ) as $property => $title) {
            if (empty($this->{$property})) {
                continue;
            }
            arsort($this->{$property});
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = $title;
            foreach ($this->{$property} as $label => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 6, ' ', STR_PAD_LEFT).'  '.$label;
            }
        }

        if (!$this->prestaLinkAvailable) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Boutique en ligne : table '.MAIN_DB_PREFIX.'prestasync_product absente,';
            $lines[] = '  ni rapprochement ni déclaration des produits créés.';
        } else {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Boutique en ligne :';
            $lines[] = '  '.str_pad((string) $this->prestaLinksCreated, 6, ' ', STR_PAD_LEFT).'  lien(s) créé(s) dans '.MAIN_DB_PREFIX.'prestasync_product';
            if ($this->withoutPrestaId > 0) {
                $lines[] = '  '.str_pad((string) $this->withoutPrestaId, 6, ' ', STR_PAD_LEFT).'  produit(s) sans identifiant boutique : absents de la boutique';
            }
            if ($this->prestaIdConflicts > 0) {
                $lines[] = '  '.str_pad((string) $this->prestaIdConflicts, 6, ' ', STR_PAD_LEFT).'  produit(s) non liés : identifiant boutique déjà attribué';
            }
        }

        if ($this->barcodeDuplicated > 0 || !empty($this->barcodeInvalid)) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Codes-barres :';
            if ($this->barcodeDuplicated > 0) {
                $lines[] = '  '.str_pad((string) $this->barcodeDuplicated, 6, ' ', STR_PAD_LEFT)
                    .'  déjà porté(s) par un autre produit, laissé(s) de côté';
                $lines[] = '          Dolibarr en impose l\'unicité et refuserait la fiche entière.';
                $lines[] = '          Le produit est repris, seul son code-barres manque.';
            }
            if (!empty($this->barcodeInvalid)) {
                arsort($this->barcodeInvalid);
                $lines[] = '  '.str_pad((string) count($this->barcodeInvalid), 6, ' ', STR_PAD_LEFT)
                    .'  valeur(s) qui ne sont pas des codes-barres, écartée(s) :';
                foreach (array_slice($this->barcodeInvalid, 0, 6, true) as $value => $nb) {
                    $lines[] = '            '.str_pad('« '.$value.' »', 28).$nb.' article(s)';
                }
            }
        }

        if ($this->matchedByRef > 0) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Rattachés par leur référence : '.$this->matchedByRef.' produit(s)';
            $lines[] = '  Aucun lien boutique ne les désignait, mais un produit portait déjà leur';
            $lines[] = '  référence : complétés plutôt que recréés.';
        }

        if (!empty($this->categoryByFamily)) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Catégories de familles : '.implode(', ', array_keys($this->categoryByFamily));
        }

        if (empty($this->categoryByClNo)) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Catalogue : aucune catégorie reprise en base.';
            $lines[] = '  Les articles n\'ont pas été classés — lancez « migrate.php category » avant celui-ci.';
        } elseif (!empty($this->missingCatalogue)) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Catalogue : '.count($this->missingCatalogue).' rubrique(s) référencée(s) par des articles';
            $lines[] = '  mais absente(s) des catégories reprises : '
                .implode(', ', array_slice(array_keys($this->missingCatalogue), 0, 15))
                .(count($this->missingCatalogue) > 15 ? '…' : '');
        }

        return $lines;
    }
}
