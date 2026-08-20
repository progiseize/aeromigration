<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationproposal.class.php
 * \ingroup aeromigration
 * \brief   Reprise des devis clients : f_docentete_global (type 0) -> Propal.
 *
 * PRÉREQUIS : les tiers et les articles doivent avoir été repris — chacun se retrouve par
 * son `ref_ext`. Aucune dépendance aux commandes : la source ne conserve aucun lien
 * devis→commande exploitable (2 correspondances sur 667 via `DO_Ref`), un devis transformé
 * quittant la table à la transformation.
 *
 * ## Le périmètre, arrêté avec le client le 20/08/2026
 *
 * La grille des statuts vient du client lui-même (l'écran ADD affiche « Terminée » là où le
 * dictionnaire `z_statut_document` de l'export disait encore « Devis refusé » — le libellé a
 * été renommé depuis) :
 *
 *     0  brouillon                        → ÉCARTÉ (un seul vrai devis de 2020, et les
 *                                           5 ordres de réparation OR du carnet atelier)
 *     1  envoyé, sans réponse             → propale VALIDÉE (ouverte)
 *     2  refusé                           → propale NON SIGNÉE
 *     9  terminée = transformée en cmde   → propale SIGNÉE, date de signature = date pièce
 *
 * S'écartent aussi : les **146 annulés** (`Z_Annule = 'A'`) — « aucun intérêt », décision du
 * 20/08/2026, même règle que préparations et réceptions — et les **72 devis sans aucune
 * ligne**.
 *
 * ## Référence : définitive dès la création
 *
 * Même règle que factures et expéditions (validée par le client le 20/08/2026) : avant le
 * 01/10/2023, `DE<millésime>-<chiffres de la pièce>` ; depuis, séquence `000001` par exercice
 * fiscal (oct→sept), chronologique, compteur six chiffres. Aucune propale n'existe en cible
 * (deux essais, contrôlés par la passe anti-collision) : la référence est posée AVANT
 * `valid()`, qui la conserve dès lors qu'elle ne commence pas par `(PROV` — même mécanique
 * que `Commande` (propal.class.php:2113). L'attribution ne dépend que de la source : stable
 * de passage en passage.
 *
 * ## Deux constantes neutralisées, en mémoire du seul processus
 *
 * - `MAIN_DISABLE_PDF_AUTOUPDATE = 1` : sans elle, `closeProposal()` GÉNÈRE le PDF de chaque
 *   devis clos (propal.class.php:2709) — 680 documents fabriqués en pleine reprise, à
 *   rebours de la règle « les PDF se génèrent à la demande » ;
 * - `PROPALE_NOCHECK_ONSALE_PRODUCTS_ONVALID = 1` : `valid()` refuse sinon tout devis
 *   portant un article retiré de la vente (propal.class.php:2099) — justifié à la saisie,
 *   absurde sur l'historique, même parade que pour les commandes.
 *
 * `closeProposal()` datant la signature du jour du passage, la date de la pièce est
 * réécrite ensuite par `setValueFrom()` : un devis accepté en 2021 reste signé en 2021.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

class MigrationProposal extends AeroMigrationRunner
{
    /** Domaine vente de la source. */
    const SRC_DOMAIN = 0;

    /** Type « devis » dans le domaine vente. */
    const SRC_TYPE = 0;

    /** Marqueur d'annulation de la source. */
    const CANCELLED = 'A';

    /** Préfixe des références cibles. */
    const REF_PREFIX = 'DE';

    /** Premier jour du premier exercice numéroté en séquence. */
    const CUTOFF = '2023-10-01';

    /** Longueur du compteur des références en séquence. */
    const COUNTER_LENGTH = 6;

    /** Nombre d'exemples conservés par anomalie. */
    const SAMPLES = 8;

    /** @var string Identifiant du script en ligne de commande */
    public $code = 'proposal';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptProposal';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues */
    protected $srcFields = 'DO_Piece, DO_Date, DO_DateLivr, DO_Tiers, DO_Ref, DO_Statut, Z_Annule';

