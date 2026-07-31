<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationsupplierorder.class.php
 * \ingroup aeromigration
 * \brief   Reprise des commandes fournisseur : f_docentete_global -> CommandeFournisseur.
 *
 * Premier script du module à reprendre un **document à lignes**. Les précédents créaient des
 * objets plats ; ici chaque enregistrement source donne un en-tête et ses lignes, le tout
 * dans une seule transaction — celle que le socle ouvre autour de `migrateRow()`.
 *
 * Quatre partis pris demandent une explication.
 *
 * **1. Les numéros d'origine sont conservés.** Dolibarr attribue normalement ses propres
 * références par un module de numérotation, et `create()` insère `ref = '(PROV)'` en dur.
 * Mais `valid()` ne renumérote que si la référence est encore provisoire :
 *
 *     if (preg_match('/^[\(]?PROV/i', (string) $this->ref) || empty($this->ref)) {
 *         $num = $this->getNextNumRef($soc);
 *     } else {
 *         $num = (string) $this->ref;          // fournisseur.commande.class.php:816
 *     }
 *
 * Il suffit donc de poser `ref` avant de valider. Les 2 567 `DO_Piece` étant uniques, et
 * `uk_commande_fournisseur_ref` portant sur cette colonne, la reprise garde les numéros que
 * le client connaît — `CLMBCF990002512` plutôt qu'un `CF2607-0001` sans signification pour
 * lui. Les commandes créées après la bascule reprendront la numérotation du module : deux
 * formats cohabiteront, ce qui rend au passage l'origine de chaque document lisible.
 *
 * **2. Le statut vient de `Z_Annule`, pas de `DO_Statut`.** Cette dernière vaut 9 sur les
 * 2 567 commandes : elle ne distingue rien. L'annulation est portée par `Z_Annule`, colonne
 * ajoutée par l'applicatif par-dessus le schéma Sage — d'où son préfixe. Vérifié par lecture
 * d'écran sur six documents : les cinq annulés portent `A`, celui en cours ne porte rien.
 *
 * Le reste se déduit du chaînage : une commande citée par une réception ou une facture
 * (`DL_PieceBC`) a été honorée.
 *
 *     Z_Annule = 'A'                →  Annulée               603
 *     active + réception/facture    →  Reçue complètement  1 852
 *     active + sans suite           →  Validée               112
 *
 * Ces 112 sont le seul en-cours réel, et plus de la moitié date de 2024 ou après.
 *
 * **3. Valider ne touche pas au stock.** `approve()` crée des mouvements de réception, mais
 * seulement si `STOCK_CALCULATE_ON_SUPPLIER_VALIDATE_ORDER` est posée
 * (fournisseur.commande.class.php:1357) — elle ne l'est pas, non plus que son équivalent sur
 * la ventilation. Vérifié avant d'écrire une ligne : le stock d'ouverture posé par le script
 * `stock` ne risque rien.
 *
 * **4. `setStatus()` plutôt que le parcours des statuts.** Amener une commande à « reçue
 * complètement » par le chemin normal supposerait de créer des réceptions, que la source ne
 * nous donne pas — 3 116 bons pour 2 567 commandes, sans correspondance ligne à ligne
 * exploitable. `setStatus()` écrit le statut et déclenche le trigger correspondant, ce qui
 * est exactement ce qu'il faut ici.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationSupplierOrder extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'supplierorder';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptSupplierOrder';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues : la table en compte plus de 130 */
    protected $srcFields = 'DO_Piece, DO_Date, DO_Tiers, DO_Ref, DO_DateLivr,'
        .' DO_Devise, DO_Cours, Z_Annule';

    /**
     * Colonne de parcours.
     *
     * `cbMarq` vaut 0 sur les 318 030 lignes de la table, comme dans `f_artstock`.
     * `DO_Piece` est unique sur le périmètre lu — 2 567 valeurs pour 2 567 documents — et
     * fait donc un curseur valable. Son ordre n'est pas chronologique, les préfixes ayant
     * changé au fil des ans, mais un curseur n'a besoin que d'être stable et total.
     *
     * @var string
     */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'DO_Piece';

    /** @var string Filtre de lecture : commandes fournisseur uniquement */
    protected $srcWhere = "DO_Domaine = 1 AND DO_Type = 12 AND TRIM(DO_Piece) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'commande_fournisseur';

    /** @var string Élément Dolibarr */
    protected $dstElement = 'supplier_order';

    /** @var int Domaine source des achats */
    const SRC_DOMAIN = 1;

    /** @var int Type source des commandes fournisseur */
    const SRC_TYPE = 12;

    /** @var string Marqueur d'annulation, porté par la colonne applicative Z_Annule */
    const CANCELLED = 'A';

    /** @var int Nombre d'anomalies citées nominativement au rapport */
    const SAMPLES = 8;

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,int> CT_Num -> rowid du fournisseur en cible */
    protected $supplierBySage = array();

    /** @var array<string,array{id:int,ref:string}> AR_Ref -> produit en cible */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> DO_Piece -> lignes du document */
    protected $linesByDocument = array();

    /** @var array<string,bool> DO_Piece des commandes citées par une réception ou une facture */
    protected $documentsWithFollowUp = array();

    /**
     * Codes devise de la source.
     *
     * Même correspondance que dans la reprise des tarifs fournisseurs, et mêmes limites :
     * la source numérote ses devises sans les nommer nulle part. Le zéro et le NULL valent
     * la devise de l'instance.
     *
     * @var array<int,string>
     */
    protected $currencyAliases = array(1 => 'EUR', 2 => 'USD', 4 => 'GBP', 11 => 'CAD');

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Commandes marquées annulées */
    protected $cancelled = 0;

    /** @var int Commandes marquées reçues */
    protected $received = 0;

    /** @var int Commandes laissées validées, en attente de réception */
    protected $pending = 0;

    /** @var int Lignes écrites */
    protected $linesWritten = 0;

    /** @var int Lignes de texte libre, sans article rattaché */
    protected $freeTextLines = 0;

    /** @var int Lignes dont l'article n'a pas été repris */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'article introuvables */
    protected $missingProducts = array();

    /** @var int Lignes portant une remise */
    protected $discountLines = 0;

    /** @var int Lignes dont la remise est un montant et non un taux */
    protected $amountDiscountLines = 0;

    /** @var int Lignes à quantité nulle */
    protected $zeroQtyLines = 0;

    /** @var int Lignes à quantité négative */
    protected $negativeQtyLines = 0;

    /** @var int Lignes sans taux de TVA */
    protected $noVatLines = 0;

    /** @var int Commandes sans aucune ligne */
    protected $emptyOrders = 0;

    /** @var array<int,string> Échantillon de commandes vides */
    protected $emptyOrderSamples = array();

    /** @var int Commandes en devise étrangère */
    protected $foreignCurrency = 0;

    /** @var array<string,int> Devises rencontrées et leur volume */
    protected $currencyCounts = array();

    /** @var int Commandes dont le taux de change source était nul */
    protected $missingRate = 0;

    /** @var array<int,string> Références source de forme inattendue */
    protected $oddReferences = array();

    /** @var int Fournisseurs introuvables en cible */
    protected $missingSuppliers = 0;

    /** @var array<string,bool> Codes fournisseur introuvables */
    protected $missingSupplierCodes = array();

    /**
     * Chargement des référentiels.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        $this->allowInactiveProducts();

        foreach (array(
            'loadSupplierIndex',
            'loadProductIndex',
            'loadDocumentLines',
            'loadFollowUps',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Autorise la validation de commandes portant des articles retirés de l'achat.
     *
     * `valid()` refuse tout document dont une ligne référence un produit dont `tobuy` vaut 0
     * (fournisseur.commande.class.php:805). Le contrôle est justifié à la saisie — on ne
     * commande pas un article qu'on ne référence plus — mais c'est un contresens sur un
     * historique : **1 076 des 15 811 articles repris sont arrêtés**, et les commandes qui
     * les portent ont bel et bien existé. Sans cette levée, 233 commandes sur 2 567 étaient
     * refusées.
     *
     * La constante est posée **en mémoire du processus**, jamais en base : `getDolGlobalBool()`
     * lit `$conf->global`, et un script en ligne de commande a sa propre instance. Le
     * comportement de l'application pour les utilisateurs n'est donc pas modifié, et il n'y a
     * rien à restaurer en fin de passage.
     *
     * @return void
     */
    protected function allowInactiveProducts()
    {
        global $conf;

        if (!isset($conf->global)) {
            return;
        }

        $conf->global->SUPPLIER_ORDER_NOCHECK_ONBUY_PRODUCTS_ONVALID = 1;
    }

    /**
     * Index des fournisseurs repris : CT_Num -> rowid.
     *
     * Les 192 fournisseurs des commandes existent tous en cible, la reprise des tiers les
     * ayant tous couverts. Un manquant est donc un signal, pas un cas ordinaire : la
     * commande est écartée et le code cité au rapport.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadSupplierIndex()
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
            $key = substr((string) $obj->ref_ext, strlen($prefix));
            if ($key !== '') {
                $this->supplierBySage[$key] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des articles repris : AR_Ref -> rowid et référence.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = substr((string) $obj->ref_ext, strlen($prefix));
            if ($key !== '') {
                $this->productBySage[$key] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref);
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Lignes de tous les documents du périmètre, groupées par numéro.
     *
     * Préchargées en une requête plutôt qu'interrogées document par document : 24 265 lignes
     * tiennent sans peine en mémoire, quand 2 567 requêtes coûteraient plusieurs minutes.
     * C'est la même règle que pour les autres référentiels du module.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_Taxe1,';
        $sql .= ' DL_Remise01REM_Valeur, DL_Remise01REM_Type, AF_RefFourniss, DL_Ligne';
        $sql .= ' FROM f_docligne_global';
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
     * Numéros de commande cités par une réception ou une facture fournisseur.
     *
     * C'est ce chaînage — et non un quelconque statut de la source — qui dit si une commande
     * a été honorée.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadFollowUps()
    {
        $sql  = 'SELECT DISTINCT DL_PieceBC FROM f_docligne_global';
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type IN (13, 16)';
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
     * Statut cible d'une commande.
     *
     * @param stdClass $row Ligne source
     * @return int          Constante de statut de CommandeFournisseur
     */
    protected function resolveStatus($row)
    {
        if (strtoupper(trim((string) $row->Z_Annule)) === self::CANCELLED) {
            return CommandeFournisseur::STATUS_CANCELED;
        }

        if (isset($this->documentsWithFollowUp[trim((string) $row->DO_Piece)])) {
            return CommandeFournisseur::STATUS_RECEIVED_COMPLETELY;
        }

        return CommandeFournisseur::STATUS_VALIDATED;
    }

    /**
     * Devise du document, et son taux exprimé dans le sens attendu par Dolibarr.
     *
     * **Les deux systèmes comptent le taux à l'envers l'un de l'autre**, et s'en apercevoir
     * demandait de trouver une valeur connue des deux côtés. L'article `7248` la fournit :
     * son tarif fournisseur vaut 50,80 $ pour 47,244 €, et la ligne de la commande
     * `CLMBCF990002266` porte `DL_PrixUnitaire = 47,710344` avec `DO_Cours = 0,93918`.
     *
     *     50,80 USD × 0,93918 = 47,71 EUR        ← la valeur de la ligne
     *
     * Donc `DO_Cours` compte les **euros par unité de devise**, et les lignes de document
     * sont déjà converties en euros — ce qui rend les montants en euros justes sans rien
     * faire. Dolibarr, lui, compte les **unités de devise par euro** : sa table des taux
     * donne `USD = 1,075268`, soit 1 € = 1,075 $. D'où l'inversion.
     *
     * Sans elle, les 120 commandes en devise affichaient un montant en devise **inférieur**
     * à leur montant en euros — 2 268 € pour 2 041 $ — au lieu de l'inverse. Les totaux en
     * euros, eux, étaient déjà corrects : le défaut ne se voyait que sur l'onglet devise.
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
        if ($code === $conf->currency) {
            return array('code' => $conf->currency, 'rate' => 1.0);
        }

        $sourceRate = (float) $row->DO_Cours;
        if ($sourceRate <= 0) {
            // Un taux nul rendrait tous les montants infinis : on retombe sur la devise de
            // l'instance plutôt que d'écrire des valeurs aberrantes.
            $this->missingRate++;
            return array('code' => $conf->currency, 'rate' => 1.0);
        }

        $this->foreignCurrency++;
        if (!isset($this->currencyCounts[$code])) {
            $this->currencyCounts[$code] = 0;
        }
        $this->currencyCounts[$code]++;

        return array('code' => $code, 'rate' => 1 / $sourceRate);
    }

    /**
     * Reprend une commande et ses lignes.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid déjà repris, 0 sinon
     * @return array{action:string,id:int}
     * @throws Exception Si le coeur refuse une écriture
     */
    protected function migrateRow($row, $existingId)
    {
        $piece = trim((string) $row->DO_Piece);

        // Déjà repris : une commande validée ne se remanie pas, et rejouer ses lignes les
        // dupliquerait. Le passage avec --update ne fait donc que confirmer le statut.
        if ($existingId > 0) {
            return $this->refreshStatus($row, $existingId);
        }

        $supplierCode = trim((string) $row->DO_Tiers);
        if (!isset($this->supplierBySage[$supplierCode])) {
            $this->missingSuppliers++;
            $this->missingSupplierCodes[$supplierCode] = true;
            return array('action' => 'skipped', 'id' => 0);
        }

        $this->noteOddReference($piece);

        $currency = $this->resolveCurrency($row);

        $order = new CommandeFournisseur($this->db);
        $order->socid              = (int) $this->supplierBySage[$supplierCode];
        $order->fourn_id           = (int) $this->supplierBySage[$supplierCode];
        $order->date_commande      = $this->db->jdate($row->DO_Date);
        $order->date               = $order->date_commande;
        $order->ref_ext            = $this->buildRefExt($piece);
        $order->ref_supplier       = trim((string) $row->DO_Ref);
        $order->multicurrency_code = $currency['code'];
        $order->multicurrency_tx   = $currency['rate'];
        $order->note_private       = 'Commande reprise de l\'ancien ERP — '.$piece;

        if (!empty($row->DO_DateLivr)) {
            $order->delivery_date = $this->db->jdate($row->DO_DateLivr);
        }

        if ($order->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($order));
        }

        // `create()` n'écrit ni `ref_ext` ni `date_commande` : sa requête d'insertion ne
        // comporte tout simplement pas ces colonnes, et pose `ref = '(PROV<id>)'`. Sans ce
        // rattrapage, la reprise n'aurait aucune clé d'idempotence — un second passage
        // recréerait les 2 567 commandes — et toutes seraient sans date.
        //
        // `update()` écrit les trois, ainsi que la référence : la poser ici évite d'avoir à
        // la reposer avant `valid()`, qui la conservera puisqu'elle n'est plus provisoire
        // (fournisseur.commande.class.php:816).
        $order->ref           = $piece;
        $order->ref_ext       = $this->buildRefExt($piece);
        $order->date_commande = $this->db->jdate($row->DO_Date);

        if ($order->update($this->user, 1) <= 0) {
            throw new Exception('Référence et date non écrites : '.$this->objectErrors($order));
        }

        $this->addLines($order, $piece);

        if ($order->valid($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($order));
        }

        $status = $this->resolveStatus($row);
        if ($status !== CommandeFournisseur::STATUS_VALIDATED) {
            if ($order->setStatus($this->user, $status) <= 0) {
                throw new Exception('Statut '.$status.' refusé : '.$this->objectErrors($order));
            }
        }
        $this->countStatus($status);

        return array('action' => 'created', 'id' => (int) $order->id);
    }

    /**
     * Réaligne le statut d'une commande déjà reprise.
     *
     * Seule opération idempotente sur un document validé : ses lignes et sa référence ne se
     * remanient plus, mais son statut peut avoir été décidé autrement lors d'un passage
     * antérieur, ou modifié à la main.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la commande
     * @return array{action:string,id:int}
     */
    protected function refreshStatus($row, $existingId)
    {
        $status = $this->resolveStatus($row);

        $order = new CommandeFournisseur($this->db);
        if ($order->fetch($existingId) <= 0) {
            return array('action' => 'skipped', 'id' => $existingId);
        }

        if ((int) $order->statut === $status) {
            return array('action' => 'skipped', 'id' => $existingId);
        }

        if ($order->setStatus($this->user, $status) <= 0) {
            throw new Exception('Statut '.$status.' refusé : '.$this->objectErrors($order));
        }

        $this->countStatus($status);

        return array('action' => 'updated', 'id' => $existingId);
    }

    /**
     * Ajoute les lignes du document.
     *
     * @param CommandeFournisseur $order Commande créée, encore au brouillon
     * @param string              $piece Numéro du document source
     * @return void
     * @throws Exception Si le coeur refuse une ligne
     */
    protected function addLines(CommandeFournisseur $order, $piece)
    {
        if (empty($this->linesByDocument[$piece])) {
            $this->emptyOrders++;
            if (count($this->emptyOrderSamples) < self::SAMPLES) {
                $this->emptyOrderSamples[] = $piece;
            }
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
                0,
                $map['supplierRef'],
                $map['discount'],
                'HT'
            );

            if ($result <= 0) {
                throw new Exception('Ligne refusée (article '.($map['sourceRef'] !== '' ? $map['sourceRef'] : 'texte libre')
                    .') : '.$this->objectErrors($order));
            }

            $this->linesWritten++;
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
        $productId = 0;

        if ($sourceRef === '') {
            $this->freeTextLines++;
        } elseif (isset($this->productBySage[$sourceRef])) {
            $productId = (int) $this->productBySage[$sourceRef]['id'];
        } else {
            // L'article n'a pas été repris : la ligne reste, en texte libre. La perdre
            // fausserait le montant du document.
            $this->missingProductLines++;
            $this->missingProducts[$sourceRef] = true;
        }

        $desc = trim((string) $line->DL_Design);
        if ($desc === '') {
            $desc = ($sourceRef !== '') ? $sourceRef : 'Ligne sans désignation';
        }

        $qty = (float) $line->DL_Qte;
        if ($qty == 0) {
            $this->zeroQtyLines++;
        } elseif ($qty < 0) {
            $this->negativeQtyLines++;
        }

        $vat = $line->DL_Taxe1;
        if ($vat === null) {
            $this->noVatLines++;
            $vat = 0;
        }

        // La source autorise trois remises par ligne, mais seule la première est utilisée
        // sur ce périmètre — les deux autres sont nulles sur les 24 265 lignes.
        $discount = (float) $line->DL_Remise01REM_Valeur;
        if ($discount != 0) {
            $this->discountLines++;

            // Type 1 = montant et non pourcentage, sur 16 lignes de tout le jeu. Dolibarr
            // n'a qu'un taux par ligne : le montant est ignoré plutôt que d'être pris pour
            // un pourcentage, ce qui fausserait la ligne bien davantage.
            if ((int) $line->DL_Remise01REM_Type === 1) {
                $this->amountDiscountLines++;
                $discount = 0;
            }
        }
        if ($discount < 0) {
            // Une remise négative est une majoration, que Dolibarr ne sait pas représenter.
            $discount = 0;
        }

        return array(
            'desc'        => $desc,
            'price'       => (float) $line->DL_PrixUnitaire,
            'qty'         => $qty,
            'vat'         => (float) $vat,
            'productId'   => $productId,
            'supplierRef' => trim((string) $line->AF_RefFourniss),
            'discount'    => $discount,
            'sourceRef'   => $sourceRef,
        );
    }

    /**
     * Relève une référence source de forme inattendue.
     *
     * Le jeu en compte trois générations — `CF7344`, `BCF990000001`, `CLMBCF990002512` — et
     * une valeur corrompue, `undefinedBCF9900`, où le `undefined` de JavaScript s'est glissé
     * dans le numéro. Elle reste unique, donc utilisable comme référence, mais le client doit
     * savoir qu'elle existe.
     *
     * @param string $piece Numéro du document
     * @return void
     */
    protected function noteOddReference($piece)
    {
        if (stripos($piece, 'undefined') === false) {
            return;
        }
        if (count($this->oddReferences) < self::SAMPLES) {
            $this->oddReferences[] = $piece;
        }
    }

    /**
     * Tient les compteurs de statut.
     *
     * @param int $status Statut appliqué
     * @return void
     */
    protected function countStatus($status)
    {
        if ($status === CommandeFournisseur::STATUS_CANCELED) {
            $this->cancelled++;
        } elseif ($status === CommandeFournisseur::STATUS_RECEIVED_COMPLETELY) {
            $this->received++;
        } else {
            $this->pending++;
        }
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de créer un document valide
     */
    protected function validateRow($row)
    {
        $piece        = trim((string) $row->DO_Piece);
        $supplierCode = trim((string) $row->DO_Tiers);

        if (!isset($this->supplierBySage[$supplierCode])) {
            $this->missingSuppliers++;
            $this->missingSupplierCodes[$supplierCode] = true;
            return;
        }

        $this->noteOddReference($piece);
        $this->resolveCurrency($row);
        $this->countStatus($this->resolveStatus($row));

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyOrders++;
            if (count($this->emptyOrderSamples) < self::SAMPLES) {
                $this->emptyOrderSamples[] = $piece;
            }
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
        if (!isset($this->supplierBySage[trim((string) $row->DO_Tiers)])) {
            return 'skipped';
        }

        return ($existingId > 0) ? 'updated' : 'created';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des commandes fournisseur reprises (ref_ext commençant par « '
            .$this->refExtPrefix.' »), lignes comprises';
    }

    /**
     * Défait la reprise.
     *
     * Une commande validée refuse d'être supprimée : elle doit repasser au brouillon, ce que
     * `setStatus()` fait sans exiger le chemin inverse complet. `delete()` retire ensuite le
     * document et ses lignes.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'commande_fournisseur';
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
            $order = new CommandeFournisseur($this->db);
            if ($order->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'Commande '.$row->ref.' illisible';
                continue;
            }

            if ((int) $order->statut !== CommandeFournisseur::STATUS_DRAFT) {
                $order->setStatus($this->user, CommandeFournisseur::STATUS_DRAFT);
            }

            if ($order->delete($this->user) > 0) {
                $result['deleted']++;
            } else {
                $result['failed']++;
                $result['errors'][] = 'Commande '.$row->ref.' : '.$this->objectErrors($order);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 100 === 0)) {
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

        $this->reportOrders($lines);
        $this->reportLines($lines);
        $this->reportAnomalies($lines);

        return $lines;
    }

    /**
     * Les commandes et leur statut.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportOrders(array &$lines)
    {
        $block = array();

        $block[] = $this->countLine($this->received, 'commande(s) reçue(s) complètement');
        $block[] = $this->countLine($this->cancelled, 'commande(s) annulée(s) dans l\'ancien ERP');
        $block[] = $this->countLine($this->pending, 'commande(s) validée(s), en attente de réception');
        $block[] = '          Le statut vient de la colonne Z_Annule et du chaînage vers les';
        $block[] = '          réceptions et factures : DO_Statut vaut 9 sur toute la table.';
        $block[] = '          Les numéros d\'origine sont conservés en référence.';

        if ($this->foreignCurrency > 0) {
            $detail = array();
            foreach ($this->currencyCounts as $code => $nb) {
                $detail[] = $nb.' en '.$code;
            }
            $block[] = $this->countLine($this->foreignCurrency, 'commande(s) en devise étrangère : '
                .implode(', ', $detail));
        }
        if ($this->missingRate > 0) {
            $block[] = $this->countLine($this->missingRate, 'commande(s) en devise sans taux de change :'
                .' reprises dans la devise de l\'instance');
        }

        $this->appendBlock($lines, 'Commandes fournisseur :', $block);
    }

    /**
     * Les lignes de commande.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportLines(array &$lines)
    {
        $block = array();

        $block[] = $this->countLine($this->linesWritten, 'ligne(s) de commande');

        if ($this->discountLines > 0) {
            $block[] = $this->countLine($this->discountLines, 'ligne(s) avec remise');
        }
        if ($this->freeTextLines > 0) {
            $block[] = $this->countLine($this->freeTextLines, 'ligne(s) de texte libre, sans article');
        }
        if ($this->noVatLines > 0) {
            $block[] = $this->countLine($this->noVatLines, 'ligne(s) sans taux de TVA, reprises à 0 %');
        }

        $this->appendBlock($lines, 'Lignes :', $block);
    }

    /**
     * Ce qui mérite un coup d'oeil.
     *
     * @param array<int,string> $lines Rapport en cours de construction
     * @return void
     */
    protected function reportAnomalies(array &$lines)
    {
        $block = array();

        if ($this->missingSuppliers > 0) {
            $block[] = $this->countLine($this->missingSuppliers, 'commande(s) écartée(s), fournisseur non repris : '
                .implode(', ', array_slice(array_keys($this->missingSupplierCodes), 0, self::SAMPLES)));
        }
        if ($this->emptyOrders > 0) {
            $block[] = $this->countLine($this->emptyOrders, 'commande(s) sans aucune ligne, reprises telles quelles');
            if (!empty($this->emptyOrderSamples)) {
                $block[] = '            '.implode(', ', $this->emptyOrderSamples);
            }
        }
        if ($this->missingProductLines > 0) {
            $block[] = $this->countLine($this->missingProductLines, 'ligne(s) dont l\'article n\'est pas repris,'
                .' conservées en texte libre : '
                .implode(', ', array_slice(array_keys($this->missingProducts), 0, self::SAMPLES)));
        }
        if ($this->zeroQtyLines > 0) {
            $block[] = $this->countLine($this->zeroQtyLines, 'ligne(s) à quantité nulle');
        }
        if ($this->negativeQtyLines > 0) {
            $block[] = $this->countLine($this->negativeQtyLines, 'ligne(s) à quantité négative, reprises telles quelles');
        }
        if ($this->amountDiscountLines > 0) {
            $block[] = $this->countLine($this->amountDiscountLines, 'ligne(s) dont la remise est un montant :'
                .' ignorée, Dolibarr n\'ayant qu\'un taux par ligne');
        }
        if (!empty($this->oddReferences)) {
            $block[] = $this->countLine(count($this->oddReferences), 'référence(s) source corrompue(s),'
                .' reprises telles quelles : '.implode(', ', $this->oddReferences));
        }

        if (empty($block)) {
            return;
        }

        $this->appendBlock($lines, 'À vérifier :', $block);
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
     * @param string            $title Titre du bloc
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
