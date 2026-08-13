<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationinvoice.class.php
 * \ingroup aeromigration
 * \brief   Reprise des factures clients et de leurs règlements : f_docentete_global -> Facture.
 *
 * Le script suit la structure des commandes clients, dont il reprend les mécanismes éprouvés :
 * lignes préchargées par document, index des tiers et des articles, adoption des objets que la
 * cible possède déjà. Quatre points lui sont propres.
 *
 * **1. La source tient les factures en deux populations.** `DO_Type = 6` pour les factures
 * courantes, `DO_Type = 7` pour les factures comptabilisées. Les deux sont des factures clients
 * réelles et leurs numéros ne se recoupent jamais — vérifié : zéro pièce commune. Le type 7
 * couvre 2015 → 2024 et le type 6 2019 → 2026 ; ensemble, **182 832 factures et 525 402 lignes**.
 *
 * **2. Une partie des factures existe déjà, créées par Prestasync.** Le module facture chaque
 * commande de la boutique par `Facture::createFromOrder()`, ce qui pose un lien d'origine dans
 * llx_element_element. Ces factures sont donc **adoptées** : reconnues par la commande dont elles
 * dépendent, marquées d'un `ref_ext`, et laissées intactes pour tout le reste.
 *
 * Le rapprochement ne peut PAS se faire par la référence, contrairement aux commandes : Prestasync
 * impose la sienne sur la commande, mais laisse la facture prendre le numéro du compteur Dolibarr.
 * Il passe donc par la commande, dont le `ref_ext` porte la clé source.
 *
 * Il ne peut pas non plus se faire sur le préfixe `CI-` des commandes de la boutique. Mesuré sur
 * l'instance de reprise :
 *
 *     factures issues d'une commande boutique .................. 55 131
 *     dont la commande porte DÉJÀ une facture Dolibarr ......... 28 313   → adoption
 *     dont la commande n'en porte AUCUNE ....................... 31 832   → création
 *
 * Prestasync n'a facturé que depuis 2023 ; écarter toutes les commandes de la boutique ferait
 * disparaître les 31 832 factures antérieures. C'est l'état réel de la cible qui décide, pas
 * l'origine du document.
 *
 * **3. Les règlements sont portés tantôt par la facture, tantôt par la commande.** La vente au
 * comptoir facture directement et encaisse sur la facture ; la vente en ligne encaisse sur la
 * commande, avant facturation. Sur 110 103 règlements : 54 064 sur des factures, 55 337 sur des
 * commandes, 12 496 sur un document qui n'existe plus. Dolibarr ne sait rattacher un paiement
 * qu'à une facture : les deux gisements convergent donc vers la facture, et les orphelins sont
 * comptés au rapport sans être repris.
 *
 * Les règlements des factures ADOPTÉES ne sont jamais recréés : Prestasync les a déjà posés.
 *
 * **4. Les avoirs se reconnaissent à leur total, et le seuil compte.** La source n'a pas de type
 * distinct : un avoir est une facture dont le total est négatif — 1 934 documents. Encore
 * faut-il que ce total le soit franchement.
 *
 * Dolibarr force chaque ligne d'un avoir à être négative : quantité en valeur absolue, prix
 * unitaire en négatif, total en négatif (facture.class.php:4415-4427). Une ligne de reprise,
 * négative à la source, y devient donc positive et **s'ajoute au remboursement au lieu de s'en
 * retrancher**. Un document dont les lignes se compensent est alors gravement faussé : mesuré sur
 * AF990026466, dont le total passait de 0 à -89,82 €.
 *
 * Or 1 024 documents totalisent quelques millièmes d'euro négatifs — un résidu d'arrondi, la
 * source stockant ses prix à six décimales. Les classer en avoir sur la foi de ce signe les
 * aurait tous abîmés. Le seuil (self::ROUNDING) les laisse en factures ordinaires, où les lignes
 * gardent leur signe et le total reste juste.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationInvoice extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'invoice';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptInvoice';

    /**
     * Base de l'ancien ERP.
     *
     * L'export ADD vit dans une base à part : les tables f_* et z_* ne sont pas dans celle de
     * Dolibarr. Toute lecture de la source passe donc par src(), y compris les requêtes propres à
     * ce script — les oublier ferait chercher f_docligne_global dans la base Dolibarr, où elle
     * n'existe pas.
     *
     * @var string
     */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues */
    protected $srcFields = 'DO_Piece, DO_Type, DO_Date, DO_Tiers, DO_Ref, DO_Devise, DO_Cours, Z_Annule';

    /** @var string Colonne de parcours : cbMarq vaut 0 sur toute la table */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'DO_Piece';

    /**
     * Filtre de lecture.
     *
     * Les documents sans tiers sont écartés dès la lecture : une facture client sans client n'a
     * pas de sens en cible, et le socle les compterait en erreur à chaque passage.
     *
     * @var string
     */
    protected $srcWhere = "DO_Domaine = 0 AND DO_Type IN (6,7) AND TRIM(DO_Piece) <> ''"
        ." AND TRIM(COALESCE(DO_Tiers,'')) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'facture';

    /** @var string Élément Dolibarr */
    protected $dstElement = 'facture';

    /** @var int Domaine source des ventes */
    const SRC_DOMAIN = 0;

    /** @var int Type source des factures courantes */
    const SRC_TYPE_INVOICE = 6;

    /** @var int Type source des factures comptabilisées */
    const SRC_TYPE_ACCOUNTED = 7;

    /** @var int Type source des commandes, qui portent une partie des règlements */
    const SRC_TYPE_ORDER = 1;

    /** @var string Marqueur d'annulation */
    const CANCELLED = 'A';

    /**
     * Indices de la source désignant un règlement par avoir.
     *
     * « Avoirs » (5) et « avoir en cour » (9) : une compensation entre deux pièces, et non un
     * encaissement. Dolibarr n'a pas de mode de paiement pour cela, et c'est logique.
     */
    const CREDIT_MODES = array(5, 9);

    /** @var int Nombre d'anomalies citées nominativement au rapport */
    const SAMPLES = 8;

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,int> CT_Num en minuscules -> rowid du tiers */
    protected $customerBySage = array();

    /** @var array<string,int> AR_Ref en minuscules -> rowid du produit */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> DO_Piece -> lignes de la facture */
    protected $linesByDocument = array();

    /** @var array<string,int> DO_Piece de la commande source -> rowid de la commande reprise */
    protected $orderBySage = array();

    /** @var array<int,int> rowid de commande -> rowid de la facture déjà liée (Prestasync) */
    protected $invoiceByOrder = array();

    /** @var array<string,string> DO_Piece de la facture -> DO_Piece de sa commande d'origine */
    protected $orderOfInvoice = array();

    /** @var array<string,array<int,stdClass>> DO_Piece du document -> règlements */
    protected $paymentsByDocument = array();

    /** @var array<int,int> cbIndice du mode source -> id du mode Dolibarr */
    protected $paymentModes = array();

    /** @var int Compte bancaire d'affectation des règlements repris */
    protected $bankAccountId = 0;

    /** @var int Mode de repli pour les indices absents du dictionnaire source (0 = aucun) */
    protected $fallbackMode = 0;

    /** @var array<string,int> Fragment de libellé -> id du mode Dolibarr, du plus précis au plus large */
    protected $modesByLabel = array();

    // ── Compteurs du rapport ───────────────────────────────────────────────

    /** @var int Factures adoptées, créées par Prestasync */
    protected $adopted = 0;

    /** @var int Factures rattachées à leur commande */
    protected $linkedToOrder = 0;

    /** @var int Avoirs créés */
    protected $creditNotes = 0;

    /** @var int Factures abandonnées (annulées à la source) */
    protected $abandoned = 0;

    /** @var int Documents sans la moindre ligne */
    protected $emptyDocuments = 0;

    /** @var int Lignes dont l'article est introuvable en cible */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'articles introuvables */
    protected $missingProducts = array();

    /** @var int Factures dont le client est introuvable */
    protected $missingCustomers = 0;

    /** @var array<string,bool> Codes clients introuvables */
    protected $missingCustomerCodes = array();

    /** @var int Lignes de texte libre (sans article) */
    protected $freeTextLines = 0;

    /** @var int Lignes majorées par une remise négative, reportée sur le prix unitaire */
    protected $markupLines = 0;

    /** @var int Règlements créés */
    protected $paymentsCreated = 0;

    /** @var int Règlements repris depuis la commande plutôt que depuis la facture */
    protected $paymentsFromOrder = 0;

    /** @var int Règlements écartés : mode source inconnu */
    protected $paymentsUnknownMode = 0;

    /** @var int Règlements écartés : montant nul */
    protected $paymentsZero = 0;

    /** @var int Règlements écartés : compensation par avoir, pas un encaissement */
    protected $paymentsCreditNote = 0;

    /** @var array<int,bool> Indices de mode rencontrés sans correspondance */
    protected $unknownModes = array();

    /** @var int Règlements dont le mode a été lu dans le libellé, faute d'indice */
    protected $paymentsByLabel = 0;

    /**
     * Décision prise en simulation, par document.
     *
     * @var array<string,string>
     */
    protected $previewDecision = array();

    /**
     * Charge les index nécessaires à la reprise.
     *
     * @return int 1 si OK, -1 en cas d'erreur
     */
    protected function prepare()
    {
        // Le trigger « garantie / n° de série » d'aerotoolbox s'exécute à chaque ligne de facture
        // insérée : deux requêtes et une écriture, pour recopier un bloc dans la description. À la
        // saisie c'est invisible ; sur 525 000 lignes historiques, cela pèse plus d'un million de
        // requêtes — mesuré, il triplait la durée de la reprise.
        //
        // Il est neutralisé le temps du passage, EN MÉMOIRE seulement : la constante n'est pas
        // écrite en base, et l'application la retrouve active dès la requête suivante. Rien n'est
        // perdu au passage : les numéros de série ne sont pas repris, et une facture de 2016 n'a
        // pas de garantie à afficher.
        global $conf;
        $conf->global->AEROTB_DISABLE_LINE_WARRANTY = 1;

        if ($this->loadCustomerIndex() < 0) {
            return -1;
        }
        if ($this->loadProductIndex() < 0) {
            return -1;
        }
        if ($this->loadOrderIndex() < 0) {
            return -1;
        }
        if ($this->loadExistingInvoices() < 0) {
            return -1;
        }
        if ($this->loadDocumentLines() < 0) {
            return -1;
        }
        if ($this->loadPayments() < 0) {
            return -1;
        }

        $this->loadPaymentModes();

        return 1;
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
     * Index des commandes reprises : DO_Piece de la commande -> rowid.
     *
     * C'est par lui que passe le rattachement de la facture à sa commande, et la détection des
     * factures que Prestasync a déjà produites.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadOrderIndex()
    {
        $prefix = $this->refExtPrefix;

        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'commande';
        $sql .= ' WHERE entity IN ('.getEntity('commande').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($prefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $key = $this->indexKey(substr((string) $obj->ref_ext, strlen($prefix)));
            if ($key !== '') {
                $this->orderBySage[$key] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Factures déjà présentes en cible et liées à une commande.
     *
     * Ce sont celles de Prestasync. Le lien d'origine posé par createFromOrder() est la seule
     * trace exploitable : la facture ne porte ni la référence de la boutique, ni de marqueur.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadExistingInvoices()
    {
        $sql  = 'SELECT ee.fk_source, ee.fk_target FROM '.MAIN_DB_PREFIX.'element_element as ee';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = ee.fk_target';
        $sql .= " WHERE ee.sourcetype = 'commande' AND ee.targettype = 'facture'";
        $sql .= ' AND f.entity IN ('.getEntity('facture').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // Une commande peut porter plusieurs factures (acomptes, refacturation). La première
            // suffit : elle établit que la commande est facturée, ce qui est la seule question posée.
            $orderId = (int) $obj->fk_source;
            if (!isset($this->invoiceByOrder[$orderId])) {
                $this->invoiceByOrder[$orderId] = (int) $obj->fk_target;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Lignes des factures, groupées par numéro de document.
     *
     * 525 402 lignes préchargées en une requête, comme pour les commandes : le jeu tient en
     * mémoire là où autant de requêtes unitaires coûteraient des heures. C'est ce qui impose le
     * `memory_limit` relevé documenté dans le README.
     *
     * La colonne DL_PieceBC est lue ici et non dans une requête à part : elle porte la commande
     * d'origine, et c'est la première ligne renseignée qui fait foi pour le document entier.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_Taxe1,';
        $sql .= ' DL_Remise01REM_Valeur, DL_Remise01REM_Type, DL_Ligne, DL_PieceBC';
        $sql .= ' FROM '.$this->src('f_docligne_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN;
        $sql .= ' AND DO_Type IN ('.self::SRC_TYPE_INVOICE.','.self::SRC_TYPE_ACCOUNTED.')';
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

            $bc = trim((string) $obj->DL_PieceBC);
            if ($bc !== '' && !isset($this->orderOfInvoice[$piece])) {
                $this->orderOfInvoice[$piece] = $bc;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Règlements, groupés par document.
     *
     * Les deux gisements sont chargés ensemble — ceux portés par les factures et ceux portés par
     * les commandes — et distingués par la clé, qui est le numéro de pièce. Un même numéro ne peut
     * pas désigner à la fois une facture et une commande : les séries sont disjointes.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadPayments()
    {
        $sql  = 'SELECT DO_Piece, DO_Type, DR_Date, DR_Montant, DR_Libelle, N_Reglement, DR_No';
        $sql .= ' FROM '.$this->src('z_docregl_global');
        $sql .= ' WHERE DO_Domaine = '.self::SRC_DOMAIN;
        $sql .= ' AND DO_Type IN ('.self::SRC_TYPE_INVOICE.','.self::SRC_TYPE_ACCOUNTED.','.self::SRC_TYPE_ORDER.')';
        $sql .= ' ORDER BY DO_Piece, DR_No';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $piece = trim((string) $obj->DO_Piece);
            if ($piece !== '') {
                $this->paymentsByDocument[$piece][] = $obj;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Correspondance des modes de règlement, et compte bancaire d'affectation.
     *
     * L'ancien ERP distingue trois canaux de carte bancaire — internet, magasin, téléphone — là
     * où Dolibarr n'a qu'un code CB. Les modes « CBNET » et « CBMAG » que pose le module
     * aerotoolbox sont employés s'ils existent et sont actifs ; sinon tout retombe sur CB, sans
     * que la reprise s'interrompe pour autant. La distinction est une amélioration, pas une
     * condition.
     *
     * @return void
     */
    protected function loadPaymentModes()
    {
        $byCode = array();
        $sql    = 'SELECT id, code FROM '.MAIN_DB_PREFIX.'c_paiement';
        $sql   .= ' WHERE active = 1 AND entity IN ('.getEntity('c_paiement').')';

        $resql = $this->db->query($sql);
        while ($resql && ($obj = $this->db->fetch_object($resql))) {
            $byCode[strtoupper(trim((string) $obj->code))] = (int) $obj->id;
        }

        $cb  = isset($byCode['CB']) ? $byCode['CB'] : 0;
        $net = isset($byCode['CBNET']) ? $byCode['CBNET'] : $cb;
        $mag = isset($byCode['CBMAG']) ? $byCode['CBMAG'] : $cb;

        // Indices de p_reglement, relevés dans la source.
        $this->paymentModes = array(
            1 => isset($byCode['LIQ']) ? $byCode['LIQ'] : 0,   // Espèces
            2 => isset($byCode['CHQ']) ? $byCode['CHQ'] : 0,   // Chèques
            3 => $mag,                                          // CB magasin
            4 => isset($byCode['VIR']) ? $byCode['VIR'] : 0,   // Virements
            7 => $net,                                          // CB internet
            8 => $cb,                                           // CB téléphone
            10 => $mag,                                         // CB portable / extérieur
        );

        // L'indice 10 mérite un mot : « CB Portable / extérieur » désigne un terminal mobile,
        // donc un encaissement en présentiel hors des murs — 34 règlements, 6 908,84 €, entre
        // mars 2024 et mars 2025. Il rejoint la carte magasin plutôt que la carte internet.
        //
        // Ses libellés ne l'auraient pas rattrapé : 28 sont vides, et les 6 autres portent
        // « JPO Lasbordes », un seul client sur deux factures de juillet 2024. Sans cette
        // entrée, ils tombaient tous en mode inconnu et n'étaient pas repris.

        // Correspondance de secours, par le libellé du règlement.
        //
        // 1 540 règlements portent l'indice 0, absent du dictionnaire de la source : le champ
        // n'était pas alimenté avant 2021. Leur moyen de paiement n'est pas perdu pour autant —
        // il est écrit en toutes lettres à côté. 1 539 d'entre eux annoncent « Carte Bancaire
        // Internet », pour 141 005 € ; ils viennent tous de commandes de la boutique.
        //
        // Lire le libellé vaut mieux que se rabattre sur un mode choisi d'avance : on reprend ce
        // que la source dit, au lieu de parier sur ce qu'elle voulait dire.
        $this->modesByLabel = array(
            'carte bancaire internet' => $net,
            'carte bancaire magasin'  => $mag,
            'carte bancaire'          => $cb,
            'paiement en compte'      => isset($byCode['VIR']) ? $byCode['VIR'] : 0,
            'especes'                 => isset($byCode['LIQ']) ? $byCode['LIQ'] : 0,
            'cheque'                  => isset($byCode['CHQ']) ? $byCode['CHQ'] : 0,
            'virement'                => isset($byCode['VIR']) ? $byCode['VIR'] : 0,
        );

        // Dernier recours, si le libellé ne dit rien non plus. Sans réglage, le règlement n'est
        // pas repris : mieux vaut un manque signalé qu'un mode inventé.
        $fallbackCode = strtoupper(getDolGlobalString('AEROMIG_PAYMENT_FALLBACK_CODE', ''));
        $this->fallbackMode = ($fallbackCode !== '' && isset($byCode[$fallbackCode]))
            ? $byCode[$fallbackCode] : 0;

        $this->bankAccountId = (int) getDolGlobalInt('AEROMIG_INVOICE_BANK_ACCOUNT',
            getDolGlobalInt('PRESTASYNC_INVOICE_BANK_ACCOUNT', 0));
    }

    /**
     * Mode de règlement déduit du libellé, quand l'indice de la source ne dit rien.
     *
     * La comparaison est faite sur un libellé désaccentué, en minuscules et aux espaces resserrés
     * — la source écrit « exo tva   autre remise » avec trois espaces —, et par inclusion plutôt
     * que par égalité : « Carte Bancaire Internet » se retrouve dans des libellés plus longs.
     *
     * Les clés les plus précises passent avant les plus générales, l'ordre du tableau faisant foi :
     * sans cela, « carte bancaire » attraperait les paiements internet avant leur propre entrée.
     *
     * @param  string $label Libellé du règlement à la source
     * @return int           Identifiant du mode Dolibarr, 0 si le libellé n'apprend rien
     */
    protected function modeFromLabel($label)
    {
        $needle = dol_string_unaccent(strtolower(trim((string) $label)));
        $needle = preg_replace('/\s+/', ' ', $needle);
        if ($needle === '') {
            return 0;
        }

        foreach ($this->modesByLabel as $key => $modeId) {
            if ($modeId > 0 && strpos($needle, $key) !== false) {
                return (int) $modeId;
            }
        }

        return 0;
    }

    /**
     * Clé d'index normalisée : casse et espaces neutralisés.
     *
     * @param  string $value Valeur brute
     * @return string
     */
    protected function indexKey($value)
    {
        return strtolower(trim((string) $value));
    }

    /**
     * Commande reprise dont dépend une facture source, s'il y en a une.
     *
     * @param  string $piece Numéro de la facture source
     * @return int           rowid de la commande, 0 si aucune
     */
    protected function orderIdOf($piece)
    {
        if (!isset($this->orderOfInvoice[$piece])) {
            return 0;
        }
        $key = $this->indexKey($this->orderOfInvoice[$piece]);

        return isset($this->orderBySage[$key]) ? (int) $this->orderBySage[$key] : 0;
    }

    /**
     * Écart en deçà duquel un total est tenu pour nul.
     *
     * Un document dont les lignes s'annulent laisse un résidu d'arrondi de quelques millièmes
     * d'euro — la source stocke des prix à six décimales. Sans ce seuil, 1 024 documents à
     * -0,003 € seraient classés en avoir : leur signe ne dit rien, il est le fruit de l'arrondi.
     */
    const ROUNDING = 0.01;

    /**
     * Total hors taxes d'un document source, calculé sur ses lignes, remises comprises.
     *
     * La source ne stocke aucun total d'entête : Sage les recalcule à l'affichage. Le signe de
     * cette somme est ce qui distingue une facture d'un avoir, d'où l'importance d'y intégrer les
     * remises — une remise de 20 % sur une ligne peut renverser le signe du document.
     *
     * @param  string $piece Numéro du document
     * @return float
     */
    protected function documentTotal($piece)
    {
        if (empty($this->linesByDocument[$piece])) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($this->linesByDocument[$piece] as $line) {
            $amount = (float) $line->DL_Qte * (float) $line->DL_PrixUnitaire;

            // La remise est TOUJOURS un pourcentage, quel que soit DL_Remise01REM_Type — voir
            // mapLine() pour la démonstration. Dans les deux sens : la source majore par une
            // remise négative.
            $discount = (float) $line->DL_Remise01REM_Valeur;
            if ($discount != 0) {
                $amount *= (1 - $discount / 100);
            }
            $total += $amount;
        }

        return $total;
    }

    /**
     * Le document est-il un avoir ?
     *
     * Deux conditions, et la seconde est celle qui compte.
     *
     * **Le total doit être franchement négatif**, au-delà du bruit d'arrondi : 1 024 documents
     * totalisent quelques millièmes d'euro négatifs, résidu de prix stockés à six décimales.
     *
     * **Et toutes les lignes doivent aller dans le même sens.** Dans un avoir, Dolibarr force
     * chaque ligne à être négative — quantité en valeur absolue, prix unitaire et total en négatif
     * (facture.class.php:4415-4427). Une ligne de reprise, négative à la source, y devient donc
     * positive et s'ajoute au remboursement au lieu de s'en retrancher. Un document aux signes
     * mixtes est irreprésentable en avoir : il reste une facture ordinaire, où Dolibarr respecte
     * le signe de chaque ligne et où le total tombe juste, fût-il négatif.
     *
     * Mesuré : AF990026466 passait de 0 à -89,82 €, et AF990009815 de -21,06 à -446,94 €.
     *
     * @param  string $piece Numéro du document
     * @return bool
     */
    protected function isCreditNote($piece)
    {
        if ($this->documentTotal($piece) >= -self::ROUNDING) {
            return false;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            if ((float) $line->DL_Qte * (float) $line->DL_PrixUnitaire > self::ROUNDING) {
                return false; // au moins une ligne positive : Dolibarr ne saurait pas la porter
            }
        }

        return true;
    }

    /**
     * Reprend une facture, ou adopte celle que Prestasync a déjà créée.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Identifiant déjà repris (0 si nouveau)
     * @return array{action:string,id:int}
     * @throws Exception
     */
    protected function migrateRow($row, $existingId)
    {
        $piece = trim((string) $row->DO_Piece);

        if ($existingId > 0) {
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // La commande dont dépend cette facture a-t-elle déjà été facturée par la boutique ?
        // Testé avant toute écriture : c'est ce qui évite 28 313 doublons.
        $orderId = $this->orderIdOf($piece);
        if ($orderId > 0 && isset($this->invoiceByOrder[$orderId])) {
            return $this->adoptInvoice($this->invoiceByOrder[$orderId], $piece, $orderId);
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            return array('action' => 'skipped', 'id' => 0);
        }

        if (empty($this->linesByDocument[$piece])) {
            // 430 documents dans ce cas. Une facture sans ligne ne porte aucun montant : la créer
            // reviendrait à poser une pièce comptable vide, que personne ne saurait interpréter.
            $this->emptyDocuments++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $isCreditNote = $this->isCreditNote($piece);

        $invoice = new Facture($this->db);
        $invoice->socid        = (int) $this->customerBySage[$customerCode];
        $invoice->date         = $this->db->jdate($row->DO_Date);
        $invoice->type         = $isCreditNote ? Facture::TYPE_CREDIT_NOTE : Facture::TYPE_STANDARD;
        $invoice->ref_ext      = $this->buildRefExt($piece);
        $invoice->ref_client   = trim((string) $row->DO_Ref);
        $invoice->note_private = 'Facture reprise de l\'ancien ERP — '.$piece;

        // Rattachement à la commande : posé avant create(), qui enregistre alors le lien d'origine
        // dans llx_element_element — le même que celui de Prestasync.
        if ($orderId > 0) {
            $invoice->origin      = 'commande';
            $invoice->origin_type = 'commande';
            $invoice->origin_id   = $orderId;
            $invoice->linked_objects['commande'] = $orderId;
        }

        if ($invoice->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($invoice));
        }

        if ($orderId > 0) {
            $this->linkedToOrder++;
        }
        if ($isCreditNote) {
            $this->creditNotes++;
        }

        $this->addLines($invoice, $piece, $isCreditNote);

        if ($invoice->validate($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($invoice));
        }

        // Une facture annulée à la source est classée « abandonnée » : Dolibarr n'efface pas une
        // facture validée, il la sort du circuit en conservant son numéro.
        if (trim((string) $row->Z_Annule) === self::CANCELLED) {
            $invoice->setCanceled($this->user);
            $this->abandoned++;
        } else {
            $this->addPayments($invoice, $piece);
        }

        return array('action' => 'created', 'id' => (int) $invoice->id);
    }

    /**
     * Marque une facture existante comme reprise, sans y toucher par ailleurs.
     *
     * Seul `ref_ext` est écrit, par une mise à jour directe : passer par Facture::update()
     * refuserait la modification d'une facture validée, et rien d'autre n'a à changer. C'est une
     * exception assumée à la règle du module, documentée ici parce qu'elle se justifie : le champ
     * n'appartient à aucune logique métier, il ne sert qu'à l'idempotence de cette reprise.
     *
     * @param  int    $invoiceId Facture cible
     * @param  string $piece     Numéro de la facture source
     * @param  int    $orderId   Commande d'origine
     * @return array{action:string,id:int}
     * @throws Exception
     */
    protected function adoptInvoice($invoiceId, $piece, $orderId)
    {
        $sql  = 'UPDATE '.MAIN_DB_PREFIX.'facture';
        $sql .= " SET ref_ext = '".$this->db->escape($this->buildRefExt($piece))."'";
        $sql .= ' WHERE rowid = '.((int) $invoiceId);

        if (!$this->db->query($sql)) {
            throw new Exception('Adoption refusée : '.$this->db->lasterror());
        }

        // La facture n'est adoptée qu'une fois : deux factures source ne peuvent pas revendiquer
        // la même facture cible, sans quoi la seconde écraserait le marqueur de la première.
        unset($this->invoiceByOrder[$orderId]);
        $this->adopted++;

        return array('action' => 'updated', 'id' => (int) $invoiceId);
    }

    /**
     * Ajoute les lignes d'un document à la facture.
     *
     * Le recalcul des totaux est reporté à la fin : addline() le déclenche à chaque appel, ce qui
     * ferait recalculer vingt fois une facture de vingt lignes.
     *
     * @param  Facture $invoice      Facture cible
     * @param  string  $piece        Numéro du document source
     * @param  bool    $isCreditNote L'avoir inverse le signe des quantités
     * @return void
     * @throws Exception
     */
    protected function addLines($invoice, $piece, $isCreditNote)
    {
        if (empty($this->linesByDocument[$piece])) {
            return;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $map = $this->mapLine($line, $isCreditNote);

            // Signature longue, mais il n'y a pas d'autre voie : le drapeau qui suspend le
            // recalcul des totaux est le trente-et-unième paramètre (facture.class.php:4218), et
            // PHP n'a pas d'arguments nommés avant la version 8. Les valeurs intermédiaires sont
            // celles du coeur, reprises telles quelles.
            $result = $invoice->addline(
                $map['desc'],       // desc
                $map['price'],      // pu_ht
                $map['qty'],        // qty
                $map['vat'],        // txtva
                0,                  // txlocaltax1
                0,                  // txlocaltax2
                $map['productId'],  // fk_product
                $map['discount'],   // remise_percent
                '',                 // date_start
                '',                 // date_end
                0,                  // fk_code_ventilation
                0,                  // info_bits
                0,                  // fk_remise_except
                'HT',               // price_base_type
                0,                  // pu_ttc
                0,                  // type : ligne de produit
                -1,                 // rang : à la suite
                0,                  // special_code
                '',                 // origin
                0,                  // origin_id
                0,                  // fk_parent_line
                null,               // fk_fournprice
                0,                  // pa_ht
                '',                 // label
                array(),            // array_options
                100,                // situation_percent
                0,                  // fk_prev_id
                null,               // fk_unit
                0,                  // pu_ht_devise
                '',                 // ref_ext
                1                   // noupdateafterinsertline : recalcul reporté après la boucle
            );

            if ($result < 0) {
                throw new Exception('Ligne refusée : '.$this->objectErrors($invoice));
            }
        }

        $invoice->update_price(1);
    }

    /**
     * Traduit une ligne source en arguments d'addline().
     *
     * @param  stdClass $line         Ligne source
     * @param  bool     $isCreditNote Avoir : les quantités sont ramenées en positif
     * @return array<string,mixed>
     */
    protected function mapLine($line, $isCreditNote)
    {
        $sourceRef  = trim((string) $line->AR_Ref);
        $productKey = $this->indexKey($sourceRef);
        $productId  = 0;

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

        // Avoir : le signe de la quantité est INVERSÉ, il n'est pas ramené en positif.
        //
        // Dolibarr inverse lui-même le prix unitaire des lignes d'un avoir, si bien qu'une
        // quantité positive y produit un montant négatif. Multiplier par -1 rend donc à chaque
        // ligne le montant qu'elle avait à la source, y compris négatif.
        //
        // abs() semblait équivalent et ne l'est pas : une facture d'avoir contient des lignes de
        // reprise, de quantité négative, qui viennent en déduction du remboursement. Les ramener
        // en positif les fait s'ajouter au lieu de se retrancher — mesuré sur AF990026466, dont
        // les quatre lignes s'annulent à la source et totalisaient -89,82 € en cible.
        $qty = (float) $line->DL_Qte;
        if ($isCreditNote) {
            $qty = -$qty;
        }

        $vat = $line->DL_Taxe1;
        if ($vat === null) {
            $vat = 0;
        }

        $price    = (float) $line->DL_PrixUnitaire;
        $discount = (float) $line->DL_Remise01REM_Valeur;

        // ------------------------------------------------------------------------------
        // DL_Remise01REM_Type NE DISTINGUE PAS POURCENTAGE ET MONTANT
        // ------------------------------------------------------------------------------
        //
        // La première version de ce script tenait le type 1 pour une remise en montant et
        // l'abandonnait — hypothèse héritée de `MigrationSupplierOrder`, où elle ne portait
        // que sur seize lignes. Elle est fausse, et sur les factures elle coûtait cher :
        // 7 927 lignes perdaient leur remise, la facture étant écrite au prix plein.
        //
        // La source tranche d'elle-même, en confrontant `DL_MontantHT` aux deux formules
        // possibles sur les 7 925 lignes de type 1 où le calcul est vérifiable :
        //
        //     interprétée en POURCENTAGE ... 7 851 lignes conformes
        //     interprétée en MONTANT ....... 6 lignes conformes
        //
        // La facture F010000781 est exemplaire : prix 790,00, remise 5,063291 de type 1, et
        // `DL_MontantHT` vaut 750,00 — soit exactement 790 × (1 − 5,063291 / 100).
        //
        // Les 74 lignes qui ne collent à aucune des deux formules portent des remises de 5 %
        // et 50 % : des pourcentages ordinaires dont `DL_MontantHT` n'a pas été recalculé
        // après coup. Rien qui contredise la lecture.
        //
        // La remise est donc reportée telle quelle, quel que soit son type. Le cas négatif,
        // traité juste en dessous, reste le seul à demander un traitement particulier.

        if ($discount < 0) {
            // Remise négative : la source s'en sert pour MAJORER une ligne — 169 lignes, jusqu'à
            // -85 446 %. Dolibarr ne connaît pas la remise négative ; le coefficient est donc
            // reporté sur le prix unitaire, ce qui donne le même montant de ligne. L'abandonner
            // faisait perdre jusqu'à 76 € sur une seule facture.
            $price   *= (1 - $discount / 100);
            $discount = 0;
            $this->markupLines++;
        }

        return array(
            'desc'      => $desc,
            'price'     => $price,
            'qty'       => $qty,
            'vat'       => (float) $vat,
            'productId' => $productId,
            'discount'  => $discount,
        );
    }

    /**
     * Crée les règlements d'une facture.
     *
     * Deux gisements sont interrogés : les règlements portés par la facture elle-même, et ceux
     * portés par sa commande d'origine — la vente en ligne encaisse avant de facturer. Les
     * seconds ne sont repris que si la facture n'en a aucun, faute de quoi un paiement compterait
     * deux fois.
     *
     * @param  Facture $invoice Facture validée
     * @param  string  $piece   Numéro du document source
     * @return void
     */
    protected function addPayments($invoice, $piece)
    {
        $rows = isset($this->paymentsByDocument[$piece]) ? $this->paymentsByDocument[$piece] : array();
        $fromOrder = false;

        if (empty($rows) && isset($this->orderOfInvoice[$piece])) {
            $orderPiece = $this->orderOfInvoice[$piece];
            if (isset($this->paymentsByDocument[$orderPiece])) {
                $rows      = $this->paymentsByDocument[$orderPiece];
                $fromOrder = true;
                // Consommé : une commande peut porter plusieurs factures, son règlement n'en solde
                // qu'une. Sans cela, l'encaissement serait recopié sur chacune d'elles.
                unset($this->paymentsByDocument[$orderPiece]);
            }
        }

        foreach ($rows as $row) {
            // Montant arrondi au centime avant d'être jugé nul : la source stocke six décimales et
            // deux règlements valent 0,00001 €. Ils ne sont pas nuls au sens strict, mais le
            // deviennent une fois écrits — et Dolibarr refuse alors le paiement (TotalAmountEmpty),
            // ce qui faisait échouer la facture entière pour un cent-millième d'euro.
            $amount = round((float) $row->DR_Montant, 2);
            if ($amount == 0.0) {
                $this->paymentsZero++;
                continue;
            }

            $modeIndex = (int) $row->N_Reglement;

            // Règlement par avoir (indices 5 et 9 de la source) : ce n'est pas un encaissement
            // mais une compensation entre deux pièces. Dolibarr la traite par l'application d'un
            // avoir sur la facture, jamais par un paiement — en créer un gonflerait les
            // encaissements de 280 lignes qui n'ont jamais vu d'argent.
            if (in_array($modeIndex, self::CREDIT_MODES, true)) {
                $this->paymentsCreditNote++;
                continue;
            }

            $modeId = isset($this->paymentModes[$modeIndex]) ? (int) $this->paymentModes[$modeIndex] : 0;
            if ($modeId <= 0) {
                $modeId = $this->modeFromLabel($row->DR_Libelle);
                if ($modeId > 0) {
                    $this->paymentsByLabel++;
                }
            }
            if ($modeId <= 0) {
                $modeId = $this->fallbackMode;
            }
            if ($modeId <= 0) {
                $this->paymentsUnknownMode++;
                $this->unknownModes[$modeIndex] = true;
                continue;
            }

            $payment = new Paiement($this->db);
            $payment->datepaye     = $this->db->jdate($row->DR_Date);
            $payment->amounts      = array($invoice->id => abs($amount));
            $payment->paiementid   = $modeId;
            $payment->num_payment  = trim((string) $row->DR_Libelle);
            $payment->note_private = 'Règlement repris de l\'ancien ERP — '.$piece;

            if ($payment->create($this->user, 1) <= 0) {
                // Un règlement refusé n'interrompt pas la facture : elle est juste, seul son
                // encaissement manque, et le rapport le dit.
                $this->addError($piece, 'Règlement refusé : '.$this->objectErrors($payment));
                continue;
            }

            if ($this->bankAccountId > 0) {
                $payment->addPaymentToBank($this->user, 'payment', '(CustomerInvoicePayment)', $this->bankAccountId, '', '');
            }

            $this->paymentsCreated++;
            if ($fromOrder) {
                $this->paymentsFromOrder++;
            }
        }
    }

    /**
     * Contrôle un document en simulation, sans rien écrire.
     *
     * @param  stdClass $row Ligne source
     * @return void
     */
    protected function validateRow($row)
    {
        $piece = trim((string) $row->DO_Piece);

        $orderId = $this->orderIdOf($piece);
        if ($orderId > 0 && isset($this->invoiceByOrder[$orderId])) {
            $this->previewDecision[$piece] = 'adopt';
            $this->adopted++;
            unset($this->invoiceByOrder[$orderId]);
            return;
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            $this->previewDecision[$piece] = 'skip';
            return;
        }

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyDocuments++;
            $this->previewDecision[$piece] = 'skip';
            return;
        }

        if ($orderId > 0) {
            $this->linkedToOrder++;
        }
        if ($this->isCreditNote($piece)) {
            $this->creditNotes++;
        }
        if (trim((string) $row->Z_Annule) === self::CANCELLED) {
            $this->abandoned++;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $this->mapLine($line, false);
        }

        $this->previewDecision[$piece] = 'create';
    }

    /**
     * Action annoncée en simulation pour un document.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Identifiant déjà repris
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'skipped';
        }
        $piece    = trim((string) $row->DO_Piece);
        $decision = isset($this->previewDecision[$piece]) ? $this->previewDecision[$piece] : 'create';

        if ($decision === 'adopt') {
            return 'updated';
        }

        return ($decision === 'skip') ? 'skipped' : 'created';
    }

    /**
     * Lignes de rapport propres à cette reprise.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $report = parent::getReport();

        if ($this->adopted > 0) {
            $report[] = $this->adopted.' facture(s) déjà créée(s) par la boutique : marquée(s), non recréée(s)';
        }
        if ($this->linkedToOrder > 0) {
            $report[] = $this->linkedToOrder.' facture(s) rattachée(s) à leur commande';
        }
        if ($this->creditNotes > 0) {
            $report[] = $this->creditNotes.' avoir(s) (total négatif à la source)';
        }
        if ($this->abandoned > 0) {
            $report[] = $this->abandoned.' facture(s) annulée(s) à la source, classée(s) abandonnée(s)';
        }
        if ($this->emptyDocuments > 0) {
            $report[] = $this->emptyDocuments.' document(s) sans aucune ligne : écarté(s)';
        }
        if ($this->missingCustomers > 0) {
            $report[] = $this->missingCustomers.' facture(s) sans client repris : écartée(s) — '
                .$this->sample(array_keys($this->missingCustomerCodes));
        }
        if ($this->missingProductLines > 0) {
            $report[] = $this->missingProductLines.' ligne(s) dont l\'article est introuvable : reprise(s) en texte libre — '
                .$this->sample(array_keys($this->missingProducts));
        }
        if ($this->markupLines > 0) {
            $report[] = $this->markupLines.' ligne(s) majorée(s) par une remise négative :'
                .' le coefficient est reporté sur le prix unitaire';
        }
        if ($this->freeTextLines > 0) {
            $report[] = $this->freeTextLines.' ligne(s) de texte libre (aucun article à la source)';
        }
        if ($this->paymentsCreated > 0) {
            $report[] = $this->paymentsCreated.' règlement(s) créé(s), dont '.$this->paymentsFromOrder.' repris depuis la commande';
        }
        if ($this->paymentsByLabel > 0) {
            $report[] = $this->paymentsByLabel.' règlement(s) sans indice de mode : mode lu dans le libellé';
        }
        if ($this->paymentsCreditNote > 0) {
            $report[] = $this->paymentsCreditNote.' règlement(s) par avoir : compensation entre pièces,'
                .' non repris comme encaissement';
        }
        if ($this->paymentsUnknownMode > 0) {
            $report[] = $this->paymentsUnknownMode.' règlement(s) écarté(s) : mode absent du dictionnaire'
                .' source (indice '.implode(', ', array_keys($this->unknownModes)).')'
                .' — AEROMIG_PAYMENT_FALLBACK_CODE désigne le mode de repli';
        }
        if ($this->paymentsZero > 0) {
            $report[] = $this->paymentsZero.' règlement(s) écarté(s) : montant nul';
        }
        if ($this->bankAccountId <= 0) {
            $report[] = 'Aucun compte bancaire configuré (AEROMIG_INVOICE_BANK_ACCOUNT) :'
                .' les règlements sont créés sans écriture bancaire';
        }

        return $report;
    }

    /**
     * Description de la purge, affichée avant confirmation.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des factures reprises et de leurs règlements ; les factures de la'
            .' boutique sont conservées, seul leur marqueur est retiré (ref_ext « '
            .$this->refExtPrefix.' »)';
    }

    /**
     * Défait la reprise.
     *
     * Trois précautions, dans cet ordre :
     *
     * 1. **Les règlements d'abord.** Dolibarr refuse de supprimer une facture qui porte un
     *    paiement, et le paiement survivrait à sa facture — une écriture bancaire sans pièce.
     * 2. **Les factures de la boutique sont épargnées.** Elles appartiennent à Prestasync, qui
     *    continue de les tenir à jour ; seul le marqueur de reprise est retiré. Elles se
     *    reconnaissent à leur note privée, que seule la reprise écrit.
     * 3. **Le brouillon avant la suppression.** Une facture validée ne se supprime pas, et une
     *    facture abandonnée non plus : elle est ramenée au brouillon d'abord.
     *
     * @param  bool          $confirm  false pour dénombrer sans rien supprimer
     * @param  callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        // De la plus récente vers la plus ancienne, et ce n'est pas un détail de présentation :
        // Dolibarr refuse de supprimer une facture qui n'est pas la dernière de sa séquence
        // (is_erasable() renvoie -2, commoninvoice.class.php:871), pour ne pas trouer la
        // numérotation. En descendant, chaque facture est la dernière au moment où on la retire.
        $sql  = 'SELECT rowid, ref, note_private FROM '.MAIN_DB_PREFIX.'facture';
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
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
            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                continue;
            }

            // Facture de la boutique : le marqueur est retiré, rien n'est supprimé.
            //
            // Compté à part : additionner les démarquages aux suppressions dans un total
            // unique laisse croire que la purge s'apprête à effacer des factures Prestasync.
            if (strpos((string) $row->note_private, 'ancien ERP') === false) {
                $invoice->setValueFrom('ref_ext', '', '', null, 'text', '', $this->user);
                $result['deleted']++;
                $result['unmarked'] = (isset($result['unmarked']) ? $result['unmarked'] : 0) + 1;
                continue;
            }

            // Une facture close n'abandonne pas ses règlements : Dolibarr répond
            // « ErrorDeletePaymentLinkedToAClosedInvoiceNotPossible ». Elle est donc rouverte
            // avant qu'on y touche, et non après.
            if ((int) $invoice->statut === Facture::STATUS_CLOSED || !empty($invoice->paye)) {
                $invoice->setUnpaid($this->user);
                $invoice->fetch((int) $row->rowid);
            }

            if ($this->deletePayments($invoice, $result) < 0) {
                $result['failed']++;
                continue;
            }

            // Le brouillon est refusé tant que la facture porte encore un règlement : c'est
            // pourquoi il vient après, et non avant.
            if ((int) $invoice->statut !== Facture::STATUS_DRAFT) {
                if ($invoice->setDraft($this->user) < 0) {
                    $result['failed']++;
                    $result['errors'][] = 'Facture '.$row->ref.' : retour au brouillon refusé — '
                        .$this->objectErrors($invoice);
                    continue;
                }
                $invoice->fetch((int) $row->rowid);
            }

            if ($invoice->delete($this->user) > 0) {
                $result['deleted']++;
            } else {
                $result['failed']++;
                $result['errors'][] = 'Facture '.$row->ref.' : '.$this->objectErrors($invoice);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 500 === 0)) {
                call_user_func($progress, $result['deleted'] + $result['failed'], $result['count']);
            }
        }

        return $result;
    }

    /**
     * Supprime les règlements d'une facture avant sa suppression.
     *
     * Un règlement peut solder plusieurs factures : seuls ceux qui ne touchent que celle-ci sont
     * supprimés. Les autres sont laissés en place et signalés — les détruire retirerait un
     * encaissement à des factures qui ne sont pas du périmètre.
     *
     * @param  Facture $invoice Facture en cours de suppression
     * @param  array   $result  Compte rendu, enrichi des anomalies
     * @return int              0 si OK, -1 si un règlement a résisté
     */
    protected function deletePayments($invoice, array &$result)
    {
        $sql  = 'SELECT pf.fk_paiement, COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'paiement_facture as pf';
        $sql .= ' WHERE pf.fk_paiement IN (SELECT fk_paiement FROM '.MAIN_DB_PREFIX.'paiement_facture';
        $sql .= ' WHERE fk_facture = '.((int) $invoice->id).')';
        $sql .= ' GROUP BY pf.fk_paiement';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return -1;
        }

        $payments = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $payments[(int) $obj->fk_paiement] = (int) $obj->nb;
        }
        $this->db->free($resql);

        foreach ($payments as $paymentId => $nbInvoices) {
            if ($nbInvoices > 1) {
                $result['errors'][] = 'Facture '.$invoice->ref.' : règlement '.$paymentId
                    .' partagé avec une autre facture, conservé';
                return -1;
            }

            $payment = new Paiement($this->db);
            if ($payment->fetch($paymentId) <= 0) {
                continue;
            }
            if ($payment->delete($this->user) <= 0) {
                $result['errors'][] = 'Facture '.$invoice->ref.' : '.$this->objectErrors($payment);
                return -1;
            }
        }

        return 0;
    }

    /**
     * Quelques valeurs citées au rapport, pour orienter la recherche sans le noyer.
     *
     * @param  array<int,string> $values Valeurs
     * @return string
     */
    protected function sample(array $values)
    {
        $shown = array_slice($values, 0, self::SAMPLES);
        $out   = implode(', ', $shown);

        if (count($values) > self::SAMPLES) {
            $out .= ', … ('.count($values).' au total)';
        }

        return $out;
    }
}