    /** @var string Colonne de parcours : la clé primaire du document */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du document */
    protected $srcKeyField = 'DO_Piece';

    /**
     * Devis du domaine vente, hors annulés et hors brouillons.
     *
     * Les statuts admis sont ceux de la grille client : 1 (envoyé), 2 (refusé), 9 (terminé).
     * Le statut 0 — un brouillon de 2020 et les 5 ordres de réparation OR — est écarté à la
     * lecture, comme les 146 annulés.
     *
     * @var string
     */
    protected $srcWhere = "DO_Domaine = 0 AND DO_Type = 0 AND TRIM(DO_Piece) <> ''"
        ." AND COALESCE(Z_Annule, '') <> 'A' AND COALESCE(DO_Statut, 0) IN (1, 2, 9)";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'propal';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'propal';

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,int> Tiers repris : CT_Num en minuscules -> rowid */
    protected $customerBySage = array();

    /** @var array<string,int> Articles repris : AR_Ref en minuscules -> rowid */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> Lignes source, par numéro de document */
    protected $linesByDocument = array();

    /** @var array<string,string> Document -> référence cible définitive */
    protected $refByPiece = array();

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Devis laissés ouverts (statut 1, envoyés sans réponse) */
    protected $openProposals = 0;

    /** @var int Devis clos non signés (statut 2, refusés) */
    protected $refusedProposals = 0;

    /** @var int Devis clos signés (statut 9, transformés en commande) */
    protected $signedProposals = 0;

    /** @var int Lignes écrites */
    protected $linesWritten = 0;

    /** @var int Lignes de texte libre */
    protected $freeTextLines = 0;

    /** @var int Lignes dont l'article n'est pas repris, conservées en texte libre */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'articles introuvables */
    protected $missingProducts = array();

    /** @var int Lignes portant une remise */
    protected $discountLines = 0;

    /** @var int Lignes sans prix unitaire dans la source */
    protected $noPriceLines = 0;

    /** @var int Lignes sans taux de TVA */
    protected $noVatLines = 0;

    /** @var int Lignes à quantité négative */
    protected $negativeQtyLines = 0;

    /** @var int Documents sans aucune ligne, écartés */
    protected $emptyDocuments = 0;

    /** @var array<int,string> Exemples de documents sans ligne */
    protected $emptySamples = array();

    /** @var int Documents dont le tiers n'est pas repris */
    protected $missingCustomers = 0;

    /** @var array<string,bool> Codes tiers introuvables */
    protected $missingCustomerCodes = array();

    /** @var array<string,int> Documents écartés à la lecture, par motif */
    protected $discarded = array();

    /**
     * Charge les index et calcule les références cibles.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        $this->neutralizeSideEffects();

        foreach (array(
            'loadCustomerIndex',
            'loadProductIndex',
            'loadDocumentLines',
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
     * Neutralise, pour ce seul processus, ce que le coeur ferait de trop.
     *
     * Tout se joue dans `$conf->global`, jamais en base : l'application garde son
     * comportement pour les utilisateurs.
     *
     * @return void
     */
    protected function neutralizeSideEffects()
    {
        global $conf;

        if (!isset($conf->global)) {
            return;
        }

        // closeProposal() génère sinon le PDF de chaque devis clos (propal.class.php:2709).
        // Les documents se génèrent à la demande, jamais par la reprise.
        $conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;

        // valid() refuse sinon tout devis portant un article retiré de la vente
        // (propal.class.php:2099) — l'historique en est plein, et c'est son droit.
        $conf->global->PROPALE_NOCHECK_ONSALE_PRODUCTS_ONVALID = 1;
    }

