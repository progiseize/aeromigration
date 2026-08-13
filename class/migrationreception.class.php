<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationreception.class.php
 * \ingroup aeromigration
 * \brief   Reprise des réceptions fournisseur : f_docentete_global -> Reception.
 *
 * PRÉREQUIS : les tiers, les articles, les entrepôts et les commandes fournisseur doivent
 * avoir été repris. Chacun est retrouvé par son `ref_ext`, l'entrepôt par son `import_key`.
 *
 * Le domaine achat de la source ne numérote pas ses types comme le domaine vente : la
 * réception y est le **type 13**, la commande le 12 et la facture le 16. Ce script ne lit
 * que le 13, soit 3 143 documents et 25 143 lignes.
 *
 * **Les factures fournisseur ne sont volontairement pas reprises.** Le client ne les a jamais
 * gérées dans l'ancien ERP, ce que la source confirme : sur ses 2 139 factures d'achat,
 * `z_docregl_global` ne porte **qu'un seul règlement** — 478,19 €, et sur une commande, pas
 * sur une facture. `f_docregl` est vide, `DR_Regle` vaut 0 partout et `Z_Solde` vaut « N » sur
 * l'intégralité du gisement. Seul l'historique des réceptions a été demandé.
 *
 * ## Le stock n'est pas touché, et c'est vital
 *
 * `Reception::valid()` ne crée de mouvement que si `STOCK_CALCULATE_ON_RECEPTION` est posée
 * (reception.class.php:681). Elle ne l'est pas sur cette instance — vérifié avant d'écrire une
 * ligne. Sans cette garantie, les 25 143 lignes reprises **s'ajouteraient au stock d'ouverture
 * déjà en place** et doubleraient les quantités de 387 682 unités.
 *
 * Si la constante venait à être activée, ce script deviendrait destructeur. `prepare()` s'en
 * assure à chaque passage et refuse de démarrer plutôt que de le découvrir après coup.
 *
 * ## Deux façons d'écrire une ligne, et pourquoi les deux servent
 *
 * Dolibarr adosse structurellement une ligne de réception à une **ligne de commande** :
 * `Reception::addline()` prend un `fk_commandefourndet`, qu'il relit pour en déduire l'article
 * (reception.class.php:962). C'est la voie complète — elle porte l'entrepôt et le lien.
 *
 * Mais 470 réceptions sur 3 143 ne citent aucune commande, et 3 678 de leurs lignes n'ont donc
 * rien à quoi s'adosser. Elles passent par `addlinefree()`, qui accepte un article et une
 * quantité sans commande — au prix d'une limite à connaître : **cette méthode n'écrit ni
 * l'entrepôt ni le prix**. C'est sans grande conséquence ici, les 3 143 réceptions étant
 * toutes sur le dépôt 1, mais le rapport le dénombre.
 *
 * ## Ce qui est écarté
 *
 * **291 réceptions annulées** (`Z_Annule = 'A'`). L'objet Reception n'a que trois statuts —
 * brouillon, validée, close — et **aucun équivalent d'« abandonnée »**, contrairement aux
 * factures. Les faire figurer validées à l'historique laisserait croire à une entrée de
 * marchandise qui n'a pas eu lieu ; les laisser en brouillon les installerait à demeure dans
 * la liste des réceptions à traiter. Elles sont donc écartées et comptées.
 *
 * **Quatre documents sans aucune ligne.** `Reception::valid()` refuse un document vide, et
 * une réception sans ligne n'apprend rien.
 *
 * ## Idempotence : `ref_ext` existe, mais le coeur ne l'écrit jamais
 *
 * C'est le quatrième cas du module. `llx_reception.ref_ext` est déclarée, et `fetch()` sait
 * lire par elle (reception.class.php:517) — mais **aucune méthode ne l'écrit** : ni `create()`,
 * ni `update()`. `import_key` ne peut pas la remplacer, ses 14 caractères ne suffisant pas à
 * « SAGE:BLF990003143 ».
 *
 * La clé est donc posée par `CommonObject::setValueFrom()`, méthode générique du coeur, juste
 * après la création. Le mécanisme du socle fonctionne ensuite sans surcharge.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
