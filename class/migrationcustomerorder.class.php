<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationcustomerorder.class.php
 * \ingroup aeromigration
 * \brief   Reprise des commandes clients : f_docentete_global -> Commande.
 *
 * Même structure que la reprise des commandes fournisseur, dont ce script reprend les
 * conclusions : le statut vient de `Z_Annule` et du chaînage vers les factures, `valid()`
 * conserve la référence si elle est posée avant, et les lignes sont préchargées par document.
 *
 * Trois différences justifient un script distinct plutôt qu'un paramètre.
 *
 * **1. La cible n'est pas vierge.** Le module Prestasync crée les commandes venues de la
 * boutique, et la source les contient aussi. Les recréer donnerait **deux commandes pour
 * chaque vente en ligne**. Elles sont donc **adoptées** : reconnues, marquées d'un `ref_ext`,
 * et laissées intactes pour le reste.
 *
 * Le rapprochement se fait sur la **référence**, et non sur `DO_NoWeb` — colonne qui semblait
 * prévue pour cela mais qui est NULL sur la quasi-totalité des documents. Prestasync écrit la
 * référence de la boutique directement dans `ref` :
 *
 *     if ($resSearchRef === 0 && !getDolGlobalInt('PRESTASYNC_NO_USER_PRESTA_REF')) {
 *         $this->doliObject->ref = $this->reference;                  // prestaOrder.class.php:634
 *     }
 *     $this->doliObject->ref_client = $this->id . ' / ' . $this->reference;
 *
 * Et la source enregistre le même document sous `CI-<référence>` : la commande `235880` de la
 * boutique est la commande `CI-235880` de la source. **54 700 des 60 936 commandes** portent
 * ce préfixe.
 *
 * Le repli par `ref_client` est conservé : la constante `PRESTASYNC_NO_USER_PRESTA_REF`, si
 * elle est posée, empêche Prestasync d'imposer sa référence, et seul `ref_client` porte alors
 * l'information.
 *
 * Prestasync laisse `ref_ext` vide (ligne 946), ce qui libère le marqueur pour la reprise.
 *
 * **2. Le volume.** 60 936 commandes et 200 010 lignes, contre 2 567 et 24 265 côté achats.
 * `Commande::addline()` accepte un dernier paramètre `$noupdateafterinsertline` qui évite de
 * recalculer les totaux du document à chaque ligne (commande.class.php:1829) ; un seul
 * `update_price()` est appelé en fin de document. Sans cela, une commande de vingt lignes
 * recalcule vingt fois ses totaux.
 *
 * **3. Les statuts diffèrent.** `Commande` n'a pas l'échelle de réception des achats :
 *
 *     Z_Annule = 'A'            →  Annulée   (-1)    1 423
 *     facturée (DL_PieceBC)     →  Fermée     (3)   59 231
 *     sans suite                →  Validée    (1)      282
 *
 * Une commande fermée est de surcroît marquée « facturée » par `classifyBilled()`, sans quoi
 * elle resterait dans la liste des commandes à facturer.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationCustomerOrder extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'customerorder';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptCustomerOrder';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues */
    protected $srcFields = 'DO_Piece, DO_Date, DO_Tiers, DO_Ref, DO_NoWeb, DO_DateLivr,'
        .' DO_Devise, DO_Cours, Z_Annule';

    /** @var string Colonne de parcours : cbMarq vaut 0 sur toute la table */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'DO_Piece';

    /**
     * Filtre de lecture.
     *
     * Les 22 documents sans tiers sont écartés dès la lecture : une commande client sans
     * client n'a pas de sens en cible, et le socle les compterait en erreur à chaque passage.
     *
     * @var string
     */
    protected $srcWhere = "DO_Domaine = 0 AND DO_Type = 1 AND TRIM(DO_Piece) <> ''"
        ." AND TRIM(COALESCE(DO_Tiers,'')) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'commande';

    /** @var string Élément Dolibarr */
    protected $dstElement = 'commande';

    /** @var int Domaine source des ventes */
    const SRC_DOMAIN = 0;

    /** @var int Type source des commandes clients */
    const SRC_TYPE = 1;

    /** @var string Marqueur d'annulation */
    const CANCELLED = 'A';

    /** @var string Séparateur de la référence posée par Prestasync dans ref_client */
    const PRESTA_SEPARATOR = ' / ';

    /** @var string Préfixe des documents source venus de la boutique */
    const SHOP_PREFIX = 'CI-';

    /** @var int Nombre d'anomalies citées nominativement au rapport */
    const SAMPLES = 8;

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,int> CT_Num en minuscules -> rowid du tiers */
    protected $customerBySage = array();

    /** @var array<string,int> AR_Ref en minuscules -> rowid du produit */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> DO_Piece -> lignes */
    protected $linesByDocument = array();

    /** @var array<string,bool> DO_Piece des commandes citées par une facture */
    protected $documentsWithFollowUp = array();

    /** @var array<string,int> Référence boutique -> rowid d'une commande déjà en base */
    protected $ordersByShopRef = array();

    /** @var array<string,bool> Références déjà attribuées, pour garantir l'unicité */
    protected $usedRefs = array();

    /**
     * Décision prise en simulation, par document.
     *
     * Le socle appelle `validateRow()` **puis** `previewAction()` sur la même ligne
     * (aeromigrationrunner.class.php:676). Or la première consomme la référence de boutique
     * — sans quoi plusieurs documents partageant une référence adopteraient la même commande
     * — et la seconde ne la retrouverait donc plus : elle annonçait « créée » ce qui venait
     * d'être compté comme adopté.
     *
     * La décision est prise une fois et relue, plutôt que recalculée sur un index modifié
     * entre-temps.
     *
     * @var array<string,string>
     */
    protected $previewDecisions = array();

    /** @var int Documents dont la référence souhaitée était déjà prise */
    protected $refCollisions = 0;

    /** @var array<int,string> Devises de la source */
    protected $currencyAliases = array(1 => 'EUR', 2 => 'USD', 4 => 'GBP', 11 => 'CAD');

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Commandes fermées */
    protected $closed = 0;

    /** @var int Commandes annulées */
    protected $cancelled = 0;

    /** @var int Commandes laissées validées */
    protected $pending = 0;

    /** @var int Commandes de la boutique reconnues et marquées */
    protected $adopted = 0;

    /** @var int Commandes de la boutique déjà marquées lors d'un passage antérieur */
    protected $alreadyAdopted = 0;

    /** @var int Lignes écrites */
    protected $linesWritten = 0;

    /** @var int Lignes de texte libre */
    protected $freeTextLines = 0;

    /** @var int Lignes dont l'article n'est pas repris */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'article introuvables */
    protected $missingProducts = array();

    /** @var int Lignes portant une remise */
    protected $discountLines = 0;

    /** @var int Lignes dont la remise est un montant */
    protected $amountDiscountLines = 0;

    /** @var int Lignes à quantité négative */
    protected $negativeQtyLines = 0;

    /** @var int Lignes sans taux de TVA */
    protected $noVatLines = 0;

    /** @var int Commandes sans aucune ligne */
    protected $emptyOrders = 0;

    /** @var int Commandes en devise étrangère */
    protected $foreignCurrency = 0;

    /** @var int Commandes écartées, tiers non repris */
    protected $missingCustomers = 0;

    /** @var array<string,bool> Codes tiers introuvables */
    protected $missingCustomerCodes = array();

    /**
     * Normalise une référence source pour la recherche en index.
     *
     * **Les index sont en minuscules, et ce n'est pas une commodité.** MySQL compare les
     * chaînes sans tenir compte de la casse — collation `_ci` —, PHP compare les clés de
     * tableau à l'identique. Une requête de contrôle SQL peut donc conclure qu'un article est
     * rattachable là où le script ne le trouvera pas.
     *
     * Le cas rencontré : la source écrit les frais de port tantôt `PortSTD`, tantôt
     * `PORTSTD`. La requête préalable annonçait 177 articles introuvables, le script en
     * trouvait **36 050** — soit 37 877 lignes de frais de port et leurs variantes de
     * graphie. Vérifié qu'aucune collision n'en résulte : les 15 811 références des produits
     * repris restent 15 811 une fois mises en minuscules.
     *
     * @param string $ref Référence source
     * @return string     Clé d'index
     */
    protected function indexKey($ref)
    {
        return strtolower(trim((string) $ref));
    }

    /**
     * Chargement des référentiels.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        $this->allowInactiveProducts();

        foreach (array(
            'loadCustomerIndex',
            'loadProductIndex',
            'loadDocumentLines',
            'loadFollowUps',
            'loadShopOrders',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Autorise la validation de commandes portant des articles retirés de la vente.
     *
     * `valid()` refuse tout document dont une ligne référence un produit dont `tosell` vaut 0
     * (commande.class.php:532). Justifié à la saisie, absurde sur un historique : **1 076 des
     * 15 811 articles repris sont arrêtés**, et les commandes qui les portent ont existé.
     *
     * La constante est posée **en mémoire du processus**, jamais en base : `getDolGlobalBool()`
     * lit `$conf->global`, et un script en ligne de commande a sa propre instance. Le
     * comportement de l'application pour les utilisateurs n'est pas modifié.
     *
     * @return void
     */
    protected function allowInactiveProducts()
    {
        global $conf;

        if (!isset($conf->global)) {
            return;
        }

        $conf->global->ORDER_NOCHECK_ONSALE_PRODUCTS_ONVALID = 1;
    }

    /**
     * Index des tiers repris : CT_Num -> rowid.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadCustomerIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= ' WHERE entity IN ('.getEntity('societe').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = $this->indexKey(substr((string) $obj->ref_ext, strlen($prefix)));
            if ($key !== '') {
                $this->customerBySage[$key] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des articles repris : AR_Ref -> rowid.
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
     * Lignes des commandes, groupées par numéro de document.
     *
     * 200 010 lignes préchargées en une requête. Le jeu tient en mémoire — quelques dizaines
     * de mégaoctets — là où 60 936 requêtes coûteraient plus d'une heure à elles seules.
     * C'est ce qui impose le `memory_limit` relevé documenté dans le README.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_Taxe1,';
        $sql .= ' DL_Remise01REM_Valeur, DL_Remise01REM_Type, DL_Ligne';
        $sql .= ' FROM '.$this->src('f_docligne_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type = '.self::SRC_TYPE;
        $sql .= ' ORDER BY DO_Piece, DL_Ligne';

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
     * Numéros de commande cités par une facture.
     *
     * Les deux types de facture sont interrogés : les « vivantes » (6) et les
     * « comptabilisées » (7), qui couvrent les exercices antérieurs à 2024.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadFollowUps()
    {
        $sql  = 'SELECT DISTINCT DL_PieceBC FROM '.$this->src('f_docligne_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type IN (6, 7)';
        $sql .= " AND DL_PieceBC IS NOT NULL AND TRIM(DL_PieceBC) <> ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->documentsWithFollowUp[trim((string) $obj->DL_PieceBC)] = true;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des commandes déjà en base venues de la boutique.
     *
     * Prestasync pose dans `ref_client` la forme `<id> / <référence>`. Seule la partie qui
     * suit le séparateur est comparable à `DO_NoWeb` : l'identifiant numérique, lui, n'existe
     * pas dans la source.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadShopOrders()
    {
        $sql  = 'SELECT rowid, ref, ref_client, ref_ext FROM '.MAIN_DB_PREFIX.'commande';
        $sql .= ' WHERE entity IN ('.getEntity('commande').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // Toute référence déjà en base est réservée, marquée ou non : c'est ce qui évite
            // de buter sur `uk_commande_ref` au moment de la validation.
            $this->usedRefs[$this->indexKey($obj->ref)] = true;

            // Une commande déjà marquée par une reprise antérieure n'est pas candidate à
            // l'adoption : la reconnaître à nouveau la ferait compter deux fois.
            if (!empty($obj->ref_ext)) {
                continue;
            }

            // Voie principale : Prestasync a posé la référence de la boutique dans `ref`.
            $key = $this->indexKey($obj->ref);
            if ($key !== '' && !isset($this->ordersByShopRef[$key])) {
                $this->ordersByShopRef[$key] = (int) $obj->rowid;
            }

            // Repli : si PRESTASYNC_NO_USER_PRESTA_REF est posée, `ref` vient du module de
            // numérotation et seul `ref_client` porte la référence de la boutique.
            $fromClient = $this->extractShopRef((string) $obj->ref_client);
            if ($fromClient !== '' && !isset($this->ordersByShopRef[$fromClient])) {
                $this->ordersByShopRef[$fromClient] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Extrait la référence de boutique d'un `ref_client` posé par Prestasync.
     *
     * Le module y écrit `<id PrestaShop> / <référence>`. Seule la partie qui suit le
     * séparateur est comparable à la source : l'identifiant numérique n'y existe pas.
     *
     * @param string $refClient Valeur du champ
     * @return string           Référence normalisée, ou chaîne vide si la forme ne correspond pas
     */
    protected function extractShopRef($refClient)
    {
        $pos = strrpos($refClient, self::PRESTA_SEPARATOR);
        if ($pos === false) {
            return '';
        }

        return $this->indexKey(substr($refClient, $pos + strlen(self::PRESTA_SEPARATOR)));
    }

    /**
     * Référence de boutique d'un document source, pour le rapprochement.
     *
     * **Deux voies, et il faut les deux.** La source a changé de convention en cours de
     * route : jusqu'en septembre 2022 la référence de la boutique n'était pas conservée, et
     * seul le numéro du document la portait ; ensuite `DO_NoWeb` l'enregistre, tantôt
     * identique au numéro, tantôt alphanumérique.
     *
     *     CI-251029   DO_NoWeb = 251029       →  la boutique la nomme « 251029 »
     *     CI-261866   DO_NoWeb = UJGSMDLHX    →  la boutique la nomme « UJGSMDLHX »
     *     CI-210251   DO_NoWeb vide           →  repli sur le numéro, « 210251 »
     *
     * Sur les 54 700 documents préfixés, 20 273 ont un `DO_NoWeb` — dont **3 333 où il diffère
     * du numéro**. Se fier au seul numéro manquerait ces 3 333, et leur donnerait de surcroît
     * une référence que la boutique ne reconnaîtrait pas.
     *
     * @param stdClass $row Ligne source
     * @return string       Clé de rapprochement, ou chaîne vide si le document ne vient pas
     *                      de la boutique
     */
    protected function shopRef($row)
    {
        $noWeb = trim((string) $row->DO_NoWeb);
        if ($noWeb !== '') {
            return $this->indexKey($noWeb);
        }

        $piece = trim((string) $row->DO_Piece);
        if (strncasecmp($piece, self::SHOP_PREFIX, strlen(self::SHOP_PREFIX)) === 0) {
            return $this->indexKey(substr($piece, strlen(self::SHOP_PREFIX)));
        }

        return '';
    }

    /**
     * Référence à donner à la commande en cible.
     *
     * Même logique que `shopRef()`, mais en conservant la casse : celle-ci sert à comparer,
     * celle-là à nommer. Les documents qui ne viennent pas de la boutique gardent leur numéro
     * d'origine.
     *
     * Ce choix n'est pas cosmétique. Prestasync vérifie l'existence d'une commande avant de
     * la créer, et il le fait sur la référence :
     *
     *     SELECT rowid FROM llx_commande WHERE ref = '<reference>'   // prestaOrder.class.php:622
     *     if ($resSearchRef) { setError('Order already exist'); return false; }
     *
     * Une commande reprise sous le nom que la boutique lui donnerait est donc reconnue par
     * elle, qui refusera de la recréer. La protection joue dans les deux sens.
     *
     * @param stdClass $row Ligne source
     * @return string       Référence souhaitée
     */
    protected function preferredRef($row)
    {
        $noWeb = trim((string) $row->DO_NoWeb);
        if ($noWeb !== '') {
            return $noWeb;
        }

        $piece = trim((string) $row->DO_Piece);
        if (strncasecmp($piece, self::SHOP_PREFIX, strlen(self::SHOP_PREFIX)) === 0) {
            return substr($piece, strlen(self::SHOP_PREFIX));
        }

        return $piece;
    }

    /**
     * Référence libre pour la commande, la souhaitée étant peut-être déjà prise.
     *
     * `uk_commande_ref` impose l'unicité, et la référence souhaitée ne l'est pas toujours :
     * **159 documents entrent en collision**, dont 22 partageant un `DO_NoWeb` valant « 1 ».
     * Le numéro du document, lui, est unique par construction — 60 936 valeurs pour autant de
     * documents — et sert donc de repli.
     *
     * L'index des références déjà attribuées est tenu en mémoire au fil de l'écriture : deux
     * documents traités dans le même passage ne peuvent pas se voir attribuer la même.
     *
     * @param stdClass $row Ligne source
     * @return string       Référence libre
     */
    protected function resolveRef($row)
    {
        $piece     = trim((string) $row->DO_Piece);
        $preferred = $this->preferredRef($row);
        $key       = $this->indexKey($preferred);

        if ($key !== '' && !isset($this->usedRefs[$key])) {
            $this->usedRefs[$key] = true;
            return $preferred;
        }

        // Repli sur le numéro du document, unique par construction.
        $fallbackKey = $this->indexKey($piece);
        if (!isset($this->usedRefs[$fallbackKey])) {
            $this->usedRefs[$fallbackKey] = true;
            $this->refCollisions++;
            return $piece;
        }

        // Ceinture et bretelles : ne devrait jamais arriver, DO_Piece étant unique.
        $suffixed = $piece.'-'.substr(md5($piece), 0, 4);
        $this->usedRefs[$this->indexKey($suffixed)] = true;
        $this->refCollisions++;

        return $suffixed;
    }

    /**
     * Statut cible d'une commande.
     *
     * @param stdClass $row Ligne source
     * @return int          Constante de statut de Commande
     */
    protected function resolveStatus($row)
    {
        if (strtoupper(trim((string) $row->Z_Annule)) === self::CANCELLED) {
            return Commande::STATUS_CANCELED;
        }

        if (isset($this->documentsWithFollowUp[trim((string) $row->DO_Piece)])) {
            return Commande::STATUS_CLOSED;
        }

        return Commande::STATUS_VALIDATED;
    }

    /**
     * Devise du document.
     *
     * Le taux est inversé, comme pour les achats : la source compte les euros par unité de
     * devise, Dolibarr l'inverse. Voir `MigrationSupplierOrder::resolveCurrency()` pour la
     * démonstration. Une seule commande client est concernée.
     *
     * @param stdClass $row Ligne source
     * @return array{code:string,rate:float}
     */
    protected function resolveCurrency($row)
    {
        global $conf;

        $no = (int) $row->DO_Devise;
        if ($no <= 0 || !isset($this->currencyAliases[$no])) {
            return array('code' => $conf->currency, 'rate' => 1.0);
        }

        $code = $this->currencyAliases[$no];
        $rate = (float) $row->DO_Cours;

        if ($code === $conf->currency || $rate <= 0) {
            return array('code' => $conf->currency, 'rate' => 1.0);
        }

        $this->foreignCurrency++;

        return array('code' => $code, 'rate' => 1 / $rate);
    }

    /**
     * Reprend une commande.
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
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // La boutique a peut-être déjà créé cette commande : on la marque au lieu de la
        // recréer. Testé avant toute écriture, c'est ce qui évite 20 637 doublons.
        $shopRef = $this->shopRef($row);
        if ($shopRef !== '' && isset($this->ordersByShopRef[$shopRef])) {
            $result = $this->adoptShopOrder($this->ordersByShopRef[$shopRef], $piece);
            $this->consumeShopRef($shopRef);
            return $result;
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            return array('action' => 'skipped', 'id' => 0);
        }

        $currency = $this->resolveCurrency($row);

        $order = new Commande($this->db);
        $order->socid              = (int) $this->customerBySage[$customerCode];
        $order->date_commande      = $this->db->jdate($row->DO_Date);
        $order->date               = $order->date_commande;
        $order->ref_ext            = $this->buildRefExt($piece);
        $order->ref_client         = trim((string) $row->DO_Ref);
        $order->multicurrency_code = $currency['code'];
        $order->multicurrency_tx   = $currency['rate'];
        $order->note_private       = 'Commande reprise de l\'ancien ERP — '.$piece;

        if (!empty($row->DO_DateLivr)) {
            $order->delivery_date = $this->db->jdate($row->DO_DateLivr);
        }

        if ($order->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($order));
        }

        $this->addLines($order, $piece);

        $order->ref = $this->resolveRef($row);

        if ($order->valid($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($order));
        }

        $this->applyStatus($order, $this->resolveStatus($row));

        return array('action' => 'created', 'id' => (int) $order->id);
    }

    /**
     * Marque une commande créée par la boutique sans y toucher autrement.
     *
     * Même règle que pour les tiers : on ne recrée pas, on ne renomme pas, on ne remanie pas
     * les lignes — la boutique en est la source. On pose seulement le marqueur, qui rend la
     * reprise idempotente et permet de retrouver le document d'origine.
     *
     * @param int    $orderId rowid de la commande existante
     * @param string $piece   Numéro du document source
     * @return array{action:string,id:int}
     * @throws Exception Si le marqueur ne peut pas être posé
     */
    protected function adoptShopOrder($orderId, $piece)
    {
        $order = new Commande($this->db);
        if ($order->fetch($orderId) <= 0) {
            throw new Exception('Commande '.$orderId.' illisible : '.$this->objectErrors($order));
        }

        if (!empty($order->ref_ext)) {
            $this->alreadyAdopted++;
            return array('action' => 'skipped', 'id' => $orderId);
        }

        $marker = $this->buildRefExt($piece);

        if ($order->setValueFrom('ref_ext', $marker, '', null, 'text', '', $this->user) <= 0) {
            throw new Exception('Marqueur non posé sur la commande '.$order->ref.' : '
                .$this->objectErrors($order));
        }

        $this->adopted++;

        return array('action' => 'adopted', 'id' => $orderId);
    }

    /**
     * Retire une référence boutique de l'index une fois consommée.
     *
     * **`DO_NoWeb` n'est pas unique dans la source** : 131 références sont portées par
     * plusieurs commandes, l'une d'elles par 22 — des documents distincts issus d'une même
     * commande en ligne. La boutique, elle, n'en a créé qu'une.
     *
     * Sans ce retrait, les commandes suivantes verraient la même commande cible déjà marquée
     * et seraient comptées « déjà adoptées » : **158 commandes de la source ne seraient ni
     * créées ni reprises**, et rien dans le rapport ne le dirait. La première consomme donc
     * l'adoption, les suivantes sont créées normalement.
     *
     * @param string $shopRef Référence boutique
     * @return void
     */
    protected function consumeShopRef($shopRef)
    {
        unset($this->ordersByShopRef[$shopRef]);
    }

    /**
     * Applique le statut final.
     *
     * Une commande fermée est en outre classée « facturée » : sans cela elle resterait dans
     * la liste des commandes à facturer, qui n'aurait plus aucun sens avec 59 231 entrées.
     *
     * @param Commande $order  Commande validée
     * @param int      $status Statut à appliquer
     * @return void
     * @throws Exception Si le coeur refuse
     */
    protected function applyStatus(Commande $order, $status)
    {
        if ($status === Commande::STATUS_CANCELED) {
            if ($order->cancel($this->user) <= 0) {
                throw new Exception('Annulation refusée : '.$this->objectErrors($order));
            }
            $this->cancelled++;
            return;
        }

        if ($status === Commande::STATUS_CLOSED) {
            if ($order->classifyBilled($this->user) <= 0) {
                throw new Exception('Classement en facturée refusé : '.$this->objectErrors($order));
            }
            if ($order->cloture($this->user) <= 0) {
                throw new Exception('Clôture refusée : '.$this->objectErrors($order));
            }
            $this->closed++;
            return;
        }

        $this->pending++;
    }

    /**
     * Ajoute les lignes du document.
     *
     * Le recalcul des totaux est reporté à la fin : `addline()` le déclenche à chaque appel
     * si on ne l'en empêche pas (commande.class.php:1829), ce qui ferait recalculer vingt
     * fois les totaux d'une commande de vingt lignes.
     *
     * @param Commande $order Commande créée, encore au brouillon
     * @param string   $piece Numéro du document source
     * @return void
     * @throws Exception Si le coeur refuse une ligne
     */
    protected function addLines(Commande $order, $piece)
    {
        if (empty($this->linesByDocument[$piece])) {
            $this->emptyOrders++;
            return;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $map = $this->mapLine($line);

            $result = $order->addline(
                $map['desc'],
                $map['price'],
                $map['qty'],
                $map['vat'],
                0,
                0,
                $map['productId'],
                $map['discount'],
                0,
                0,
                'HT',
                0,
                '',
                '',
                0,
                -1,
                0,
                0,
                null,
                0,
                '',
                array(),
                null,
                '',
                0,
                0,
                '',
                1
            );

            if ($result <= 0) {
                throw new Exception('Ligne refusée : '.$this->objectErrors($order));
            }

            $this->linesWritten++;
        }

        // Les totaux, une fois toutes les lignes posées.
        if ($order->update_price(1, 'auto') < 0) {
            throw new Exception('Totaux non calculés : '.$this->objectErrors($order));
        }
    }

    /**
     * Traduit une ligne source en arguments d'`addline()`.
     *
     * @param stdClass $line Ligne source
     * @return array<string,mixed>
     */
    protected function mapLine($line)
    {
        $sourceRef = trim((string) $line->AR_Ref);
        $productKey = $this->indexKey($sourceRef);
        $productId = 0;

        if ($sourceRef === '') {
            $this->freeTextLines++;
        } elseif (isset($this->productBySage[$productKey])) {
            $productId = (int) $this->productBySage[$productKey];
        } else {
            $this->missingProductLines++;
            $this->missingProducts[$sourceRef] = true;
        }

        $desc = trim((string) $line->DL_Design);
        if ($desc === '') {
            $desc = ($sourceRef !== '') ? $sourceRef : 'Ligne sans désignation';
        }

        $qty = (float) $line->DL_Qte;
        if ($qty < 0) {
            $this->negativeQtyLines++;
        }

        $vat = $line->DL_Taxe1;
        if ($vat === null) {
            $this->noVatLines++;
            $vat = 0;
        }

        $discount = (float) $line->DL_Remise01REM_Valeur;
        if ($discount != 0) {
            $this->discountLines++;

            if ((int) $line->DL_Remise01REM_Type === 1) {
                $this->amountDiscountLines++;
                $discount = 0;
            }
        }
        if ($discount < 0) {
            $discount = 0;
        }

        return array(
            'desc'      => $desc,
            'price'     => (float) $line->DL_PrixUnitaire,
            'qty'       => $qty,
            'vat'       => (float) $vat,
            'productId' => $productId,
            'discount'  => $discount,
        );
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     */
    protected function validateRow($row)
    {
        $piece   = trim((string) $row->DO_Piece);
        $shopRef = $this->shopRef($row);

        if ($shopRef !== '' && isset($this->ordersByShopRef[$shopRef])) {
            $this->adopted++;
            $this->consumeShopRef($shopRef);
            $this->previewDecisions[$piece] = 'adopted';
            return;
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            $this->previewDecisions[$piece] = 'skipped';
            return;
        }

        $this->previewDecisions[$piece] = 'created';

        $this->resolveCurrency($row);
        $this->resolveRef($row);

        $status = $this->resolveStatus($row);
        if ($status === Commande::STATUS_CANCELED) {
            $this->cancelled++;
        } elseif ($status === Commande::STATUS_CLOSED) {
            $this->closed++;
        } else {
            $this->pending++;
        }

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyOrders++;
            return;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $this->mapLine($line);
            $this->linesWritten++;
        }
    }

    /**
     * Annonce l'action prévue en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid déjà repris, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'skipped';
        }

        // La décision a été prise par validateRow(), qui s'exécute juste avant : la
        // recalculer ici donnerait un autre résultat, l'index des commandes de la boutique
        // ayant été modifié entre-temps.
        $piece = trim((string) $row->DO_Piece);
        if (isset($this->previewDecisions[$piece])) {
            return $this->previewDecisions[$piece];
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
        return 'Suppression des commandes clients créées par la reprise et retrait du marqueur'
            .' sur celles qui venaient de la boutique (ref_ext commençant par « '
            .$this->refExtPrefix.' »)';
    }

    /**
     * Défait la reprise.
     *
     * Les commandes adoptées ne sont pas supprimées — elles appartiennent à la boutique — mais
     * leur marqueur est retiré. Les autres sont ramenées au brouillon puis supprimées.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid, ref, ref_client, note_private FROM '.MAIN_DB_PREFIX.'commande';
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

        foreach ($rows as $row) {
            $order = new Commande($this->db);
            if ($order->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                continue;
            }

            // Commande de la boutique : on retire le marqueur, on ne supprime rien.
            if (strpos((string) $row->note_private, 'ancien ERP') === false) {
                $order->setValueFrom('ref_ext', '', '', null, 'text', '', $this->user);
                $result['deleted']++;
                continue;
            }

            if ((int) $order->statut !== Commande::STATUS_DRAFT) {
                $order->setDraft($this->user);
            }

            if ($order->delete($this->user) > 0) {
                $result['deleted']++;
            } else {
                $result['failed']++;
                $result['errors'][] = 'Commande '.$row->ref.' : '.$this->objectErrors($order);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 500 === 0)) {
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

        $block = array();
        $block[] = $this->countLine($this->closed, 'commande(s) fermée(s), facturée(s) dans l\'ancien ERP');
        $block[] = $this->countLine($this->cancelled, 'commande(s) annulée(s)');
        $block[] = $this->countLine($this->pending, 'commande(s) validée(s), sans suite documentaire');
        $block[] = '          Le statut vient de Z_Annule et du chaînage vers les factures.';
        $this->appendBlock($lines, 'Commandes clients :', $block);

        if ($this->adopted > 0 || $this->alreadyAdopted > 0) {
            $block = array();
            if ($this->adopted > 0) {
                $block[] = $this->countLine($this->adopted, 'commande(s) de la boutique reconnue(s) et marquée(s)');
                $block[] = '          Ni recréées, ni modifiées : seul le marqueur est posé, ce qui';
                $block[] = '          évite autant de doublons. Elles restent la propriété de la';
                $block[] = '          boutique, qui continue de les mettre à jour.';
            }
            if ($this->alreadyAdopted > 0) {
                $block[] = $this->countLine($this->alreadyAdopted, 'déjà marquée(s) lors d\'un passage antérieur');
            }
            $this->appendBlock($lines, 'Commandes venues de la boutique :', $block);
        }

        $block = array();
        $block[] = $this->countLine($this->linesWritten, 'ligne(s) de commande');
        if ($this->discountLines > 0) {
            $block[] = $this->countLine($this->discountLines, 'ligne(s) avec remise');
        }
        if ($this->freeTextLines > 0) {
            $block[] = $this->countLine($this->freeTextLines, 'ligne(s) de texte libre');
        }
        if ($this->noVatLines > 0) {
            $block[] = $this->countLine($this->noVatLines, 'ligne(s) sans TVA, reprises à 0 %');
        }
        $this->appendBlock($lines, 'Lignes :', $block);

        $block = array();
        if ($this->missingCustomers > 0) {
            $block[] = $this->countLine($this->missingCustomers, 'commande(s) écartée(s), client non repris : '
                .implode(', ', array_slice(array_keys($this->missingCustomerCodes), 0, self::SAMPLES)));
        }
        if ($this->emptyOrders > 0) {
            $block[] = $this->countLine($this->emptyOrders, 'commande(s) sans aucune ligne');
        }
        if ($this->missingProductLines > 0) {
            $block[] = $this->countLine($this->missingProductLines, 'ligne(s) dont l\'article n\'est pas repris,'
                .' conservées en texte libre : '
                .implode(', ', array_slice(array_keys($this->missingProducts), 0, self::SAMPLES)));
        }
        if ($this->negativeQtyLines > 0) {
            $block[] = $this->countLine($this->negativeQtyLines, 'ligne(s) à quantité négative');
        }
        if ($this->amountDiscountLines > 0) {
            $block[] = $this->countLine($this->amountDiscountLines, 'ligne(s) dont la remise est un montant : ignorée');
        }
        if ($this->foreignCurrency > 0) {
            $block[] = $this->countLine($this->foreignCurrency, 'commande(s) en devise étrangère');
        }
        if ($this->refCollisions > 0) {
            $block[] = $this->countLine($this->refCollisions, 'référence(s) de boutique déjà prise(s) :'
                .' le numéro du document a été utilisé à la place');
        }
        $this->appendBlock($lines, 'À vérifier :', $block);

        return $lines;
    }

    /**
     * Ligne de rapport alignée sur un compteur.
     *
     * @param int    $count Valeur
     * @param string $text  Libellé
     * @return string
     */
    protected function countLine($count, $text)
    {
        return str_pad((string) $count, 8, ' ', STR_PAD_LEFT).'  '.$text;
    }

    /**
     * Ajoute un bloc titré au rapport, s'il a du contenu.
     *
     * @param array<int,string> $lines Rapport
     * @param string            $title Titre
     * @param array<int,string> $block Contenu
     * @return void
     */
    protected function appendBlock(array &$lines, $title, array $block)
    {
        if (empty($block)) {
            return;
        }
        if (!empty($lines)) {
            $lines[] = '';
        }
        $lines[] = $title;
        foreach ($block as $line) {
            $lines[] = $line;
        }
    }
}
