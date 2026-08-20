<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationshipment.class.php
 * \ingroup aeromigration
 * \brief   Reprise des expéditions clients : f_docentete_global (type 2) -> Expedition.
 *
 * PRÉREQUIS : les tiers, les articles, les entrepôts et les COMMANDES CLIENTS doivent avoir
 * été repris — chaque expédition s'adosse aux lignes de sa commande, retrouvée par `ref_ext`.
 * Une commande citée par la source mais absente de la cible est une ERREUR, pas un écart :
 * elle dit que `customerorder` n'a pas été passé, ou pas en entier.
 *
 * La « préparation de livraison » (type 2 du domaine vente) est l'expédition de l'ancien ERP :
 * 62 519 documents, ~9 000 par an depuis 2019. Les « bons de livraison » type 3 (316 pièces)
 * sont le carnet atelier/SAV — ordres de réparation compris — et sont écartés du périmètre,
 * décision du 20/08/2026 : le client n'en veut pas.
 *
 * ## Le stock n'est pas touché, et c'est le point qui commande tout
 *
 * Le stock d'ouverture est déjà posé par le script `stock` : il tient compte de toutes les
 * livraisons passées. Or le module lots/séries force `STOCK_CALCULATE_ON_SHIPMENT_CLOSE = 1`
 * (conf.class.php:947) : clôturer une expédition SORT ses quantités du stock. Clôturer les
 * 60 000 expéditions reprises déduirait donc une seconde fois des centaines de milliers
 * d'unités déjà décomptées.
 *
 * La parade tient au fait que le coeur ne consulte pas la base mais la configuration EN
 * MÉMOIRE : `isModEnabled('stock')` lit `$conf->modules` (functions.lib.php:465), chargé au
 * démarrage du processus. `prepare()` retire donc « stock » de ce tableau — POUR CE PROCESSUS
 * SEULEMENT. L'instance reste intacte pour tout le monde : rien n'est désactivé en base, rien
 * à réactiver, et un script qui meurt ne laisse aucun état à restaurer. Les clôtures passent
 * alors sans le moindre mouvement (expedition.class.php:2923 : `isModEnabled('stock') &&
 * STOCK_CALCULATE_ON_SHIPMENT_CLOSE`). Le rapport vérifie en fin de passage que
 * `llx_stock_mouvement` n'a pas bougé d'une ligne.
 *
 * Deux modules voisins sont neutralisés de la même façon, pour le même processus :
 *
 * - **productbatch** : `addline()` refuse tout article suivi par lot avec l'erreur
 *   `ADDLINE_WAS_CALLED_INSTEAD_OF_ADDLINEBATCH` (expedition.class.php:1276). Sur un
 *   historique sans détail de lot, c'est la voie simple qui est la bonne ;
 * - **PRODUIT_SOUSPRODUITS** : `create()` éclaterait chaque produit composé en lignes de
 *   composants (expedition.class.php:524). La source liste déjà ses compositions en lignes de
 *   texte — reprises en note — et l'éclatement ferait double emploi.
 *
 * ⚠  COROLLAIRE À NE JAMAIS OUBLIER : ces expéditions sont livrées CLÔTURÉES et doivent le
 * rester. En rouvrir une depuis l'écran puis la reclôturer déclencherait cette fois le
 * mouvement de stock — l'application, elle, a ses modules actifs.
 *
 * ## Statut cible : clôturée, et « facturée » quand la commande l'est
 *
 * Les statuts de la source ne portent rien d'exploitable — le 0 « A préparer » coiffe des
 * pièces pourtant soldées et facturées, le drapeau `Z_Solde` contredit les dates — et ce sont
 * de toute façon des livraisons passées : tout est repris CLÔTURÉ, plus le drapeau « facturée »
 * quand la commande d'origine est citée par une facture de la source.
 *
 * Le coeur a deux effets de bord sur la commande d'origine, qu'il faut défaire :
 * `valid()` la passe en « expédition en cours » et `setClosed()` la clôture quand tout est
 * livré. Or les statuts des commandes ont été posés par `customerorder` d'après la source, et
 * la reprise n'a pas à les réécrire : le statut de la commande est relevé avant, restauré
 * après si le passage l'a changé.
 *
 * ## Référence : définitive dès la création, pas de renumérotation ultérieure
 *
 * Contrairement aux factures — nées avec leur numéro de reprise puis renumérotées —, aucune
 * expédition n'existe en cible : la nomenclature arrêtée avec le client (20/08/2026, même
 * règle que les factures) est posée directement.
 *
 * - avant le 01/10/2023 : `BL<millésime>-<chiffres de la pièce>` — millésime = les deux
 *   derniers chiffres des deux années de l'exercice fiscal (oct→sept), chiffres de la pièce
 *   tels quels, lettres retirées, zéros conservés ;
 * - depuis : séquence `000001` par exercice, chronologique, compteur 6 chiffres.
 *
 * `Expedition::create()` respecte une référence posée avant l'appel (expedition.class.php:510)
 * et `valid()` la conserve dès lors qu'elle ne commence pas par `(PROV` (ligne 1024) : le
 * module de numérotation n'est jamais sollicité. L'attribution est calculée dans `prepare()`
 * à partir de la seule source, donc STABLE d'un passage à l'autre : une relance redonne à
 * chaque pièce la même référence, migrée ou pas.
 *
 * ## Rattachement des lignes : article + prix, consommation dans l'ordre
 *
 * Une ligne d'expédition Dolibarr référence une LIGNE DE COMMANDE (`fk_elementdet`), et la
 * source ne conserve pas le numéro de la ligne d'origine — seulement la commande
 * (`DL_PieceBC`, une seule par document sur tout le gisement). L'appariement se fait donc par
 * article, départagé par le prix unitaire : 615 des 864 doublons article/commande ont des prix
 * différents, et la préparation hérite du prix de sa ligne à la transformation. À prix égal,
 * les lignes sont interchangeables — même article, même prix — et sont servies dans l'ordre,
 * en suivant les quantités restantes.
 *
 * 361 documents n'ont aucun lien vers une commande : 296 sont des coquilles vides (aucune
 * ligne, créées en rafale par l'ancien ERP), le reste se rattrape parfois par `DO_NoWeb`, qui
 * porte tantôt le numéro de commande, tantôt le numéro web commun aux deux documents. Ce qui
 * reste est écarté et listé.
 *
 * ## Ce qui va en note plutôt qu'en ligne
 *
 * Les 6 797 lignes sans article sont des annotations — numéros de série livrés (la seule
 * traçabilité série de l'ancien ERP), compositions de produits montés, franco de port. Une
 * ligne d'expédition ne peut pas les porter : leur texte est recopié dans la note privée,
 * dans l'ordre de la source. Rien ne se perd, et les numéros de série restent cherchables.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
// Expedition::addline() instancie OrderLine sans l'inclure, comme Reception avec le dispatch :
// en CLI, personne ne l'a chargé avant nous et l'appel mourrait en erreur fatale.
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

class MigrationShipment extends AeroMigrationRunner
{
    /** Domaine vente de la source. */
    const SRC_DOMAIN = 0;

    /** Type « préparation de livraison » dans le domaine vente. */
    const SRC_TYPE = 2;

    /** Type « commande client ». */
    const SRC_TYPE_ORDER = 1;

    /** Types « facture », pour le drapeau « facturée ». */
    const SRC_TYPES_INVOICE = '6, 7';

    /** Marqueur d'annulation de la source. */
    const CANCELLED = 'A';

    /** Clé de reprise de l'entrepôt principal. */
    const MAIN_WAREHOUSE = 'DEPOT1';

    /** Préfixe des références cibles. */
    const REF_PREFIX = 'BL';

    /** Premier jour du premier exercice numéroté en séquence. */
    const CUTOFF = '2023-10-01';

    /** Longueur du compteur des références en séquence. */
    const COUNTER_LENGTH = 6;

    /** Tolérance de rapprochement des prix unitaires, en euros. */
    const PRICE_TOLERANCE = 0.005;

    /** Nombre d'exemples conservés par anomalie. */
    const SAMPLES = 8;

    /** @var string Identifiant du script en ligne de commande */
    public $code = 'shipment';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptShipment';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues */
    protected $srcFields = 'DO_Piece, DO_Date, DO_DateLivr, DO_Tiers, DO_Ref, DO_NoWeb, Z_Annule';

    /** @var string Colonne de parcours : la clé primaire du document */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du document */
    protected $srcKeyField = 'DO_Piece';

    /**
     * Préparations du domaine vente, hors annulations.
     *
     * Les 2 236 documents annulés sont exclus dès la lecture : comme pour les réceptions,
     * l'objet cible n'a aucun statut pour représenter une livraison qui n'a pas eu lieu.
     *
     * @var string
     */
    protected $srcWhere = "DO_Domaine = 0 AND DO_Type = 2 AND TRIM(DO_Piece) <> ''"
        ." AND COALESCE(Z_Annule, '') <> 'A'";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'expedition';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'expedition';

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var int Entrepôt unique, posé sur toutes les lignes */
    protected $warehouseId = 0;

    /** @var array<string,int> Commandes reprises : numéro source en minuscules -> rowid */
    protected $orderBySage = array();

    /** @var array<int,int> rowid de commande -> rowid du tiers */
    protected $orderSoc = array();

    /** @var array<int,int> rowid de commande -> statut posé par la reprise des commandes */
    protected $orderStatus = array();

    /**
     * Lignes de commande candidates au rattachement.
     *
     * Par commande puis par article : liste de lignes `{id, price, remaining}`, dans l'ordre
     * du document. `remaining` suit les quantités déjà servies — par ce passage comme par les
     * expéditions posées lors d'un passage antérieur.
     *
     * @var array<int,array<int,array<int,array{id:int,price:float,remaining:float}>>>
     */
    protected $orderLinesByProduct = array();

    /** @var array<string,int> Articles repris : AR_Ref en minuscules -> rowid */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> Lignes source, par numéro de document */
    protected $linesByDocument = array();

    /** @var array<string,bool> Numéros des commandes clients existant dans la source */
    protected $srcOrderPieces = array();

    /**
     * Commandes de la source SANS TIERS — écartées d'office par `customerorder`, elles
     * n'existeront jamais en cible. Les préparations qui les citent sont écartées de la même
     * façon, et non comptées en erreur : le trou est dans la source, pas dans la reprise.
     *
     * @var array<string,bool>
     */
    protected $srcOrdersNoCustomer = array();

    /** @var array<string,string> Numéro web -> numéro de commande, quand il est sans ambiguïté */
    protected $webToOrderPiece = array();

    /** @var array<string,bool> Commandes citées par une facture de la source */
    protected $invoicedOrders = array();

    /** @var array<string,string> Document -> numéro de la commande d'origine, résolu de la source */
    protected $orderPieceByDoc = array();

    /** @var array<string,string> Document -> voie de résolution : « lines » ou « header » */
    protected $orderViaByDoc = array();

    /** @var array<string,string> Document -> référence cible définitive */
    protected $refByPiece = array();

    /** @var int Lignes de llx_stock_mouvement au démarrage, pour le contrôle final */
    protected $stockMovementsBefore = -1;

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Documents rattachés par le lien de ligne DL_PieceBC */
    protected $linkedByLines = 0;

    /** @var int Documents rattachés par le repli DO_NoWeb */
    protected $linkedByHeader = 0;

    /** @var int Expéditions marquées « facturée » */
    protected $billedShipments = 0;

    /** @var int Commandes dont le statut a été restauré après le passage du coeur */
    protected $restoredOrderStatus = 0;

    /** @var int Lignes d'expédition écrites */
    protected $shippedLines = 0;

    /** @var int Lignes adossées à prix égal sur un article en doublon */
    protected $priceMatchedLines = 0;

    /** @var int Annotations recopiées en note (lignes sans article) */
    protected $noteOnlyLines = 0;

    /** @var int Lignes de quantité nulle, recopiées en note */
    protected $zeroQtyLines = 0;

    /** @var int Lignes de quantité négative, reprises telles quelles */
    protected $negativeQtyLines = 0;

    /** @var int Lignes dont l'article n'est pas repris, recopiées en note */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'articles introuvables */
    protected $missingProducts = array();

    /**
     * Lignes dont l'article ne figure pas dans la commande, recopiées en note.
     *
     * Trois familles, vérifiées sur pièces : les frais de port et modes de retrait, posés à
     * l'expédition et jamais sur la commande (PORTSTD, RETRAITRELAIS…) ; les composants de
     * produits montés, listés à 0 € sous leur parent — qui, lui, se rattache ; et quelques
     * substitutions d'articles à la livraison.
     *
     * @var int
     */
    protected $notInOrderLines = 0;

    /** @var array<string,int> Lignes hors commande, ventilées par référence d'article */
    protected $notInOrderByRef = array();

    /** @var int Documents sans aucune ligne */
    protected $emptyDocuments = 0;

    /** @var int Documents sans commande d'origine dans la source, écartés */
    protected $withoutOrder = 0;

    /** @var array<int,string> Exemples de documents sans commande */
    protected $withoutOrderSamples = array();

    /** @var int Documents dont la commande source n'a pas de tiers, écartés */
    protected $orderNoCustomerDocs = 0;

    /** @var array<int,string> Exemples de documents dont la commande n'a pas de tiers */
    protected $orderNoCustomerSamples = array();

    /** @var int Documents dont aucune ligne n'est expédiable, écartés */
    protected $noShippableDocs = 0;

    /** @var array<int,string> Exemples de documents sans ligne expédiable */
    protected $noShippableSamples = array();

    /** @var array<string,int> Documents écartés à la lecture, par motif */
    protected $discarded = array();

    /**
     * Charge les index et calcule les références cibles.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        $this->neutralizeStockModules();

        foreach (array(
            'loadWarehouse',
            'loadProductIndex',
            'loadOrderIndex',
            'loadOrderLines',
            'loadExistingAllocations',
            'loadDocumentLines',
            'loadSourceOrders',
            'loadInvoicedOrders',
            'computeTargetRefs',
            'countDiscarded',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Neutralise, POUR CE PROCESSUS SEULEMENT, ce qui transformerait la reprise en désastre.
     *
     * Tout se joue en mémoire : `isModEnabled()` lit `$conf->modules`, chargé au démarrage du
     * processus, et `getDolGlobalInt()` lit `$conf->global`. Rien n'est écrit en base —
     * l'application garde ses modules actifs pour tous les autres, et un arrêt brutal du
     * script ne laisse aucun état à restaurer.
     *
     * - **stock** : sans lui, ni `valid()` ni `setClosed()` ne crée de mouvement
     *   (expedition.class.php:1051 et 2923). Le stock d'ouverture, déjà juste, reste intact.
     * - **productbatch** : sans lui, `addline()` accepte les articles suivis par lot
     *   (expedition.class.php:1276) — l'historique n'a pas de détail de lot à déclarer.
     * - **PRODUIT_SOUSPRODUITS** : sans elle, `create()` n'éclate pas les produits composés
     *   en lignes de composants (expedition.class.php:524) — la source les décrit déjà, en
     *   annotations reprises dans la note.
     *
     * Le nombre de mouvements de stock est relevé ici et recompté au rapport : la garantie
     * n'est pas seulement documentée, elle est vérifiée.
     *
     * @return void
     */
    protected function neutralizeStockModules()
    {
        global $conf;

        unset($conf->modules['stock'], $conf->modules['productbatch']);
        if (isset($conf->stock)) {
            $conf->stock->enabled = 0;
        }
        if (isset($conf->productbatch)) {
            $conf->productbatch->enabled = 0;
        }
        if (isset($conf->global)) {
            $conf->global->PRODUIT_SOUSPRODUITS = 0;
        }

        $this->stockMovementsBefore = $this->countStockMovements();
    }

    /**
     * Nombre de lignes de llx_stock_mouvement, pour le contrôle « rien n'a bougé ».
     *
     * @return int Nombre de mouvements, -1 si le comptage échoue
     */
    protected function countStockMovements()
    {
        $resql = $this->db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'stock_mouvement', 1);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Normalise une référence source pour la recherche en index.
     *
     * Minuscules pour les mêmes raisons que `customerorder` : MySQL compare sans la casse,
     * PHP à l'identique, et la source mélange les graphies.
     *
     * @param string $ref Référence source
     * @return string     Clé d'index
     */
    protected function indexKey($ref)
    {
        return strtolower(trim((string) $ref));
    }

    /**
     * Retrouve l'entrepôt principal, posé sur toutes les lignes.
     *
     * @return int 1 si OK, -1 si l'entrepôt manque
     */
    protected function loadWarehouse()
    {
        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'entrepot';
        $sql .= ' WHERE entity IN ('.getEntity('stock').')';
        $sql .= " AND import_key = '".$this->db->escape($this->buildRefExt(self::MAIN_WAREHOUSE))."'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Entrepôt principal absent : lancez d\'abord « migrate.php warehouse »',
            );
            return -1;
        }

        $this->warehouseId = (int) $obj->rowid;

        return 1;
    }

    /**
     * Recense les articles repris.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = $this->indexKey(substr((string) $obj->ref_ext, strlen($prefix)));
            if ($key !== '') {
                $this->productBySage[$key] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Recense les commandes clients reprises, avec leur tiers et leur statut.
     *
     * Le statut est relevé ici une fois pour toutes : c'est l'état posé par `customerorder`
     * d'après la source, celui auquel chaque commande doit être RAMENÉE si la validation ou
     * la clôture d'une expédition l'a déplacé.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadOrderIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref_ext, fk_soc, fk_statut FROM '.MAIN_DB_PREFIX.'commande';
        $sql .= ' WHERE entity IN ('.getEntity('commande').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = $this->indexKey(substr((string) $obj->ref_ext, strlen($prefix)));
            if ($key === '') {
                continue;
            }
            $id = (int) $obj->rowid;

            $this->orderBySage[$key] = $id;
            $this->orderSoc[$id]     = (int) $obj->fk_soc;
            $this->orderStatus[$id]  = (int) $obj->fk_statut;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Lignes de commande candidates au rattachement, par commande puis par article.
     *
     * 192 089 lignes préchargées en une requête, dans l'ordre du document — celui dans lequel
     * les égalités de prix seront départagées. Les commandes venues de la boutique (adoptées
     * par `customerorder`) sont dans le lot : leurs lignes ont été écrites par Prestasync,
     * mais pointent les mêmes articles.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadOrderLines()
    {
        $sql  = 'SELECT cd.rowid, cd.fk_commande, cd.fk_product, cd.subprice, cd.qty';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'commandedet as cd';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commande as c ON c.rowid = cd.fk_commande';
        $sql .= ' WHERE c.entity IN ('.getEntity('commande').')';
        $sql .= "   AND c.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= '   AND cd.fk_product > 0';
        $sql .= ' ORDER BY cd.fk_commande ASC, cd.rang ASC, cd.rowid ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $order   = (int) $obj->fk_commande;
            $product = (int) $obj->fk_product;

            $this->orderLinesByProduct[$order][$product][] = array(
                'id'        => (int) $obj->rowid,
                'price'     => (float) $obj->subprice,
                'remaining' => (float) $obj->qty,
            );
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Décompte ce que les passages antérieurs ont déjà servi.
     *
     * Sans cela, une relance repartirait avec des quantités restantes pleines et servirait
     * les doublons article/prix dans le même ordre que la première fois — les documents déjà
     * migrés étant ignorés, leurs jumeaux se verraient adossés aux mêmes lignes.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadExistingAllocations()
    {
        $sql  = 'SELECT ed.fk_elementdet, SUM(ed.qty) as qty, cd.fk_commande, cd.fk_product';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'expeditiondet as ed';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'expedition as e ON e.rowid = ed.fk_expedition';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commandedet as cd ON cd.rowid = ed.fk_elementdet';
        $sql .= ' WHERE e.entity IN ('.getEntity('expedition').')';
        $sql .= "   AND e.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' GROUP BY ed.fk_elementdet, cd.fk_commande, cd.fk_product';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $order   = (int) $obj->fk_commande;
            $product = (int) $obj->fk_product;
            $lineId  = (int) $obj->fk_elementdet;

            if (!isset($this->orderLinesByProduct[$order][$product])) {
                continue;
            }
            foreach ($this->orderLinesByProduct[$order][$product] as $i => $candidate) {
                if ($candidate['id'] === $lineId) {
                    $this->orderLinesByProduct[$order][$product][$i]['remaining'] -= (float) $obj->qty;
                    break;
                }
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Précharge les lignes de tous les documents, en une lecture.
     *
     * 214 804 lignes pour 62 000 documents : les relire pièce par pièce coûterait plus que
     * la reprise elle-même.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_PieceBC, DL_Ligne';
        $sql .= ' FROM '.$this->src('f_docligne_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type = '.self::SRC_TYPE;
        $sql .= ' ORDER BY DO_Piece ASC, DL_Ligne ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $piece = trim((string) $obj->DO_Piece);
            if ($piece === '') {
                continue;
            }
            $this->linesByDocument[$piece][] = $obj;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Commandes clients de la source : numéros, et numéros web sans ambiguïté.
     *
     * Les deux servent au rattachement des documents dont les lignes ne citent aucune
     * commande : `DO_NoWeb` y porte tantôt le numéro de la commande elle-même, tantôt le
     * numéro web que les deux documents partagent. La seconde voie n'est retenue que si le
     * numéro web ne désigne qu'UNE commande — il n'est pas unique dans la source.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadSourceOrders()
    {
        $sql  = 'SELECT DO_Piece, DO_NoWeb, DO_Tiers FROM '.$this->src('f_docentete_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type = '.self::SRC_TYPE_ORDER;
        $sql .= " AND TRIM(DO_Piece) <> ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $ambiguous = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $piece = trim((string) $obj->DO_Piece);

            // Même exclusion que `customerorder` : une commande sans tiers n'est pas reprise,
            // et ne peut donc servir d'origine à personne.
            if (trim((string) $obj->DO_Tiers) === '') {
                $this->srcOrdersNoCustomer[$piece] = true;
                continue;
            }
            $this->srcOrderPieces[$piece] = true;

            $noWeb = trim((string) $obj->DO_NoWeb);
            if ($noWeb === '') {
                continue;
            }
            if (isset($this->webToOrderPiece[$noWeb])) {
                $ambiguous[$noWeb] = true;
            } else {
                $this->webToOrderPiece[$noWeb] = $piece;
            }
        }
        $this->db->free($resql);

        foreach (array_keys($ambiguous) as $noWeb) {
            unset($this->webToOrderPiece[$noWeb]);
        }

        return 1;
    }

    /**
     * Commandes citées par une facture de la source — celles dont l'expédition sera marquée
     * « facturée ».
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadInvoicedOrders()
    {
        $sql  = 'SELECT DISTINCT DL_PieceBC FROM '.$this->src('f_docligne_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type IN ('.self::SRC_TYPES_INVOICE.')';
        $sql .= " AND TRIM(COALESCE(DL_PieceBC, '')) <> ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->invoicedOrders[trim((string) $obj->DL_PieceBC)] = true;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Résout la commande d'origine d'un document, dans la SOURCE.
     *
     * Voie principale : le `DL_PieceBC` des lignes — une seule commande par document sur tout
     * le gisement, vérifié. Repli : `DO_NoWeb` de l'entête, direct ou par numéro web commun.
     *
     * La résolution ne regarde QUE la source, jamais la cible : elle participe à l'attribution
     * des références, qui doit redonner le même résultat à chaque passage quel que soit l'état
     * de la reprise.
     *
     * @param string $piece Numéro du document
     * @param string $noWeb DO_NoWeb de l'entête
     * @return array{piece:string,via:string} Numéro de commande (vide si aucun) et voie utilisée
     */
    protected function resolveSourceOrder($piece, $noWeb)
    {
        $cited = array();
        if (!empty($this->linesByDocument[$piece])) {
            foreach ($this->linesByDocument[$piece] as $line) {
                $bc = trim((string) $line->DL_PieceBC);
                if ($bc !== '') {
                    $cited[$bc] = isset($cited[$bc]) ? $cited[$bc] + 1 : 1;
                }
            }
        }

        if (!empty($cited)) {
            // Le cas multiple n'existe pas dans le gisement ; s'il apparaissait dans un export
            // futur, la commande la plus citée l'emporterait.
            arsort($cited);
            $bc = (string) key($cited);
            if (isset($this->srcOrderPieces[$bc])) {
                return array('piece' => $bc, 'via' => 'lines');
            }
            if (isset($this->srcOrdersNoCustomer[$bc])) {
                return array('piece' => '', 'via' => 'nocustomer');
            }
            return array('piece' => '', 'via' => '');
        }

        $noWeb = trim((string) $noWeb);
        if ($noWeb === '') {
            return array('piece' => '', 'via' => '');
        }
        if (isset($this->srcOrderPieces[$noWeb])) {
            return array('piece' => $noWeb, 'via' => 'header');
        }
        if (isset($this->webToOrderPiece[$noWeb])) {
            return array('piece' => $this->webToOrderPiece[$noWeb], 'via' => 'header');
        }

        return array('piece' => '', 'via' => '');
    }

    /**
     * Attribue à chaque document sa référence définitive.
     *
     * L'attribution ne dépend que de la source — périmètre, dates, numéros — jamais de l'état
     * de la cible : une relance recalcule strictement les mêmes références, et les documents
     * déjà migrés retrouvent la leur.
     *
     * Avant la coupure, la pièce fournit ses chiffres ; après, la séquence suit l'ordre
     * chronologique (`DO_Date`, puis le numéro de pièce pour départager les ex æquo).
     *
     * Deux contrôles ferment la marche : aucune référence en double dans l'attribution, et
     * aucune déjà portée en cible par un document étranger à la reprise.
     *
     * @return int 1 si OK, -1 si un contrôle échoue
     */
    protected function computeTargetRefs()
    {
        $sql  = 'SELECT DO_Piece, DO_Date, DO_NoWeb FROM '.$this->src($this->srcTable);
        $sql .= ' WHERE '.$this->srcWhere;
        $sql .= ' ORDER BY DO_Date ASC, DO_Piece ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $sequences = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $piece = trim((string) $obj->DO_Piece);
            if ($piece === '' || empty($this->linesByDocument[$piece])) {
                continue;
            }

            $resolved = $this->resolveSourceOrder($piece, (string) $obj->DO_NoWeb);
            if ($resolved['piece'] === '') {
                if ($resolved['via'] === 'nocustomer') {
                    $this->orderViaByDoc[$piece] = 'nocustomer';
                }
                continue;
            }
            $this->orderPieceByDoc[$piece] = $resolved['piece'];
            $this->orderViaByDoc[$piece]   = $resolved['via'];

            $date      = (string) $obj->DO_Date;
            $millesime = $this->fiscalMillesime($date);

            if (substr($date, 0, 10) < self::CUTOFF) {
                $digits = preg_replace('/\D+/', '', $piece);
                $ref    = self::REF_PREFIX.$millesime.'-'.$digits;
            } else {
                $next = isset($sequences[$millesime]) ? $sequences[$millesime] + 1 : 1;
                $sequences[$millesime] = $next;
                $ref = self::REF_PREFIX.$millesime.'-'
                    .str_pad((string) $next, self::COUNTER_LENGTH, '0', STR_PAD_LEFT);
            }

            $this->refByPiece[$piece] = $ref;
        }
        $this->db->free($resql);

        return $this->checkTargetRefs();
    }

    /**
     * Millésime d'exercice fiscal (octobre -> septembre) d'une date source.
     *
     * Lu directement dans la chaîne SQL plutôt que via un timestamp : pas de fuseau à gérer,
     * et la source ne porte que des dates locales.
     *
     * @param string $date Date au format « AAAA-MM-JJ… »
     * @return string      Millésime à quatre chiffres (oct 2015 -> sept 2016 : « 1516 »)
     */
    protected function fiscalMillesime($date)
    {
        $year  = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);

        $start = ($month >= 10) ? $year : $year - 1;

        return sprintf('%02d%02d', $start % 100, ($start + 1) % 100);
    }

    /**
     * Vérifie que l'attribution est saine avant d'écrire quoi que ce soit.
     *
     * @return int 1 si OK, -1 si un doublon ou une collision est détecté
     */
    protected function checkTargetRefs()
    {
        // Unicité interne : deux pièces ne peuvent pas viser la même référence.
        $seen = array();
        foreach ($this->refByPiece as $piece => $ref) {
            if (isset($seen[$ref])) {
                $this->errors[] = array(
                    'key'     => $piece,
                    'message' => 'Référence cible en double : '.$ref.' (déjà visée par '.$seen[$ref].')',
                );
                return -1;
            }
            $seen[$ref] = $piece;
        }

        // Collision avec l'existant : une référence déjà portée par une expédition étrangère à
        // la reprise (Prestasync, saisie manuelle…) bloquerait la pièce en plein passage.
        $sql   = 'SELECT ref, ref_ext FROM '.MAIN_DB_PREFIX.'expedition';
        $sql  .= ' WHERE entity IN ('.getEntity('expedition').')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $ref = (string) $obj->ref;
            if (!isset($seen[$ref])) {
                continue;
            }
            $expected = $this->buildRefExt($seen[$ref]);
            if ((string) $obj->ref_ext !== $expected) {
                $this->db->free($resql);
                $this->errors[] = array(
                    'key'     => $seen[$ref],
                    'message' => 'La référence '.$ref.' est déjà portée par une expédition'
                        .' étrangère à la reprise (ref_ext « '.(string) $obj->ref_ext.' »).'
                        .' À arbitrer avant de relancer.',
                );
                return -1;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Dénombre les documents écartés à la lecture, pour que le rapport le dise.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscarded()
    {
        $base = 'DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type = '.self::SRC_TYPE;

        $motifs = array(
            'préparation(s) annulée(s) dans l\'ancien ERP' => $base." AND COALESCE(Z_Annule, '') = 'A'",
        );

        foreach ($motifs as $libelle => $condition) {
            $sql   = 'SELECT COUNT(*) as nb FROM '.$this->src($this->srcTable).' WHERE '.$condition;
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
     * Analyse les lignes d'un document : ce qui s'expédie, ce qui va en note.
     *
     * Partagée entre le passage réel et la simulation, pour que le --dry-run annonce
     * exactement ce que l'écriture fera — compteurs compris. La consommation des quantités
     * restantes fait partie de l'analyse : c'est elle qui départage les doublons.
     *
     * @param string $piece   Numéro du document
     * @param int    $orderId rowid de la commande d'origine
     * @return array{shippable:array<int,array{lineId:int,qty:float}>,notes:array<int,string>}
     */
    protected function analyzeLines($piece, $orderId)
    {
        $shippable = array();
        $notes     = array();

        foreach ($this->linesByDocument[$piece] as $line) {
            $sourceRef = trim((string) $line->AR_Ref);
            $design    = trim((string) $line->DL_Design);
            $qty       = ($line->DL_Qte !== null) ? (float) $line->DL_Qte : 0.0;

            // Ligne sans article : annotation (numéro de série, composition, franco…).
            if ($sourceRef === '') {
                if ($design !== '') {
                    $notes[] = $design;
                }
                $this->noteOnlyLines++;
                continue;
            }

            // Quantité nulle : rien à expédier, mais la mention reste lisible.
            if ($qty == 0.0) {
                $this->zeroQtyLines++;
                $notes[] = $sourceRef.' — '.$design.' (quantité nulle dans la source)';
                continue;
            }

            $productId = 0;
            $productKey = $this->indexKey($sourceRef);
            if (isset($this->productBySage[$productKey])) {
                $productId = $this->productBySage[$productKey];
            } else {
                $this->missingProductLines++;
                $this->missingProducts[$sourceRef] = true;
                $notes[] = $sourceRef.' — '.$design.' (qté '.$qty.', article non repris)';
                continue;
            }

            $price  = ($line->DL_PrixUnitaire !== null) ? (float) $line->DL_PrixUnitaire : null;
            $lineId = $this->allocateOrderLine($orderId, $productId, $price, $qty);

            if ($lineId <= 0) {
                $this->notInOrderLines++;
                $refKey = strtoupper($sourceRef);
                $this->notInOrderByRef[$refKey] = isset($this->notInOrderByRef[$refKey])
                    ? $this->notInOrderByRef[$refKey] + 1 : 1;
                $notes[] = $sourceRef.' — '.$design.' (qté '.$qty.', absent de la commande)';
                continue;
            }

            if ($qty < 0) {
                $this->negativeQtyLines++;
            }

            $shippable[] = array('lineId' => $lineId, 'qty' => $qty);
        }

        return array('shippable' => $shippable, 'notes' => $notes);
    }

    /**
     * Choisit la ligne de commande à laquelle adosser une ligne d'expédition.
     *
     * Article d'abord, prix ensuite : quand la commande porte le même article sur plusieurs
     * lignes, 615 doublons sur 864 se départagent par le prix unitaire — la préparation
     * hérite du sien à la transformation. À prix égal, les lignes sont interchangeables et
     * sont servies dans l'ordre du document, quantités restantes en tête.
     *
     * @param int        $orderId   rowid de la commande
     * @param int        $productId rowid de l'article
     * @param float|null $price     Prix unitaire de la ligne source, null s'il n'est pas porté
     * @param float      $qty       Quantité à expédier
     * @return int                  rowid de la ligne de commande, 0 si l'article n'y figure pas
     */
    protected function allocateOrderLine($orderId, $productId, $price, $qty)
    {
        if (empty($this->orderLinesByProduct[$orderId][$productId])) {
            return 0;
        }

        $candidates = &$this->orderLinesByProduct[$orderId][$productId];
        $matching   = array();

        if ($price !== null && count($candidates) > 1) {
            foreach ($candidates as $i => $candidate) {
                if (abs($candidate['price'] - $price) <= self::PRICE_TOLERANCE) {
                    $matching[] = $i;
                }
            }
            if (!empty($matching) && count($matching) < count($candidates)) {
                $this->priceMatchedLines++;
            }
        }
        if (empty($matching)) {
            $matching = array_keys($candidates);
        }

        // Première ligne qui peut encore servir la quantité, sinon première qui peut servir
        // quelque chose, sinon la première : le rattachement vaut mieux que l'abandon.
        $chosen = null;
        foreach ($matching as $i) {
            if ($candidates[$i]['remaining'] >= $qty - 0.000001) {
                $chosen = $i;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($matching as $i) {
                if ($candidates[$i]['remaining'] > 0) {
                    $chosen = $i;
                    break;
                }
            }
        }
        if ($chosen === null) {
            $chosen = $matching[0];
        }

        $candidates[$chosen]['remaining'] -= $qty;

        return $candidates[$chosen]['id'];
    }

    /**
     * Reprend une expédition : création, validation, clôture, drapeau, statut de la commande.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid déjà repris, 0 sinon
     * @return array{action:string,id:int}
     * @throws Exception Si le coeur refuse une écriture
     */
    protected function migrateRow($row, $existingId)
    {
        $piece = trim((string) $row->DO_Piece);

        if ($existingId > 0) {
            // Rien à compléter : une expédition clôturée ne se modifie plus.
            return array('action' => 'skipped', 'id' => $existingId);
        }

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyDocuments++;
            return array('action' => 'skipped', 'id' => 0);
        }

        if (!isset($this->orderPieceByDoc[$piece])) {
            $this->countUnattachedDocument($piece);
            return array('action' => 'skipped', 'id' => 0);
        }

        $orderPiece = $this->orderPieceByDoc[$piece];
        $orderKey   = $this->indexKey($orderPiece);

        if (!isset($this->orderBySage[$orderKey])) {
            // La commande existe dans la source mais pas en cible : c'est un trou de reprise,
            // pas une particularité du document. À corriger en relançant « customerorder ».
            throw new Exception('Commande '.$orderPiece.' absente de la reprise :'
                .' relancez « migrate.php customerorder »');
        }
        $orderId = $this->orderBySage[$orderKey];

        if (!isset($this->refByPiece[$piece])) {
            throw new Exception('Aucune référence attribuée — incohérence interne');
        }

        $analysis = $this->analyzeLines($piece, $orderId);

        if (empty($analysis['shippable'])) {
            $this->noShippableDocs++;
            if (count($this->noShippableSamples) < self::SAMPLES) {
                $this->noShippableSamples[] = $piece;
            }
            return array('action' => 'skipped', 'id' => 0);
        }

        $statusBefore = isset($this->orderStatus[$orderId]) ? $this->orderStatus[$orderId] : null;

        $shipment = new Expedition($this->db);
        $shipment->socid         = isset($this->orderSoc[$orderId]) ? $this->orderSoc[$orderId] : 0;
        $shipment->ref           = $this->refByPiece[$piece];
        $shipment->ref_ext       = $this->buildRefExt($piece);
        $shipment->ref_customer  = trim((string) $row->DO_Ref);
        $shipment->date_creation = $this->db->jdate($row->DO_Date);
        $shipment->date_shipping = !empty($row->DO_DateLivr)
            ? $this->db->jdate($row->DO_DateLivr)
            : $this->db->jdate($row->DO_Date);
        $shipment->origin        = 'commande';
        $shipment->origin_type   = 'commande';
        $shipment->origin_id     = $orderId;
        $shipment->note_private  = $this->buildNote($piece, $analysis['notes']);

        // `addline()` empile dans $this->lines, que `create()` insère ensuite : l'appel se
        // fait donc AVANT create(), comme pour les réceptions.
        foreach ($analysis['shippable'] as $item) {
            if ($shipment->addline($this->warehouseId, $item['lineId'], $item['qty']) < 0) {
                throw new Exception('Ligne refusée : '.$this->objectErrors($shipment));
            }
        }

        if ($shipment->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($shipment));
        }

        // La validation conserve la référence posée (elle ne commence pas par « (PROV ») et
        // n'écrit aucun mouvement, le module stock étant neutralisé pour ce processus.
        if ($shipment->valid($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($shipment));
        }

        // L'arobase n'étouffe que du bruit connu : setClosed() lit `$order->expeditions[$lineid]`
        // pour CHAQUE ligne de la commande (expedition.class.php:2905-2907), clé absente pour
        // toute ligne jamais expédiée — nos annotations en note, les reliquats. Le coeur émet
        // alors un « Undefined array key » par ligne, avec pile xdebug en développement, sans
        // aucune conséquence : la comparaison échoue, la commande n'est pas clôturée, ce que la
        // restauration de statut couvre de toute façon. Le retour, lui, reste contrôlé.
        if (@$shipment->setClosed() < 0) {
            throw new Exception('Clôture refusée : '.$this->objectErrors($shipment));
        }

        if (isset($this->invoicedOrders[$orderPiece])) {
            if ($shipment->setBilled() < 0) {
                throw new Exception('Marquage « facturée » refusé : '.$this->objectErrors($shipment));
            }
            $this->billedShipments++;
        }

        $this->restoreOrderStatus($shipment, $orderId, $statusBefore);

        $this->shippedLines += count($analysis['shippable']);
        if (isset($this->orderViaByDoc[$piece]) && $this->orderViaByDoc[$piece] === 'header') {
            $this->linkedByHeader++;
        } else {
            $this->linkedByLines++;
        }

        return array('action' => 'created', 'id' => (int) $shipment->id);
    }

    /**
     * Compte un document qui ne peut se rattacher à aucune commande, selon le motif.
     *
     * @param string $piece Numéro du document
     * @return void
     */
    protected function countUnattachedDocument($piece)
    {
        if (isset($this->orderViaByDoc[$piece]) && $this->orderViaByDoc[$piece] === 'nocustomer') {
            $this->orderNoCustomerDocs++;
            if (count($this->orderNoCustomerSamples) < self::SAMPLES) {
                $this->orderNoCustomerSamples[] = $piece;
            }
            return;
        }

        $this->withoutOrder++;
        if (count($this->withoutOrderSamples) < self::SAMPLES) {
            $this->withoutOrderSamples[] = $piece;
        }
    }

    /**
     * Ramène la commande d'origine au statut posé par la reprise des commandes.
     *
     * `valid()` la passe en « expédition en cours », `setClosed()` la clôture quand les
     * quantités se recoupent : deux effets légitimes au fil de l'eau, faux sur un historique
     * dont les statuts viennent de la source. La restauration passe par `setStatut()`, la
     * méthode générique du coeur, sans déclencher de trigger.
     *
     * @param Expedition $shipment     Expédition tout juste posée (porteuse de l'appel)
     * @param int        $orderId      rowid de la commande
     * @param int|null   $statusBefore Statut relevé avant le passage, null si inconnu
     * @return void
     * @throws Exception Si la restauration échoue
     */
    protected function restoreOrderStatus(Expedition $shipment, $orderId, $statusBefore)
    {
        if ($statusBefore === null) {
            return;
        }

        $sql   = 'SELECT fk_statut FROM '.MAIN_DB_PREFIX.'commande WHERE rowid = '.((int) $orderId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Relecture du statut de la commande impossible : '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj || (int) $obj->fk_statut === $statusBefore) {
            return;
        }

        if ($shipment->setStatut($statusBefore, $orderId, 'commande', 'none', 'fk_statut') < 0) {
            throw new Exception('Restauration du statut de la commande refusée : '
                .$this->objectErrors($shipment));
        }

        $this->restoredOrderStatus++;
    }

    /**
     * Note privée de l'expédition : provenance, puis les annotations de la source.
     *
     * @param string            $piece Numéro du document source
     * @param array<int,string> $notes Annotations relevées par l'analyse des lignes
     * @return string
     */
    protected function buildNote($piece, array $notes)
    {
        $text = 'Expédition reprise de l\'ancien ERP — '.$piece;

        if (!empty($notes)) {
            $text .= "\n\nLignes de la source reprises en note :";
            foreach ($notes as $note) {
                $text .= "\n— ".$note;
            }
        }

        return $text;
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si le document ne permet pas de bâtir une expédition valide
     */
    protected function validateRow($row)
    {
        $piece = trim((string) $row->DO_Piece);

        if (isset($this->orderPieceByDoc[$piece])) {
            $orderKey = $this->indexKey($this->orderPieceByDoc[$piece]);
            if (!isset($this->orderBySage[$orderKey])) {
                throw new Exception('Commande '.$this->orderPieceByDoc[$piece]
                    .' absente de la reprise : relancez « migrate.php customerorder »');
            }
        }
    }

    /**
     * Verdict d'une ligne en simulation, compteurs compris.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid déjà repris, 0 sinon
     * @return string Action que le passage réel produirait
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'skipped';
        }

        $piece = trim((string) $row->DO_Piece);

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyDocuments++;
            return 'skipped';
        }

        if (!isset($this->orderPieceByDoc[$piece])) {
            $this->countUnattachedDocument($piece);
            return 'skipped';
        }

        $orderId  = $this->orderBySage[$this->indexKey($this->orderPieceByDoc[$piece])];
        $analysis = $this->analyzeLines($piece, $orderId);

        if (empty($analysis['shippable'])) {
            $this->noShippableDocs++;
            if (count($this->noShippableSamples) < self::SAMPLES) {
                $this->noShippableSamples[] = $piece;
            }
            return 'skipped';
        }

        if (isset($this->invoicedOrders[$this->orderPieceByDoc[$piece]])) {
            $this->billedShipments++;
        }
        $this->shippedLines += count($analysis['shippable']);
        if (isset($this->orderViaByDoc[$piece]) && $this->orderViaByDoc[$piece] === 'header') {
            $this->linkedByHeader++;
        } else {
            $this->linkedByLines++;
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
        return 'Suppression des expéditions reprises (table '.MAIN_DB_PREFIX.'expedition,'
            .' marquées « '.$this->refExtPrefix.' ») et de leurs lignes';
    }

    /**
     * Supprime les expéditions posées par ce script.
     *
     * La même neutralisation qu'à la reprise s'impose : dévalider puis supprimer une
     * expédition clôturée rejouerait sinon les mouvements de stock dans l'autre sens.
     *
     * L'ordre décroissant respecte la logique des séquences, comme pour les réceptions.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $this->neutralizeStockModules();

        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'expedition';
        $sql .= ' WHERE entity IN ('.getEntity('expedition').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' ORDER BY rowid DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }

        $rowids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rowids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        $result['count'] = count($rowids);
        if (!$confirm || empty($rowids)) {
            return $result;
        }

        foreach ($rowids as $rowid) {
            $shipment = new Expedition($this->db);
            if ($shipment->fetch($rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : chargement impossible';
                continue;
            }

            if ($shipment->statut > Expedition::STATUS_DRAFT) {
                $shipment->setDraft($this->user);
            }

            if ($shipment->delete($this->user) <= 0) {
                $result['failed']++;
                $result['errors'][] = $shipment->ref.' : '.$this->objectErrors($shipment);
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

        $block = array();
        if ($this->linkedByLines > 0) {
            $block[] = $this->countLine($this->linkedByLines, 'expédition(s) rattachée(s) par le lien de ligne');
        }
        if ($this->linkedByHeader > 0) {
            $block[] = $this->countLine($this->linkedByHeader, 'expédition(s) rattachée(s) par le repli DO_NoWeb');
        }
        if ($this->billedShipments > 0) {
            $block[] = $this->countLine($this->billedShipments, 'expédition(s) marquée(s) « facturée »');
        }
        if ($this->restoredOrderStatus > 0) {
            $block[] = $this->countLine($this->restoredOrderStatus, 'statut(s) de commande restauré(s)'
                .' après le passage du coeur');
        }
        $this->appendBlock($lines, 'Rattachement', $block);

        $block = array();
        if ($this->shippedLines > 0) {
            $block[] = $this->countLine($this->shippedLines, 'ligne(s) d\'expédition écrite(s)');
        }
        if ($this->priceMatchedLines > 0) {
            $block[] = $this->countLine($this->priceMatchedLines, 'ligne(s) départagée(s) par le prix'
                .' sur un article en doublon dans la commande');
        }
        if ($this->noteOnlyLines > 0) {
            $block[] = $this->countLine($this->noteOnlyLines, 'annotation(s) recopiée(s) en note'
                .' (numéros de série, compositions, franco…)');
        }
        if ($this->zeroQtyLines > 0) {
            $block[] = $this->countLine($this->zeroQtyLines, 'ligne(s) de quantité nulle, recopiée(s) en note');
        }
        if ($this->negativeQtyLines > 0) {
            $block[] = $this->countLine($this->negativeQtyLines, 'ligne(s) de quantité négative,'
                .' reprise(s) telle(s) quelle(s) : retour client');
        }
        if ($this->notInOrderLines > 0) {
            $topRefs = $this->notInOrderByRef;
            arsort($topRefs);
            $topRefs = array_slice($topRefs, 0, 6, true);
            $detail  = array();
            foreach ($topRefs as $ref => $nb) {
                $detail[] = $ref.' ×'.$nb;
            }
            $block[] = $this->countLine($this->notInOrderLines, 'ligne(s) dont l\'article ne figure pas'
                .' dans la commande, recopiée(s) en note : frais de port et retraits posés à'
                .' l\'expédition, composants de produits montés à 0 € sous leur parent,'
                .' substitutions — en tête : '.implode(', ', $detail));
        }
        $this->appendBlock($lines, 'Lignes', $block);

        $block = array();
        if ($this->missingProductLines > 0) {
            $block[] = $this->countLine($this->missingProductLines, 'ligne(s) dont l\'article n\'est pas repris ('
                .count($this->missingProducts).' référence(s)) : recopiée(s) en note');
        }
        $this->appendBlock($lines, 'Anomalies', $block);

        $block = array();
        if ($this->emptyDocuments > 0) {
            $block[] = $this->countLine($this->emptyDocuments, 'document(s) sans aucune ligne'
                .' : coquilles vides de l\'ancien ERP');
        }
        if ($this->withoutOrder > 0) {
            $block[] = $this->countLine($this->withoutOrder, 'document(s) sans commande d\'origine'
                .' dans la source'
                .(empty($this->withoutOrderSamples) ? '' : ' — '.implode(', ', $this->withoutOrderSamples)));
        }
        if ($this->orderNoCustomerDocs > 0) {
            $block[] = $this->countLine($this->orderNoCustomerDocs, 'document(s) dont la commande source'
                .' n\'a pas de tiers — commande écartée par « customerorder », expédition écartée de même'
                .(empty($this->orderNoCustomerSamples) ? '' : ' — '.implode(', ', $this->orderNoCustomerSamples)));
        }
        if ($this->noShippableDocs > 0) {
            $block[] = $this->countLine($this->noShippableDocs, 'document(s) sans aucune ligne expédiable'
                .(empty($this->noShippableSamples) ? '' : ' — '.implode(', ', $this->noShippableSamples)));
        }
        foreach ($this->discarded as $libelle => $nb) {
            $block[] = $this->countLine($nb, $libelle);
        }
        $this->appendBlock($lines, 'Écartés', $block);

        // La garantie qui conditionne tout le script, vérifiée plutôt qu'affirmée.
        if ($this->stockMovementsBefore >= 0) {
            $after = $this->countStockMovements();
            if ($after === $this->stockMovementsBefore) {
                $lines[] = 'Stock inchangé : '.number_format($after, 0, ',', ' ')
                    .' mouvement(s) avant comme après — le module stock était neutralisé pour ce processus.';
            } else {
                $lines[] = '⚠  LE STOCK A BOUGÉ : '.number_format($this->stockMovementsBefore, 0, ',', ' ')
                    .' mouvement(s) avant le passage, '.number_format($after, 0, ',', ' ')
                    .' après. À investiguer avant toute autre opération.';
            }
        }
        $lines[] = '⚠  Ces expéditions doivent RESTER clôturées : en rouvrir une depuis l\'écran puis'
            .' la reclôturer déclencherait cette fois la sortie de stock, les modules de'
            .' l\'application étant actifs.';

        return $lines;
    }

    /**
     * Met en forme une ligne de rapport.
     *
     * @param int    $count Effectif
     * @param string $text  Libellé
     * @return string
     */
    protected function countLine($count, $text)
    {
        return '  '.str_pad(number_format($count, 0, ',', ' '), 8, ' ', STR_PAD_LEFT).'  '.$text;
    }

    /**
     * Ajoute un bloc titré au rapport, s'il n'est pas vide.
     *
     * @param array<int,string> $lines Rapport en construction
     * @param string            $title Titre du bloc
     * @param array<int,string> $block Lignes du bloc
     * @return void
     */
    protected function appendBlock(array &$lines, $title, array $block)
    {
        if (empty($block)) {
            return;
        }

        $lines[] = $title.' :';
        foreach ($block as $line) {
            $lines[] = $line;
        }
    }
}
