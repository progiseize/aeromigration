<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationcategory.class.php
 * \ingroup aeromigration
 * \brief   Reprise du catalogue : f_catalogue -> catégories produit de Dolibarr.
 *
 * `f_catalogue` porte le classement commercial de l'ancien ERP : 504 catégories réparties
 * sur quatre niveaux (61 racines, 175, 254, puis 14). C'est ce classement que les articles
 * référencent par leurs colonnes `CL_No1`, `CL_No2` et `CL_No3` — à ne pas confondre avec
 * `FA_CodeFamille`, qui n'est qu'un regroupement de TVA.
 *
 * Deux particularités :
 *
 * - **La hiérarchie doit être créée dans l'ordre.** Une catégorie ne peut recevoir son
 *   parent que si celui-ci existe déjà, or le parcours suit `CL_No`, pas le niveau. Le
 *   parent est donc créé à la demande, récursivement, avant son enfant.
 *
 * - **Les catégories déjà remontées par la boutique sont adoptées**, via
 *   `llx_prestasync_resource_element` (`presta_resource = 'categories'`). Contrairement
 *   aux produits, le module ne leur consacre pas de table dédiée : il utilise sa table de
 *   liens générique.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

class MigrationCategory extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'category';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptCategory';

    /** @var string Table source */
    protected $srcTable = 'f_catalogue';

    /** @var string Colonne de parcours : la clé primaire du catalogue */
    protected $srcCursorField = 'CL_No';

    /** @var string Le curseur est ici un entier */
    protected $srcCursorType = 'int';

    /** @var string Clé naturelle de la catégorie dans la source */
    protected $srcKeyField = 'CL_No';

    /** @var string Une catégorie sans libellé ne serait pas exploitable */
    protected $srcWhere = "TRIM(COALESCE(CL_Intitule,'')) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'categorie';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'category';

    /** @var int Identifiant de la boutique dans les tables de liaison */
    protected $prestaShopId = 1;

    /**
     * Libellé de la catégorie sous laquelle greffer les rubriques racines.
     *
     * La boutique impose sa propre arborescence — « Boutique Aero : Racine » puis
     * « Accueil » — sous laquelle vivent toutes ses catégories. Les rubriques que
     * `f_catalogue` déclare à la racine (`CL_NoParent = 0`) y sont rattachées, faute de
     * quoi elles apparaîtraient en parallèle de l'arbre existant.
     *
     * La catégorie est retrouvée par son libellé, identique sur toutes les instances,
     * plutôt que par un identifiant qui, lui, varie de l'une à l'autre.
     *
     * @var string
     */
    protected $homeCategoryLabel = 'Accueil';

    /** @var int Catégorie d'accueil résolue au démarrage, 0 si aucune */
    protected $homeCategoryId = 0;

    /** @var bool La table de liens générique est-elle disponible ? */
    protected $prestaLinkAvailable = false;

    /** @var array<string,int> id catégorie boutique -> rowid de la catégorie Dolibarr */
    protected $categoryByPrestaId = array();

    /** @var array<int,stdClass> Catalogue source indexé par CL_No, pour résoudre les parents */
    protected $catalogueByNo = array();

    /** @var array<int,int> CL_No -> rowid Dolibarr, alimenté au fil de la reprise */
    protected $categoryByClNo = array();

    /** @var array<int,bool> Garde-fou contre les cycles dans la hiérarchie */
    protected $resolving = array();

    /** @var int Catégories créées comme parent, avant leur propre tour */
    protected $createdAsParent = 0;

    /** @var int Parents introuvables : la catégorie est créée à la racine */
    protected $orphanParents = 0;

    /** @var int Doublons du catalogue fusionnés avec une catégorie existante */
    protected $mergedTwins = 0;

    /** @var int Déplacements abandonnés : une homonyme occupe déjà la place visée */
    protected $blockedMoves = 0;

    /**
     * Charge le catalogue source et les correspondances avec la boutique.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function prepare()
    {
        // Le catalogue entier est chargé en mémoire : 504 lignes, et la résolution des
        // parents exige de pouvoir remonter la hiérarchie sans requête supplémentaire.
        $sql   = 'SELECT CL_No, CL_Intitule, CL_NoParent, CL_Niveau, id_externe FROM '.$this->srcTable;
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->catalogueByNo[(int) $obj->CL_No] = $obj;
        }
        $this->db->free($resql);

        // Catégories déjà reprises, pour retrouver un parent créé lors d'un passage
        // antérieur sans le recréer.
        $sql   = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'categorie';
        $sql  .= ' WHERE entity IN ('.getEntity('category').') AND type = 0';
        $sql  .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
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

        if ($this->checkHomeCategory() < 0) {
            return -1;
        }

        return $this->loadPrestaLinks();
    }

    /**
     * Vérifie la catégorie d'accueil sous laquelle greffer les rubriques racines.
     *
     * Un identifiant qui ne désigne pas une catégorie produit est une erreur de
     * configuration : mieux vaut s'arrêter que rattacher tout le catalogue au mauvais
     * endroit.
     *
     * @return int 1 si OK, -1 si l'identifiant est invalide
     */
    protected function checkHomeCategory()
    {
        // Recherche par libellé : il est le même partout, contrairement aux identifiants.
        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie';
        $sql .= " WHERE label = '".$this->db->escape($this->homeCategoryLabel)."'";
        $sql .= ' AND type = 0 AND entity IN ('.getEntity('category').')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $found = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $found[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        // Plusieurs homonymes : le choix ne peut pas être automatique, mieux vaut
        // s'arrêter que greffer 500 rubriques au mauvais endroit.
        if (count($found) > 1) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Plusieurs catégories « '.$this->homeCategoryLabel.' » existent (rowid '
                    .implode(', ', $found).'). Le rattachement ne peut pas être déterminé : '
                    .'supprimez ou renommez les doublons avant de relancer.',
            );
            return -1;
        }

        if (empty($found)) {
            // Sans catégorie d'accueil, les rubriques racines le restent. Ce n'est pas
            // bloquant : une instance sans boutique n'en a pas.
            $this->homeCategoryId = 0;
            dol_syslog('MigrationCategory : aucune catégorie « '.$this->homeCategoryLabel
                .' », les rubriques racines resteront à la racine', LOG_NOTICE);

            return 1;
        }

        $this->homeCategoryId = $found[0];

        return 1;
    }

    /**
     * Charge les correspondances avec les catégories de la boutique.
     *
     * Le module ne consacre pas de table dédiée aux catégories : il les enregistre dans
     * sa table de liens générique, typée par `presta_resource` et `dol_element`.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadPrestaLinks()
    {
        $table = MAIN_DB_PREFIX.'prestasync_resource_element';

        if (!$this->db->query('SELECT rowid FROM '.$table.' LIMIT 1', 1)) {
            dol_syslog('MigrationCategory : table '.$table.' absente, aucune adoption possible', LOG_NOTICE);
            return 1;
        }

        $this->prestaLinkAvailable = true;

        $sql  = 'SELECT r.presta_resource_id, r.dol_element_id FROM '.$table.' as r';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'categorie as c ON c.rowid = r.dol_element_id';
        $sql .= ' WHERE r.fk_presta = '.((int) $this->prestaShopId);
        $sql .= " AND r.presta_resource = 'categories' AND r.dol_element = 'category_product'";
        $sql .= ' AND c.type = 0 AND c.entity IN ('.getEntity('category').')';
        $sql .= " AND (c.ref_ext IS NULL OR c.ref_ext NOT LIKE '".$this->db->escape($this->refExtPrefix)."%')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->categoryByPrestaId[(string) $obj->presta_resource_id] = (int) $obj->dol_element_id;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Identifiant de la catégorie dans la boutique.
     *
     * @param stdClass $row Ligne source
     * @return string       Identifiant, chaîne vide si inexploitable
     */
    protected function getPrestaCategoryId($row)
    {
        $id = trim((string) $row->id_externe);

        return ($id === '' || $id === '0' || !ctype_digit($id)) ? '' : $id;
    }

    /**
     * Annonce l'action prévue en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la catégorie déjà reprise, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'updated';
        }

        $prestaId = $this->getPrestaCategoryId($row);

        return ($prestaId !== '' && isset($this->categoryByPrestaId[$prestaId])) ? 'adopted' : 'created';
    }

    /**
     * Crée ou adopte la catégorie correspondant à une ligne du catalogue.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid de la catégorie déjà reprise, 0 sinon
     * @return array{action:string,id:int}
     * @throws Exception Si l'écriture échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $clNo = (int) $row->CL_No;

        // La catégorie a pu être créée en tant que parent d'une autre, plus tôt dans le
        // parcours : elle est alors déjà à jour.
        if ($existingId <= 0 && isset($this->categoryByClNo[$clNo])) {
            return array('action' => 'skipped', 'id' => $this->categoryByClNo[$clNo]);
        }

        $id = $this->ensureCategory($clNo, $existingId);

        return array('action' => $this->lastAction, 'id' => $id);
    }

    /** @var string Action effectuée par le dernier appel à ensureCategory() */
    protected $lastAction = 'created';

    /**
     * Garantit l'existence de la catégorie, en créant son parent au besoin.
     *
     * Le parcours suit `CL_No`, pas la profondeur : une catégorie de niveau 2 peut donc
     * se présenter avant son parent. Celui-ci est alors créé à la volée, récursivement.
     *
     * @param int $clNo       Identifiant catalogue
     * @param int $existingId rowid déjà connu, 0 sinon
     * @return int            rowid de la catégorie
     * @throws Exception Si l'écriture échoue
     */
    protected function ensureCategory($clNo, $existingId = 0)
    {
        // Le cache sert à résoudre les parents sans les recréer. Il ne doit pas
        // court-circuiter le traitement de la ligne courante : sans cette réserve,
        // --update n'aurait aucun effet, toutes les catégories étant déjà connues.
        if ($existingId <= 0 && isset($this->categoryByClNo[$clNo])) {
            $this->lastAction = 'skipped';
            return $this->categoryByClNo[$clNo];
        }

        if (!isset($this->catalogueByNo[$clNo])) {
            throw new Exception('Catégorie '.$clNo.' absente du catalogue');
        }

        $row = $this->catalogueByNo[$clNo];

        // ── Parent ─────────────────────────────────────────────────────────
        // Une rubrique racine du catalogue est greffée sous la catégorie d'accueil de la
        // boutique, pour rejoindre l'arborescence existante au lieu de vivre à côté.
        $parentId = $this->homeCategoryId;
        $parentNo = (int) $row->CL_NoParent;

        if ($parentNo > 0 && $parentNo !== $clNo) {
            if (isset($this->resolving[$parentNo])) {
                // Cycle dans la hiérarchie : on rattache à la racine plutôt que de
                // boucler indéfiniment.
                $this->orphanParents++;
            } elseif (!isset($this->catalogueByNo[$parentNo])) {
                $this->orphanParents++;
            } else {
                $this->resolving[$clNo] = true;
                $parentId = $this->ensureCategory($parentNo);
                unset($this->resolving[$clNo]);

                // Le parent vient d'être créé hors de son tour : à comptabiliser.
                if ($this->lastAction === 'created') {
                    $this->createdAsParent++;
                }
            }
        }

        // ── Adoption ou création ───────────────────────────────────────────
        $categorie = new Categorie($this->db);
        $prestaId  = $this->getPrestaCategoryId($row);
        $adoptedId = ($prestaId !== '' && isset($this->categoryByPrestaId[$prestaId]))
            ? $this->categoryByPrestaId[$prestaId]
            : 0;

        $targetId = ($existingId > 0) ? $existingId : $adoptedId;

        if ($targetId > 0) {
            if ($categorie->fetch($targetId) <= 0) {
                throw new Exception('Catégorie introuvable (rowid '.$targetId.') : '.$this->objectErrors($categorie));
            }

            // Le libellé de la boutique fait autorité ; on ne pose que le rattachement
            // et le marqueur de reprise.
            //
            // Le déplacement est abandonné si une homonyme occupe déjà la place visée :
            // Dolibarr le refuserait, et le catalogue contient des libellés en double
            // sous un même parent. La catégorie reste alors où elle est.
            if ($parentId > 0 && empty($categorie->fk_parent)) {
                $twinId = $this->findTwinCategory($categorie->label, $parentId);
                if ($twinId > 0 && $twinId !== $targetId) {
                    $this->blockedMoves++;
                } else {
                    $categorie->fk_parent = $parentId;
                }
            }
            $categorie->ref_ext = $this->buildRefExt((string) $clNo);

            if ($categorie->update($this->user) < 0) {
                throw new Exception('Échec de la mise à jour : '.$this->objectErrors($categorie));
            }

            $this->categoryByClNo[$clNo] = $targetId;
            unset($this->categoryByPrestaId[$prestaId]);
            $this->lastAction = ($existingId > 0) ? 'updated' : 'adopted';

            return $targetId;
        }

        $label = trim((string) $row->CL_Intitule);

        // Le catalogue contient des doublons : six libellés y figurent deux fois sous le
        // même parent, plus un septième qui n'en diffère que par la casse et ses points
        // de suspension. Dolibarr refuse ces homonymes, à juste titre. On réutilise alors
        // la catégorie déjà en place plutôt que d'échouer : les deux entrées de la source
        // désignent bien la même rubrique, et les articles des deux s'y retrouveront.
        $twinId = $this->findTwinCategory($label, $parentId);
        if ($twinId > 0) {
            $this->mergedTwins++;
            $this->categoryByClNo[$clNo] = $twinId;
            $this->lastAction            = 'skipped';

            return $twinId;
        }

        $categorie->label       = $label;
        $categorie->type        = Categorie::TYPE_PRODUCT;
        $categorie->fk_parent   = $parentId;
        $categorie->visible     = 1;
        $categorie->description = 'Catégorie reprise de l\'ancien ERP (ADD)';
        $categorie->ref_ext     = $this->buildRefExt((string) $clNo);

        if ($categorie->create($this->user) <= 0) {
            throw new Exception('Échec de la création de « '.$categorie->label.' » : '.$this->objectErrors($categorie));
        }

        $this->categoryByClNo[$clNo] = (int) $categorie->id;
        $this->lastAction            = 'created';

        return (int) $categorie->id;
    }

    /**
     * Cherche une catégorie de même libellé sous le même parent.
     *
     * Dolibarr impose l'unicité du couple (libellé, parent) : la trouver avant de créer
     * évite un échec et permet de fusionner les doublons du catalogue.
     *
     * @param string $label    Libellé recherché
     * @param int    $parentId Parent, 0 pour une racine
     * @return int             rowid de la catégorie, 0 si aucune
     */
    protected function findTwinCategory($label, $parentId)
    {
        $sql  = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie';
        $sql .= ' WHERE entity IN ('.getEntity('category').') AND type = 0';
        $sql .= " AND label = '".$this->db->escape($label)."'";
        $sql .= ' AND fk_parent = '.((int) $parentId);
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->rowid : 0;
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne est inexploitable
     */
    protected function validateRow($row)
    {
        if (trim((string) $row->CL_Intitule) === '') {
            throw new Exception('Catégorie sans libellé');
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

        if ($this->homeCategoryId > 0) {
            $lines[] = 'Rubriques racines greffées sous : « '.$this->homeCategoryLabel.' » (rowid '.$this->homeCategoryId.')';
        } else {
            $lines[] = 'Rubriques racines créées à la racine de Dolibarr.';
        }

        if ($this->createdAsParent > 0) {
            $lines[] = 'Créées comme parent avant leur tour : '.$this->createdAsParent;
        }

        if ($this->orphanParents > 0) {
            $lines[] = 'Parents introuvables : '.$this->orphanParents.' catégorie(s) rattachée(s) à la racine';
        }

        if ($this->mergedTwins > 0) {
            $lines[] = 'Doublons du catalogue fusionnés : '.$this->mergedTwins;
            $lines[] = '  Même libellé sous le même parent : la catégorie existante est réutilisée.';
        }

        if ($this->blockedMoves > 0) {
            $lines[] = 'Déplacements abandonnés : '.$this->blockedMoves;
            $lines[] = '  Une catégorie du même nom occupe déjà la place visée : celle-ci reste où elle est.';
        }

        if (!$this->prestaLinkAvailable) {
            $lines[] = 'Boutique en ligne : table '.MAIN_DB_PREFIX.'prestasync_resource_element absente,';
            $lines[] = '  aucune catégorie de la boutique n\'a pu être rattachée.';
        }

        return $lines;
    }
}