// Reception::addline() instancie CommandeFournisseurDispatch (reception.class.php:967) sans
// jamais l'inclure : le coeur compte sur un require_once fait par l'écran appelant. En CLI,
// personne ne le fait, et l'appel meurt d'une erreur fatale — donc hors transaction, sans
// rollback. C'est le pendant de ce qu'on a déjà rencontré avec date.lib.php sous master.inc.php.
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.dispatch.class.php';

class MigrationReception extends AeroMigrationRunner
{
    /** Domaine achat de la source. */
    const SRC_DOMAIN = 1;

    /** Type « réception » dans le domaine achat. */
    const SRC_TYPE = 13;

    /** Marqueur d'annulation de la source. */
    const CANCELLED = 'A';

    /** Clé de reprise de l'entrepôt principal. */
    const MAIN_WAREHOUSE = 'DEPOT1';

    /** Nombre d'exemples conservés par anomalie. */
    const SAMPLES = 5;

    /** @var string Identifiant du script en ligne de commande */
    public $code = 'reception';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptReception';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_docentete_global';

    /** @var string Colonnes lues */
    protected $srcFields = 'DO_Piece, DO_Date, DO_DateLivr, DO_Tiers, DO_Ref, DO_RefExterne, DE_No, Z_Annule';

    /** @var string Colonne de parcours : la clé primaire du document */
    protected $srcCursorField = 'DO_Piece';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du document dans la source */
    protected $srcKeyField = 'DO_Piece';

    /**
     * Réceptions du domaine achat, hors annulations.
     *
     * Les 291 documents annulés sont exclus dès la lecture : Dolibarr n'a pas de statut pour
     * les représenter, et les compter suffit à en garder trace.
     *
     * @var string
     */
    protected $srcWhere = "DO_Domaine = 1 AND DO_Type = 13 AND TRIM(DO_Piece) <> ''"
        ." AND COALESCE(Z_Annule, '') <> 'A'";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'reception';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'reception';

    /** @var int Entrepôt de destination des lignes rattachées à une commande */
    protected $warehouseId = 0;

    /** @var array<string,int> Fournisseurs repris, par clé source */
    protected $supplierBySage = array();

    /** @var array<string,int> Articles repris, par clé source */
    protected $productBySage = array();

    /** @var array<string,array<int,stdClass>> Lignes source, par numéro de document */
    protected $linesByDocument = array();

    /** @var array<string,int> Commandes fournisseur reprises, par clé source */
    protected $orderBySage = array();

    /** @var array<int,array<int,int>> Ligne de commande, par commande puis article */
    protected $orderLineByProduct = array();

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Lignes écrites en s'adossant à une ligne de commande */
    protected $linkedLines = 0;

    /** @var int Lignes écrites en texte libre, faute de commande */
    protected $freeLines = 0;

    /** @var int Réceptions rattachées à une commande */
    protected $linkedToOrder = 0;

    /** @var int Réceptions sans commande d'origine */
    protected $withoutOrder = 0;

    /** @var int Documents dont le fournisseur n'est pas repris */
    protected $missingSuppliers = 0;

    /** @var int Documents sans aucune ligne */
    protected $emptyDocuments = 0;

    /** @var array<int,string> Exemples de documents sans ligne */
    protected $emptySamples = array();

    /** @var int Lignes dont l'article n'est pas repris */
    protected $missingProductLines = 0;

    /** @var array<string,bool> Références d'articles introuvables */
    protected $missingProducts = array();

    /** @var int Lignes sans référence d'article */
    protected $freeTextLines = 0;

    /** @var int Lignes de quantité nulle */
    protected $zeroQtyLines = 0;

    /** @var int Lignes de quantité négative */
    protected $negativeQtyLines = 0;

    /** @var int Lignes citant une commande absente de la cible */
    protected $unknownOrderLines = 0;

    /** @var array<string,int> Documents écartés à la lecture, par motif */
    protected $discarded = array();