    /**
     * Normalise une référence source pour la recherche en index.
     *
     * @param string $ref Référence source
     * @return string     Clé d'index
     */
    protected function indexKey($ref)
    {
        return strtolower(trim((string) $ref));
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
     * Précharge les lignes de tous les devis, en une lecture.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_Taxe1,';
        $sql .= ' DL_Remise01REM_Valeur, DL_Ligne';
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
     * Attribue à chaque devis sa référence définitive.
     *
     * Même mécanique que les expéditions : calcul depuis la seule source (périmètre, dates,
     * numéros), donc identique à chaque passage ; chiffres de la pièce avant la coupure,
     * séquence chronologique par exercice après ; contrôle d'unicité interne puis de
     * collision avec la cible.
     *
     * @return int 1 si OK, -1 si un contrôle échoue
     */
    protected function computeTargetRefs()
    {
        $sql  = 'SELECT DO_Piece, DO_Date FROM '.$this->src($this->srcTable);
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

            $date      = (string) $obj->DO_Date;
            $millesime = $this->fiscalMillesime($date);

            if (substr($date, 0, 10) < self::CUTOFF) {
                $ref = self::REF_PREFIX.$millesime.'-'.preg_replace('/\D+/', '', $piece);
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

        $sql   = 'SELECT ref, ref_ext FROM '.MAIN_DB_PREFIX.'propal';
        $sql  .= ' WHERE entity IN ('.getEntity('propal').')';
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
            if ((string) $obj->ref_ext !== $this->buildRefExt($seen[$ref])) {
                $this->db->free($resql);
                $this->errors[] = array(
                    'key'     => $seen[$ref],
                    'message' => 'La référence '.$ref.' est déjà portée par une proposition'
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
            'devis annulé(s) dans l\'ancien ERP (décision client du 20/08/2026)' =>
                $base." AND COALESCE(Z_Annule, '') = 'A'",
            'brouillon(s) — statut 0, dont les 5 ordres de réparation OR' =>
                $base." AND COALESCE(Z_Annule, '') <> 'A' AND COALESCE(DO_Statut, 0) NOT IN (1, 2, 9)",
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
     * Reprend un devis : création, lignes, référence, validation, statut.
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

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyDocuments++;
            if (count($this->emptySamples) < self::SAMPLES) {
                $this->emptySamples[] = $piece;
            }
            return array('action' => 'skipped', 'id' => 0);
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            return array('action' => 'skipped', 'id' => 0);
        }

        if (!isset($this->refByPiece[$piece])) {
            throw new Exception('Aucune référence attribuée — incohérence interne');
        }

        $proposal = new Propal($this->db);
        $proposal->socid        = (int) $this->customerBySage[$customerCode];
        $proposal->date         = $this->db->jdate($row->DO_Date);
        $proposal->ref_client   = trim((string) $row->DO_Ref);
        $proposal->ref_ext      = $this->buildRefExt($piece);
        $proposal->note_private = 'Devis repris de l\'ancien ERP — '.$piece;

        if (!empty($row->DO_DateLivr)) {
            $proposal->delivery_date = $this->db->jdate($row->DO_DateLivr);
        }

        if ($proposal->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($proposal));
        }

        $this->addLines($proposal, $piece);

        // La référence définitive, posée avant valid() qui la conserve dès lors qu'elle ne
        // commence pas par « (PROV » (propal.class.php:2113).
        $proposal->ref = $this->refByPiece[$piece];

        if ($proposal->valid($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($proposal));
        }

        $this->applyStatus($proposal, $row);

        return array('action' => 'created', 'id' => (int) $proposal->id);
    }

    /**
     * Ajoute les lignes du devis.
     *
     * Le recalcul des totaux est reporté à la fin, comme pour les commandes : `addline()`
     * le déclenche sinon à chaque appel.
     *
     * @param Propal $proposal Devis créé, encore au brouillon
     * @param string $piece    Numéro du document source
     * @return void
     * @throws Exception Si le coeur refuse une ligne
     */
    protected function addLines(Propal $proposal, $piece)
    {
        foreach ($this->linesByDocument[$piece] as $line) {
            $map = $this->mapLine($line);

            $result = $proposal->addline(
                $map['desc'],
                $map['price'],
                $map['qty'],
                $map['vat'],
                0,
                0,
                $map['productId'],
                $map['discount'],
                'HT',
                0,
                0,
                0,
                -1,
                0,
                0,
                0,
                0,
                '',
                '',
                '',
                array(),
                null,
                '',
                0,
                0,
                0,
                1
            );

            if ($result <= 0) {
                throw new Exception('Ligne refusée : '.$this->objectErrors($proposal));
            }

            $this->linesWritten++;
        }

        if ($proposal->update_price(1, 'auto') < 0) {
            throw new Exception('Totaux non calculés : '.$this->objectErrors($proposal));
        }
    }

    /**
     * Traduit une ligne source en arguments d'`addline()`.
     *
     * Mêmes règles que les commandes clients : la remise est TOUJOURS un taux quel que soit
     * son type (démonstration dans MigrationInvoice::mapLine()), une remise négative est une
     * majoration que Dolibarr ne représente pas, et la TVA absente vaut zéro.
     *
     * @param stdClass $line Ligne source
     * @return array<string,mixed>
     */
    protected function mapLine($line)
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

        $qty = (float) $line->DL_Qte;
        if ($qty < 0) {
            $this->negativeQtyLines++;
        }

        if ($line->DL_PrixUnitaire === null) {
            $this->noPriceLines++;
        }

        $vat = $line->DL_Taxe1;
        if ($vat === null) {
            $this->noVatLines++;
            $vat = 0;
        }

        $discount = (float) $line->DL_Remise01REM_Valeur;
        if ($discount != 0) {
            $this->discountLines++;
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
     * Applique le statut cible de la grille client.
     *
     * `closeProposal()` date la signature du jour du passage : la date de la pièce est
     * réécrite ensuite, pour qu'un devis accepté en 2021 reste signé en 2021.
     *
     * @param Propal   $proposal Devis validé
     * @param stdClass $row      Ligne source
     * @return void
     * @throws Exception Si le coeur refuse le classement
     */
    protected function applyStatus(Propal $proposal, $row)
    {
        $status = (int) $row->DO_Statut;

        if ($status === 2) {
            if ($proposal->closeProposal($this->user, Propal::STATUS_NOTSIGNED, 'Refusé dans l\'ancien ERP') <= 0) {
                throw new Exception('Classement « non signée » refusé : '.$this->objectErrors($proposal));
            }
            $this->refusedProposals++;
            return;
        }

        if ($status === 9) {
            if ($proposal->closeProposal($this->user, Propal::STATUS_SIGNED, 'Transformé en commande dans l\'ancien ERP') <= 0) {
                throw new Exception('Classement « signée » refusé : '.$this->objectErrors($proposal));
            }
            if ($proposal->setValueFrom('date_signature', $this->db->jdate($row->DO_Date), '', null, 'date', '', $this->user) < 0) {
                throw new Exception('Date de signature non posée : '.$this->objectErrors($proposal));
            }
            $this->signedProposals++;
            return;
        }

        $this->openProposals++;
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si le document ne permet pas de bâtir un devis valide
     */
    protected function validateRow($row)
    {
        $piece = trim((string) $row->DO_Piece);

        if (empty($this->linesByDocument[$piece])) {
            return; // Écarté et compté par previewAction().
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            return; // Écarté et compté par previewAction().
        }

        if (!isset($this->refByPiece[$piece])) {
            throw new Exception('Aucune référence attribuée — incohérence interne');
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
            if (count($this->emptySamples) < self::SAMPLES) {
                $this->emptySamples[] = $piece;
            }
            return 'skipped';
        }

        $customerCode = $this->indexKey($row->DO_Tiers);
        if (!isset($this->customerBySage[$customerCode])) {
            $this->missingCustomers++;
            $this->missingCustomerCodes[$customerCode] = true;
            return 'skipped';
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $this->mapLine($line);
            $this->linesWritten++;
        }

        $status = (int) $row->DO_Statut;
        if ($status === 2) {
            $this->refusedProposals++;
        } elseif ($status === 9) {
            $this->signedProposals++;
        } else {
            $this->openProposals++;
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
        return 'Suppression des devis repris (table '.MAIN_DB_PREFIX.'propal,'
            .' marqués « '.$this->refExtPrefix.' ») et de leurs lignes';
    }

    /**
     * Supprime les devis posés par ce script.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'propal';
        $sql .= ' WHERE entity IN ('.getEntity('propal').')';
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
            $proposal = new Propal($this->db);
            if ($proposal->fetch($rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : chargement impossible';
                continue;
            }

            if ($proposal->delete($this->user) <= 0) {
                $result['failed']++;
                $result['errors'][] = $proposal->ref.' : '.$this->objectErrors($proposal);
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
        if ($this->openProposals > 0) {
            $block[] = $this->countLine($this->openProposals, 'devis validé(s), envoyé(s) sans réponse (statut 1)');
        }
        if ($this->refusedProposals > 0) {
            $block[] = $this->countLine($this->refusedProposals, 'devis clos non signé(s), refusé(s) (statut 2)');
        }
        if ($this->signedProposals > 0) {
            $block[] = $this->countLine($this->signedProposals, 'devis clos signé(s), transformé(s) en commande'
                .' (statut 9) — date de signature = date de la pièce');
        }
        $this->appendBlock($lines, 'Statuts', $block);

        $block = array();
        if ($this->linesWritten > 0) {
            $block[] = $this->countLine($this->linesWritten, 'ligne(s) écrite(s)');
        }
        if ($this->freeTextLines > 0) {
            $block[] = $this->countLine($this->freeTextLines, 'ligne(s) de texte libre');
        }
        if ($this->discountLines > 0) {
            $block[] = $this->countLine($this->discountLines, 'ligne(s) avec remise');
        }
        if ($this->negativeQtyLines > 0) {
            $block[] = $this->countLine($this->negativeQtyLines, 'ligne(s) à quantité négative');
        }
        $this->appendBlock($lines, 'Lignes', $block);

        $block = array();
        if ($this->missingProductLines > 0) {
            $block[] = $this->countLine($this->missingProductLines, 'ligne(s) dont l\'article n\'est pas repris ('
                .count($this->missingProducts).' référence(s)) : conservée(s) en texte libre');
        }
        if ($this->noPriceLines > 0) {
            $block[] = $this->countLine($this->noPriceLines, 'ligne(s) sans prix unitaire dans la source,'
                .' reprise(s) à zéro');
        }
        if ($this->noVatLines > 0) {
            $block[] = $this->countLine($this->noVatLines, 'ligne(s) sans taux de TVA, reprise(s) à zéro');
        }
        if ($this->missingCustomers > 0) {
            $block[] = $this->countLine($this->missingCustomers, 'devis écarté(s), tiers non repris : '
                .implode(', ', array_slice(array_keys($this->missingCustomerCodes), 0, self::SAMPLES)));
        }
        $this->appendBlock($lines, 'Anomalies', $block);

        $block = array();
        if ($this->emptyDocuments > 0) {
            $block[] = $this->countLine($this->emptyDocuments, 'devis sans aucune ligne'
                .(empty($this->emptySamples) ? '' : ' — '.implode(', ', $this->emptySamples)));
        }
        foreach ($this->discarded as $libelle => $nb) {
            $block[] = $this->countLine($nb, $libelle);
        }
        $this->appendBlock($lines, 'Écartés', $block);

        $lines[] = 'Aucun PDF généré : MAIN_DISABLE_PDF_AUTOUPDATE était posée en mémoire du seul'
            .' processus — les documents se génèrent à la demande, avec la référence définitive.';

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