    /**
     * Charge les index nécessaires à la reprise.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        foreach (array(
            'checkStockConfiguration',
            'loadWarehouse',
            'loadSupplierIndex',
            'loadProductIndex',
            'loadDocumentLines',
            'loadOrderIndex',
            'loadOrderLines',
            'countDiscarded',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Refuse de démarrer si valider une réception mouvementerait le stock.
     *
     * Le stock d'ouverture est déjà posé par le script `stock`. Si
     * `STOCK_CALCULATE_ON_RECEPTION` était active, chacune des 25 143 lignes reprises
     * ajouterait sa quantité par-dessus — 387 682 unités en trop, sans que rien ne le signale
     * avant l'inventaire suivant.
     *
     * ## Pourquoi `STOCK_CALCULATE_ON_RECEPTION_CLOSE` ne bloque pas, alors qu'elle vaut 1
     *
     * Elle est absente de `llx_const` : c'est **le coeur qui la force**. Quand le module
     * lots/séries est activé et que `STOCK_CALCULATE_ON_RECEPTION` est vide, `Conf::setValues()`
     * pose `STOCK_CALCULATE_ON_RECEPTION_CLOSE = 1` (conf.class.php:963). L'écrire à 0 en base
     * ne servirait à rien, la valeur étant réimposée à chaque chargement.
     *
     * Ce n'est pas gênant : cette constante ne déclenche le mouvement qu'à la **clôture** de la
     * réception, et ce script s'arrête à la validation. La distinction n'est pas théorique —
     * clôturer les 2 852 réceptions après coup, depuis l'écran, doublerait bel et bien le stock.
     *
     * @return int 1 si la configuration est sûre, -1 sinon
     */
    protected function checkStockConfiguration()
    {
        if (getDolGlobalInt('STOCK_CALCULATE_ON_RECEPTION')) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'STOCK_CALCULATE_ON_RECEPTION est active : valider les réceptions'
                    .' ajouterait leurs quantités au stock d\'ouverture déjà repris.'
                    .' Désactivez-la le temps de la reprise.',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Retrouve l'entrepôt principal, destination de toutes les réceptions.
     *
     * La source les porte toutes sur le dépôt 1 — vérifié : 3 143 sur 3 143.
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
     * Recense les fournisseurs repris.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadSupplierIndex()
    {
        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= ' WHERE entity IN ('.getEntity('societe').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->supplierBySage[$obj->ref_ext] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Recense les articles repris.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productBySage[$obj->ref_ext] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Précharge les lignes de tous les documents, en une lecture.
     *
     * 25 143 lignes pour 3 123 documents : les relire document par document multiplierait les
     * allers-retours sans rien apporter.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadDocumentLines()
    {
        $sql  = 'SELECT DO_Piece, AR_Ref, DL_Design, DL_Qte, DL_PrixUnitaire, DL_PieceBC,';
        $sql .= ' AF_RefFourniss, DL_Ligne';
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
     * Recense les commandes fournisseur reprises.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadOrderIndex()
    {
        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'commande_fournisseur';
        $sql .= ' WHERE entity IN ('.getEntity('supplier_order').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->orderBySage[$obj->ref_ext] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Associe à chaque couple (commande, article) une ligne de commande.
     *
     * `Reception::addline()` ne prend pas un article mais une **ligne de commande**, qu'elle
     * relit pour en déduire le reste. Il faut donc apparier, et l'appariement ne peut se faire
     * que sur l'article : la source ne conserve pas le numéro de la ligne d'origine.
     *
     * **448 couples portent plusieurs lignes** dans la même commande — un même article commandé
     * en deux fois, à deux prix. La première ligne l'emporte, par `rowid` croissant. Retenir
     * l'autre ne changerait ni l'article, ni la quantité reçue, ni l'entrepôt : seul le
     * rattachement visuel à une ligne de commande plutôt qu'à sa jumelle en dépendrait.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadOrderLines()
    {
        $sql  = 'SELECT cfd.rowid, cfd.fk_commande, cfd.fk_product';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'commande_fournisseurdet as cfd';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commande_fournisseur as cf ON cf.rowid = cfd.fk_commande';
        $sql .= ' WHERE cf.entity IN ('.getEntity('supplier_order').')';
        $sql .= "   AND cf.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= '   AND cfd.fk_product > 0';
        $sql .= ' ORDER BY cfd.rowid ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $order   = (int) $obj->fk_commande;
            $product = (int) $obj->fk_product;

            // Le tri par rowid a déjà désigné la bonne : les suivantes sont des jumelles.
            if (!isset($this->orderLineByProduct[$order][$product])) {
                $this->orderLineByProduct[$order][$product] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Dénombre les documents écartés, pour que le rapport le dise.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscarded()
    {
        $base = 'DO_Domaine = '.self::SRC_DOMAIN.' AND DO_Type = '.self::SRC_TYPE;

        $motifs = array(
            'réceptions annulées dans l\'ancien ERP' => $base." AND COALESCE(Z_Annule, '') = 'A'",
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
     * Commande d'origine d'une réception, quand elle en cite une seule.
     *
     * Les lignes portent le numéro de commande dans `DL_PieceBC`. Sur 2 673 réceptions
     * rattachées, **2 672 ne citent qu'une commande** : le cas multiple existe une fois et ne
     * justifie pas de compliquer le rattachement, qui reste alors vide.
     *
     * @param string $piece Numéro du document source
     * @return int          rowid de la commande, 0 si aucune ou plusieurs
     */
    protected function resolveOrder($piece)
    {
        if (empty($this->linesByDocument[$piece])) {
            return 0;
        }

        $cited = array();
        foreach ($this->linesByDocument[$piece] as $line) {
            $ref = trim((string) $line->DL_PieceBC);
            if ($ref !== '') {
                $cited[$ref] = true;
            }
        }

        if (count($cited) !== 1) {
            return 0;
        }

        $refExt = $this->buildRefExt(key($cited));

        return isset($this->orderBySage[$refExt]) ? $this->orderBySage[$refExt] : 0;
    }

    /**
     * Reprend une réception et ses lignes.
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
            // Rien à compléter : une réception validée ne se modifie plus.
            return array('action' => 'skipped', 'id' => $existingId);
        }

        if (empty($this->linesByDocument[$piece])) {
            $this->emptyDocuments++;
            if (count($this->emptySamples) < self::SAMPLES) {
                $this->emptySamples[] = $piece;
            }
            return array('action' => 'skipped', 'id' => 0);
        }

        $supplierKey = $this->buildRefExt(trim((string) $row->DO_Tiers));
        if (!isset($this->supplierBySage[$supplierKey])) {
            $this->missingSuppliers++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $orderId = $this->resolveOrder($piece);

        $reception                 = new Reception($this->db);
        $reception->socid          = $this->supplierBySage[$supplierKey];
        $reception->date_reception = $this->db->jdate($row->DO_Date);
        $reception->date_delivery  = $this->db->jdate($row->DO_DateLivr);
        $reception->ref_supplier   = $this->resolveSupplierRef($row, $piece);
        $reception->note_private   = 'Réception reprise de l\'ancien ERP — '.$piece;

        // Rattachement à la commande, posé avant create() qui enregistre alors le lien
        // d'origine dans llx_element_element.
        if ($orderId > 0) {
            $reception->origin      = 'order_supplier';
            $reception->origin_type = 'order_supplier';
            $reception->origin_id   = $orderId;
            $reception->linked_objects['order_supplier'] = $orderId;
        }

        // ── L'ordre des opérations est imposé par le coeur, et il est contre-intuitif ──
        //
        // `addline()` n'écrit rien : elle empile dans $this->lines, et c'est `create()` qui
        // insère la pile (reception.class.php:384-391). Elle doit donc être appelée AVANT.
        //
        // `addlinefree()` fait exactement l'inverse : elle insère immédiatement, a besoin de
        // $this->id et refuse de travailler hors du statut brouillon. Elle doit donc être
        // appelée APRÈS.
        //
        // Créer d'abord puis empiler, ce qui paraît naturel, produit des réceptions validées
        // sans la moindre ligne — sans erreur, ni à la création ni à la validation.
        $pending = $this->stackLines($reception, $piece, $orderId);

        if ($reception->create($this->user) <= 0) {
            throw new Exception('Création refusée : '.$this->objectErrors($reception));
        }

        // ref_ext n'est écrite par aucune méthode du coeur : setValueFrom() est la voie
        // générique, et reste une écriture par l'objet métier.
        if ($reception->setValueFrom('ref_ext', $this->buildRefExt($piece), '', null, 'text', '', $this->user) < 0) {
            throw new Exception('Marquage ref_ext refusé : '.$this->objectErrors($reception));
        }

        if ($orderId > 0) {
            $this->linkedToOrder++;
        } else {
            $this->withoutOrder++;
        }

        $this->addFreeLines($reception, $pending);

        // La validation attribue le numéro définitif et met à jour le statut de la commande
        // d'origine — le coeur recalcule alors ce qui a été reçu, à partir des réceptions
        // réelles plutôt que de la déduction faite par le script « supplierorder ».
        if ($reception->valid($this->user) <= 0) {
            throw new Exception('Validation refusée : '.$this->objectErrors($reception));
        }

        return array('action' => 'created', 'id' => (int) $reception->id);
    }

    /**
     * Référence du document chez le fournisseur.
     *
     * `DO_RefExterne` est renseignée sur 2 539 des 2 852 réceptions retenues, et c'est bien la
     * référence du fournisseur — mais sous forme de phrase : « Bon de Livraison BL00015072 du
     * 23/07/2026 ». Elle est reprise telle quelle plutôt qu'analysée : les formats sont
     * hétérogènes, en trois langues, et une extraction par motif produirait des références
     * fausses là où la phrase entière reste exacte et lisible.
     *
     * À défaut, le numéro du document source en tient lieu.
     *
     * @param stdClass $row   Ligne source
     * @param string   $piece Numéro du document source
     * @return string
     */
    protected function resolveSupplierRef($row, $piece)
    {
        $ref = trim((string) $row->DO_RefExterne);
        if ($ref === '') {
            $ref = trim((string) $row->DO_Ref);
        }

        return ($ref !== '') ? $ref : $piece;
    }

    /**
     * Empile les lignes adossées à une commande, et met les autres de côté.
     *
     * À appeler AVANT `create()` : `addline()` ne fait qu'empiler dans `$this->lines`, que
     * `create()` insère ensuite (reception.class.php:384-391).
     *
     * @param Reception $reception Réception pas encore créée
     * @param string    $piece     Numéro du document source
     * @param int       $orderId   rowid de la commande d'origine, 0 si aucune
     * @return array<int,array{qty:float,productId:int,desc:string,ref:string}> Lignes libres restantes
     * @throws Exception Si le coeur refuse une ligne
     */
    protected function stackLines(Reception $reception, $piece, $orderId)
    {
        $pending = array();

        foreach ($this->linesByDocument[$piece] as $line) {
            $sourceRef = trim((string) $line->AR_Ref);
            $productId = 0;

            if ($sourceRef === '') {
                $this->freeTextLines++;
            } else {
                $productKey = $this->buildRefExt($sourceRef);
                if (isset($this->productBySage[$productKey])) {
                    $productId = $this->productBySage[$productKey];
                } else {
                    // L'article n'a pas été repris : la ligne reste, en texte libre. La perdre
                    // amputerait la réception d'une entrée de marchandise réelle.
                    $this->missingProductLines++;
                    $this->missingProducts[$sourceRef] = true;
                }
            }

            $qty = (float) $line->DL_Qte;
            if ($qty == 0) {
                $this->zeroQtyLines++;
            } elseif ($qty < 0) {
                $this->negativeQtyLines++;
            }

            $desc = trim((string) $line->DL_Design);
            if ($desc === '') {
                $desc = ($sourceRef !== '') ? $sourceRef : 'Ligne sans désignation';
            }

            $orderLineId = $this->resolveOrderLine($line, $orderId, $productId);

            if ($orderLineId > 0) {
                // ATTENTION : addline() ne retourne PAS un code de succès mais l'INDEX de la
                // ligne dans $this->lines, compté avant l'ajout (reception.class.php:1038).
                // La première ligne de chaque réception rend donc 0, qui est un succès. Tester
                // `<= 0` rejetterait une réception sur deux, sans message d'erreur puisqu'il
                // n'y en a aucune — mesuré : 16 en échec sur les 20 premières.
                $result = $reception->addline($this->warehouseId, $orderLineId, $qty);
                if ($result < 0) {
                    throw new Exception('Ligne refusée (article '
                        .($sourceRef !== '' ? $sourceRef : 'texte libre').') : '
                        .$this->objectErrors($reception));
                }
                $this->linkedLines++;
                continue;
            }

            $pending[] = array(
                'qty'       => $qty,
                'productId' => $productId,
                'desc'      => $desc,
                'ref'       => $sourceRef,
            );
        }

        return $pending;
    }

    /**
     * Écrit les lignes qui n'ont aucune ligne de commande à quoi s'adosser.
     *
     * À appeler APRÈS `create()` : `addlinefree()` insère immédiatement, a besoin de
     * `$this->id` et refuse de travailler hors du statut brouillon — que `create()` ne pose
     * pas, d'où l'affectation explicite.
     *
     * Sa limite est assumée : elle n'écrit ni l'entrepôt ni le prix.
     *
     * @param Reception                                                          $reception Réception créée
     * @param array<int,array{qty:float,productId:int,desc:string,ref:string}>    $pending   Lignes à écrire
     * @return void
     * @throws Exception Si le coeur refuse une ligne
     */
    protected function addFreeLines(Reception $reception, array $pending)
    {
        if (empty($pending)) {
            return;
        }

        $reception->status = Reception::STATUS_DRAFT;
        $reception->statut = Reception::STATUS_DRAFT;

        $rang = 0;
        foreach ($pending as $item) {
            $rang++;

            // Le type d'élément est celui que pose l'écran natif pour une saisie libre
            // (reception/card.php:950), et non le « supplier_order » qu'insert() prend par
            // défaut : cette ligne ne vient d'aucune commande.
            $result = $reception->addlinefree(
                $item['qty'],
                'reception',
                $item['productId'],
                null,
                $rang,
                $item['desc']
            );

            if ($result <= 0) {
                throw new Exception('Ligne libre refusée (article '
                    .($item['ref'] !== '' ? $item['ref'] : 'texte libre').') : '
                    .$this->objectErrors($reception));
            }

            $this->freeLines++;
        }
    }

    /**
     * Ligne de commande à laquelle adosser une ligne de réception.
     *
     * La ligne source porte elle-même le numéro de commande dans `DL_PieceBC` : c'est lui qui
     * fait autorité, et non la commande du document. Une réception peut en effet solder des
     * lignes venues de deux commandes — le cas existe une fois.
     *
     * @param stdClass $line      Ligne source
     * @param int      $orderId   rowid de la commande du document, 0 si aucune
     * @param int      $productId rowid de l'article, 0 si non repris
     * @return int                rowid de la ligne de commande, 0 si aucune ne convient
     */
    protected function resolveOrderLine($line, $orderId, $productId)
    {
        if ($productId <= 0) {
            return 0;
        }

        $cited = trim((string) $line->DL_PieceBC);
        if ($cited === '') {
            return 0;
        }

        $refExt = $this->buildRefExt($cited);
        if (isset($this->orderBySage[$refExt])) {
            $target = $this->orderBySage[$refExt];
        } elseif ($orderId > 0) {
            $target = $orderId;
        } else {
            $this->unknownOrderLines++;
            return 0;
        }

        return isset($this->orderLineByProduct[$target][$productId])
            ? $this->orderLineByProduct[$target][$productId]
            : 0;
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si le document ne permet pas de bâtir une réception valide
     */
    protected function validateRow($row)
    {
        $piece = trim((string) $row->DO_Piece);

        $supplierKey = $this->buildRefExt(trim((string) $row->DO_Tiers));
        if (!isset($this->supplierBySage[$supplierKey])) {
            throw new Exception('Fournisseur absent de la reprise : '.trim((string) $row->DO_Tiers));
        }

        if (empty($this->linesByDocument[$piece])) {
            throw new Exception('Document sans ligne : Dolibarr refuse de valider une réception vide');
        }
    }

    /**
     * Verdict d'une ligne en simulation.
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

        $supplierKey = $this->buildRefExt(trim((string) $row->DO_Tiers));
        if (!isset($this->supplierBySage[$supplierKey])) {
            $this->missingSuppliers++;
            return 'skipped';
        }

        $orderId = $this->resolveOrder($piece);
        if ($orderId > 0) {
            $this->linkedToOrder++;
        } else {
            $this->withoutOrder++;
        }

        foreach ($this->linesByDocument[$piece] as $line) {
            $sourceRef = trim((string) $line->AR_Ref);
            $productId = 0;

            if ($sourceRef === '') {
                $this->freeTextLines++;
            } else {
                $productKey = $this->buildRefExt($sourceRef);
                if (isset($this->productBySage[$productKey])) {
                    $productId = $this->productBySage[$productKey];
                } else {
                    $this->missingProductLines++;
                    $this->missingProducts[$sourceRef] = true;
                }
            }

            if ($this->resolveOrderLine($line, $orderId, $productId) > 0) {
                $this->linkedLines++;
            } else {
                $this->freeLines++;
            }
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
        return 'Suppression des réceptions reprises (table '.MAIN_DB_PREFIX.'reception,'
            .' marquées « '.$this->refExtPrefix.' ») et de leurs lignes';
    }

    /**
     * Supprime les réceptions posées par ce script.
     *
     * `Reception::delete()` refuse une réception validée tant qu'elle n'est pas repassée en
     * brouillon : chaque objet est donc dévalidé avant d'être supprimé. Le coeur remet alors
     * de lui-même le statut de la commande d'origine.
     *
     * L'ordre décroissant n'est pas une précaution de style : le module de numérotation
     * refuserait de rendre un numéro qui n'est pas le dernier de sa séquence.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'reception';
        $sql .= ' WHERE entity IN ('.getEntity('reception').')';
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
            $reception = new Reception($this->db);
            if ($reception->fetch($rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : chargement impossible';
                continue;
            }

            if ($reception->statut > Reception::STATUS_DRAFT) {
                $reception->setDraft($this->user);
            }

            if ($reception->delete($this->user) <= 0) {
                $result['failed']++;
                $result['errors'][] = $reception->ref.' : '.$this->objectErrors($reception);
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
        if ($this->linkedToOrder > 0) {
            $block[] = $this->countLine($this->linkedToOrder, 'réception(s) rattachée(s) à leur commande');
        }
        if ($this->withoutOrder > 0) {
            $block[] = $this->countLine($this->withoutOrder, 'réception(s) sans commande d\'origine');
        }
        $this->appendBlock($lines, 'Rattachement', $block);

        $block = array();
        if ($this->linkedLines > 0) {
            $block[] = $this->countLine($this->linkedLines, 'ligne(s) adossée(s) à une ligne de commande'
                .' : article, quantité et entrepôt');
        }
        if ($this->freeLines > 0) {
            $block[] = $this->countLine($this->freeLines, 'ligne(s) libre(s), faute de ligne de commande'
                .' : sans entrepôt ni prix, limite d\'addlinefree()');
        }
        $this->appendBlock($lines, 'Lignes', $block);

        $block = array();
        if ($this->emptyDocuments > 0) {
            $block[] = $this->countLine($this->emptyDocuments, 'document(s) sans ligne, écarté(s)'
                .(empty($this->emptySamples) ? '' : ' — '.implode(', ', $this->emptySamples)));
        }
        if ($this->missingSuppliers > 0) {
            $block[] = $this->countLine($this->missingSuppliers, 'document(s) dont le fournisseur n\'est pas repris'
                .' : relancez « migrate.php thirdparty »');
        }
        if ($this->missingProductLines > 0) {
            $block[] = $this->countLine($this->missingProductLines, 'ligne(s) dont l\'article n\'est pas repris ('
                .count($this->missingProducts).' référence(s)) : conservées en texte libre');
        }
        if ($this->freeTextLines > 0) {
            $block[] = $this->countLine($this->freeTextLines, 'ligne(s) sans référence d\'article');
        }
        if ($this->unknownOrderLines > 0) {
            $block[] = $this->countLine($this->unknownOrderLines, 'ligne(s) citant une commande absente de la cible');
        }
        if ($this->zeroQtyLines > 0) {
            $block[] = $this->countLine($this->zeroQtyLines, 'ligne(s) de quantité nulle');
        }
        if ($this->negativeQtyLines > 0) {
            $block[] = $this->countLine($this->negativeQtyLines, 'ligne(s) de quantité négative : retour au fournisseur');
        }
        $this->appendBlock($lines, 'Anomalies', $block);

        $block = array();
        foreach ($this->discarded as $libelle => $nb) {
            $block[] = $this->countLine($nb, $libelle);
        }
        $this->appendBlock($lines, 'Écartés à la lecture', $block);

        // Rappel de la garantie qui conditionne tout le script, et de sa limite.
        $lines[] = 'Stock inchangé : STOCK_CALCULATE_ON_RECEPTION est inactive, la validation'
            .' ne mouvemente rien.';
        if (getDolGlobalInt('STOCK_CALCULATE_ON_RECEPTION_CLOSE')) {
            $lines[] = '  ⚠  STOCK_CALCULATE_ON_RECEPTION_CLOSE vaut 1, imposée par le coeur du fait'
                .' du module lots/séries. NE CLÔTUREZ PAS ces réceptions : chacune ajouterait'
                .' alors ses quantités au stock d\'ouverture.';
        }

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
