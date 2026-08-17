<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationproductkit.class.php
 * \ingroup aeromigration
 * \brief   Reprise des articles composés : f_nomenclat -> llx_product_association.
 *
 * PRÉREQUIS : la reprise des articles doit avoir été passée. Les deux extrémités de chaque
 * composition sont retrouvées par leur `ref_ext`.
 *
 * ------------------------------------------------------------------------------
 * DES LOTS COMMERCIAUX, PAS DES FABRICATIONS
 * ------------------------------------------------------------------------------
 *
 * Les 184 articles composés de l'ancien ERP sont des assemblages de vente : « Lot des 4 cartes
 * IGN-OACI », « Pochette VFR complète ». Rien n'est transformé — on vend ensemble des articles
 * qui existent séparément au catalogue, et qui se vendent aussi à l'unité, bien davantage même
 * que les lots.
 *
 * C'est la définition du **kit** de Dolibarr (`llx_product_association`, option
 * `PRODUIT_SOUSPRODUITS`), et non celle d'une nomenclature de fabrication : le module BOM
 * suppose un ordre de fabrication qui consomme les composants pour créer un produit fini.
 *
 * ## Ce qui est écarté, et pourquoi
 *
 * Sur les 508 lignes de la source, 19 ne sont pas reprises :
 *
 * - **3 lignes d'auto-référence** — `3887` ne contient que lui-même, deux fois ; `6571` se
 *   contient une fois en plus de son vrai composant. Une composition récursive n'a pas de sens
 *   métier et fait boucler tout système qui la déroule. Le cœur ne s'en protège pas : le
 *   contrôle anti-boucle d'`add_sousproduit()` exécute bien sa requête mais **n'en lit jamais
 *   le résultat** (product.class.php:4984), si bien qu'aucun cycle n'est refusé.
 * - **6 lignes en sommeil** (`statut = 'S'`), écartées sur décision du client. Elles ne sont
 *   pas du bruit : `4111` porte ses deux composants en double, une version en sommeil et une
 *   active. Les écarter résout ce doublon apparent sans rien arbitrer.
 * - **12 lignes de l'article `14749`**, mis de côté sur décision du client. Le composant `9592`
 *   y figure dix fois en quantité 1 : ce sont dix tailles d'un vêtement, gérées comme des
 *   articles distincts et non comme des variantes. Le cas se traitera à part.
 *
 * Reste **489 lignes, 181 articles composés, 248 composants**, sans un seul couple en double.
 *
 * ## Le stock des composants suit celui du lot
 *
 * `incdec = 1`, qui est le défaut de Dolibarr : vendre le lot décrémente chaque composant.
 * C'est le comportement attendu ici, le lot n'ayant pas de stock propre — il n'existe qu'au
 * moment de la vente. Le choix est réversible : `add_sousproduit()` commençant par supprimer
 * le lien, un rejeu avec l'autre valeur le remplace.
 *
 * ## Idempotence
 *
 * `llx_product_association` n'a ni `ref_ext` ni `import_key`. L'idempotence est portée par la
 * **comparaison du lien lui-même** : les associations déjà en base sont chargées en mémoire, et
 * `add_sousproduit()` n'est appelée que si le lien manque ou si sa quantité diffère.
 *
 * Trois associations existaient avant toute reprise, saisies à la main sur `#03881`, et elles
 * sont conformes à la source. Elles sont reconnues, pas redoublées.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
dol_include_once('/aeromigration/lib/aeromigration.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

class MigrationProductKit extends AeroMigrationRunner
{
    /** Écart en deçà duquel deux quantités sont tenues pour identiques. */
    const QTY_TOLERANCE = 0.000001;

    /** Référence de l'article mis de côté : dix tailles à traiter à part. */
    const EXCLUDED_REF = '14749';

    /** @var string Identifiant du script en ligne de commande */
    public $code = 'productkit';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptProductKit';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /**
     * Table source : la fiche article, et non la table des compositions.
     *
     * Le script raisonne par article composé et pose toutes ses liaisons d'un bloc. Parcourir
     * `f_nomenclat` ligne à ligne demanderait un curseur sur un couple de colonnes, que le
     * socle ne sait pas paginer — la table n'a pas de clé primaire simple.
     *
     * @var string
     */
    protected $srcTable = 'f_article';

    /** @var string Colonnes lues */
    protected $srcFields = 'AR_Ref, AR_Design';

    /** @var string Colonne de parcours : la clé primaire de f_article */
    protected $srcCursorField = 'AR_Ref';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle de l'article dans la source */
    protected $srcKeyField = 'AR_Ref';

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'product_association';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'product';

    /** @var array<string,int> Produits cible, par ref_ext */
    protected $productIndex = array();

    /** @var array<string,array<string,float>> Composition retenue, par composé puis composant */
    protected $compositions = array();

    /** @var array<int,array<int,float>> Associations déjà en base, par parent puis enfant */
    protected $existingLinks = array();

    /** @var int Liaisons créées */
    protected $linksCreated = 0;

    /** @var int Liaisons dont la quantité a été corrigée */
    protected $linksUpdated = 0;

    /** @var int Liaisons déjà justes, laissées telles quelles */
    protected $linksMatching = 0;

    /** @var int Composés absents du catalogue Dolibarr */
    protected $missingParents = 0;

    /** @var array<string,int> Composants introuvables en cible, par référence */
    protected $missingChildren = array();

    /** @var array<string,int> Lignes de composition écartées, par motif */
    protected $discarded = array();

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur exécutant la reprise
     */
    public function __construct($db, $user)
    {
        parent::__construct($db, $user);

        // La cible ne porte aucun marqueur de reprise : sans cette bascule, le socle
        // sauterait toutes les lignes. L'idempotence tient à la comparaison des liens.
        $this->updateExisting = true;

        // Posé ici et non dans `prepare()` : le socle dénombre la source avant de préparer,
        // et l'écran annoncerait sinon les 15 909 articles du catalogue au lieu des 181
        // réellement composés.
        $this->srcWhere = $this->getSourceWhere();
    }

    /**
     * Référence comparable, indépendante de toute collation.
     *
     * ------------------------------------------------------------------------------
     * SANS `BINARY`, LE SCRIPT ÉCHOUE SUR CERTAINES INSTALLATIONS ET PAS SUR D'AUTRES
     * ------------------------------------------------------------------------------
     *
     * Le script compare deux colonnes de types différents : `AR_Ref` est un `varchar`, mais
     * `NO_RefDet` est un **entier**. Or `CAST(<entier> AS CHAR)` ne tient pas sa collation d'une
     * table — il prend celle de la **connexion**. Dès que celle-ci diffère de la collation des
     * tables tout en partageant leur jeu de caractères, MySQL refuse la comparaison :
     *
     *     Illegal mix of collations (utf8mb3_general_ci,IMPLICIT)
     *                          and (utf8mb3_unicode_ci,IMPLICIT) for operation '='
     *
     * C'est exactement le cas de l'instance en ligne : ses tables sont en `utf8mb3_general_ci`
     * et Dolibarr ouvre sa connexion en `utf8mb3_unicode_ci`.
     *
     * **Le piège est que l'anomalie ne se voit pas partout.** En développement, les tables sont
     * en `utf8mb4` : le jeu de caractères différant de celui de la connexion, MySQL convertit
     * vers le plus large au lieu de refuser, et tout passe. La même requête jouée dans
     * phpMyAdmin passe aussi, sa connexion étant en `utf8mb4`. Seul Dolibarr échoue, et
     * seulement en ligne — de quoi chercher longtemps du côté des données.
     *
     * `BINARY` place les deux membres hors de toute collation et clôt la question sans écrire
     * de nom de collation en dur, qui serait faux sur la prochaine installation.
     *
     * **Ce que cela change, et pourquoi c'est sans effet ici :** la comparaison devient sensible
     * à la casse. Vérifié avant de l'adopter — aucune référence de `f_article` ni de
     * `f_nomenclat` ne se distingue d'une autre par la seule casse. Une comparaison numérique
     * aurait été plus naturelle, mais 22 des 15 909 références ne sont pas numériques.
     *
     * @param  string $column Colonne ou expression portant une référence article
     * @return string         Expression SQL comparable
     */
    protected function refExpr($column)
    {
        return 'BINARY CAST('.$column.' AS CHAR)';
    }

    /**
     * Condition SQL désignant les lignes de composition retenues.
     *
     * Écrite une fois et réutilisée par le filtre source, par le chargement et par le
     * décompte : les trois doivent parler du même périmètre, faute de quoi le rapport
     * mentirait sur ce qui a été fait.
     *
     * @return string
     */
    protected function keptLinesCondition()
    {
        $sql  = ' '.$this->refExpr('AR_Ref').' <> '.$this->refExpr('NO_RefDet');
        $sql .= " AND TRIM(COALESCE(statut, '')) <> 'S'";
        $sql .= ' AND '.$this->refExpr('AR_Ref')." <> '".$this->db->escape(self::EXCLUDED_REF)."'";

        return $sql;
    }

    /**
     * Filtre source : les seuls articles réellement composés.
     *
     * Les deux membres du `IN` passent par refExpr() : sans quoi `f_article.AR_Ref`, qui porte la
     * collation de sa table, affronterait une sous-requête déjà neutralisée.
     *
     * @return string
     */
    protected function getSourceWhere()
    {
        $sql  = "TRIM(AR_Ref) <> ''";
        $sql .= ' AND '.$this->refExpr('AR_Ref').' IN (SELECT '.$this->refExpr('AR_Ref');
        $sql .= ' FROM '.$this->src('f_nomenclat');
        $sql .= ' WHERE'.$this->keptLinesCondition().')';

        return $sql;
    }

    /**
     * Charge les index nécessaires.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        foreach (array(
            'checkConfiguration',
            'loadProductIndex',
            'loadCompositions',
            'loadExistingLinks',
        ) as $step) {
            if ($this->{$step}() < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * Vérifie que la cible sait porter des articles composés.
     *
     * Sans `PRODUIT_SOUSPRODUITS`, les liaisons s'écrivent sans erreur mais la fiche produit
     * n'affiche aucun onglet pour les voir, et la vente ne les déroule pas. Le travail serait
     * invisible.
     *
     * @return int 1 si la configuration convient, -1 sinon
     */
    protected function checkConfiguration()
    {
        if (!getDolGlobalString('PRODUIT_SOUSPRODUITS')) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Les produits composés sont désactivés : activez PRODUIT_SOUSPRODUITS'
                    .' (Configuration > Modules > Produits) avant de reprendre les kits.',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Recense les produits repris, par ref_ext.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadProductIndex()
    {
        $this->productIndex = array();

        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->productIndex[$obj->ref_ext] = (int) $obj->rowid;
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
     * Charge la composition de chaque article retenu.
     *
     * Un couple ne peut apparaître qu'une fois : la source n'en porte aucun en double une fois
     * les lignes en sommeil écartées, mais la garde reste, un doublon devant produire une seule
     * liaison et non deux écritures successives.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadCompositions()
    {
        $this->compositions = array();

        $sql  = 'SELECT CAST(AR_Ref AS CHAR) as parent, CAST(NO_RefDet AS CHAR) as child, NO_Qte';
        $sql .= ' FROM '.$this->src('f_nomenclat');
        $sql .= ' WHERE'.$this->keptLinesCondition();
        $sql .= ' ORDER BY AR_Ref, NO_RefDet, cbMarq DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $duplicates = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $parent = trim((string) $obj->parent);
            $child  = trim((string) $obj->child);

            if (isset($this->compositions[$parent][$child])) {
                $duplicates++;
                continue;
            }

            $qty = (float) $obj->NO_Qte;
            $this->compositions[$parent][$child] = ($qty > 0) ? $qty : 1;
        }
        $this->db->free($resql);

        if ($duplicates > 0) {
            $this->discarded['couples en double, la première lue l\'emporte'] = $duplicates;
        }

        return $this->countDiscardedLines();
    }

    /**
     * Dénombre les lignes écartées, pour que le rapport le dise.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function countDiscardedLines()
    {
        $motifs = array(
            'lignes où l\'article se contient lui-même'
                => $this->refExpr('AR_Ref').' = '.$this->refExpr('NO_RefDet'),
            'lignes de composition en sommeil'          => "TRIM(COALESCE(statut, '')) = 'S'",
            'lignes de l\'article '.self::EXCLUDED_REF.', mis de côté'
                => $this->refExpr('AR_Ref')." = '".$this->db->escape(self::EXCLUDED_REF)."'",
        );

        foreach ($motifs as $libelle => $condition) {
            $sql   = 'SELECT COUNT(*) as nb FROM '.$this->src('f_nomenclat').' WHERE '.$condition;
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
     * Charge les associations déjà en base.
     *
     * Une seule lecture, là où `is_sousproduit()` en ferait une par couple.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadExistingLinks()
    {
        $this->existingLinks = array();

        $sql = 'SELECT fk_product_pere, fk_product_fils, qty FROM '.MAIN_DB_PREFIX.'product_association';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->existingLinks[(int) $obj->fk_product_pere][(int) $obj->fk_product_fils] = (float) $obj->qty;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Index des lignes déjà reprises : volontairement vide.
     *
     * `llx_product_association` ne porte aucun marqueur. La comparaison des liens tient lieu
     * d'idempotence, et elle a lieu dans `migrateRow()`.
     *
     * @return int Toujours 0
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        return 0;
    }

    /**
     * Nombre d'articles composés dont au moins une liaison est en place.
     *
     * @return int Nombre d'articles, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(DISTINCT pa.fk_product_pere) as nb';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_association as pa';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pa.fk_product_pere';
        $sql .= " WHERE p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Liaisons à écrire pour un article, comparées à ce qui est déjà en base.
     *
     * @param string $ref      Référence source de l'article composé
     * @param int    $parentId rowid du produit composé
     * @return array<int,float> Quantité voulue, par rowid de composant
     */
    protected function pendingLinks($ref, $parentId)
    {
        $pending = array();

        if (empty($this->compositions[$ref])) {
            return $pending;
        }

        foreach ($this->compositions[$ref] as $childRef => $qty) {
            $childExt = $this->buildRefExt($childRef);

            if (!isset($this->productIndex[$childExt])) {
                $this->missingChildren[$childRef] = isset($this->missingChildren[$childRef])
                    ? $this->missingChildren[$childRef] + 1 : 1;
                continue;
            }

            $childId = $this->productIndex[$childExt];

            // Un composant ne peut pas être son propre parent : la source n'en porte plus
            // après filtrage, mais le cœur ne refuserait pas le lien — son contrôle
            // anti-boucle ne lit jamais le résultat de sa propre requête.
            if ($childId === $parentId) {
                continue;
            }

            if (isset($this->existingLinks[$parentId][$childId])
                && abs($this->existingLinks[$parentId][$childId] - $qty) <= self::QTY_TOLERANCE) {
                $this->linksMatching++;
                continue;
            }

            $pending[$childId] = $qty;
        }

        return $pending;
    }

    /**
     * Pose les liaisons d'un article composé.
     *
     * @param stdClass $row        Ligne de f_article
     * @param int      $existingId Toujours 0, l'index étant vide
     * @return array{action:string,id:int}
     * @throws Exception Si une liaison est refusée
     */
    protected function migrateRow($row, $existingId)
    {
        $ref    = trim((string) $row->AR_Ref);
        $refExt = $this->buildRefExt($ref);

        if (!isset($this->productIndex[$refExt])) {
            $this->missingParents++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $parentId = $this->productIndex[$refExt];
        $pending  = $this->pendingLinks($ref, $parentId);

        if (empty($pending)) {
            return array('action' => 'skipped', 'id' => $parentId);
        }

        $isNew = empty($this->existingLinks[$parentId]);

        $product = new Product($this->db);
        if ($product->fetch($parentId) <= 0) {
            throw new Exception('Article composé introuvable (rowid '.$parentId.') : '.$this->objectErrors($product));
        }

        foreach ($pending as $childId => $qty) {
            $wasThere = isset($this->existingLinks[$parentId][$childId]);

            // `add_sousproduit()` commence par supprimer le lien existant : elle remplace,
            // elle n'empile pas. Un rejeu ne peut donc pas doubler une liaison.
            // $incdec = 1 : vendre le lot décrémente le stock de chaque composant.
            $result = $product->add_sousproduit($parentId, $childId, $qty, 1);
            if ($result <= 0) {
                throw new Exception('Liaison refusée vers le composant '.$childId.' : '
                    .$this->objectErrors($product));
            }

            $this->existingLinks[$parentId][$childId] = $qty;

            if ($wasThere) {
                $this->linksUpdated++;
            } else {
                $this->linksCreated++;
            }
        }

        return array('action' => $isNew ? 'created' : 'updated', 'id' => $parentId);
    }

    /**
     * Contrôle une ligne en simulation : les liaisons sont résolues, rien n'est écrit.
     *
     * @param stdClass $row Ligne de f_article
     * @return void
     * @throws Exception Si l'article composé n'a aucune composition exploitable
     */
    protected function validateRow($row)
    {
        $ref = trim((string) $row->AR_Ref);

        if (empty($this->compositions[$ref])) {
            throw new Exception('Aucune composition retenue pour cet article');
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
        $ref    = trim((string) $row->AR_Ref);
        $refExt = $this->buildRefExt($ref);

        if (!isset($this->productIndex[$refExt])) {
            $this->missingParents++;
            return 'skipped';
        }

        $parentId = $this->productIndex[$refExt];
        $pending  = $this->pendingLinks($ref, $parentId);

        if (empty($pending)) {
            return 'skipped';
        }

        foreach ($pending as $childId => $qty) {
            if (isset($this->existingLinks[$parentId][$childId])) {
                $this->linksUpdated++;
            } else {
                $this->linksCreated++;
            }
        }

        return empty($this->existingLinks[$parentId]) ? 'created' : 'updated';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Suppression des liaisons de composition portées par les articles repris ('
            .MAIN_DB_PREFIX.'product_association)';
    }

    /**
     * Supprime les liaisons des articles composés repris.
     *
     * Passe par `del_sousproduit()`, qui est la voie du cœur, plutôt que par un DELETE : la
     * méthode déclenche le trigger que d'autres modules peuvent écouter.
     *
     * Les liaisons dont le parent ne vient pas de la reprise ne sont pas touchées.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT pa.fk_product_pere, pa.fk_product_fils';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_association as pa';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pa.fk_product_pere';
        $sql .= ' WHERE p.entity IN ('.getEntity('product').')';
        $sql .= "   AND p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }

        $links = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $links[] = array((int) $obj->fk_product_pere, (int) $obj->fk_product_fils);
        }
        $this->db->free($resql);

        $result['count'] = count($links);
        if (!$confirm || $result['count'] === 0) {
            return $result;
        }

        $product = new Product($this->db);
        $done    = 0;

        foreach ($links as $link) {
            if ($product->del_sousproduit($link[0], $link[1]) < 0) {
                $result['failed']++;
                $result['errors'][] = 'Liaison '.$link[0].' -> '.$link[1].' : '.$product->error;
            } else {
                $result['deleted']++;
            }

            $done++;
            if (is_callable($progress) && ($done % 50 === 0 || $done === $result['count'])) {
                call_user_func($progress, $done, $result['count']);
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

        $lines[] = 'Liaisons de composition :';
        $lines[] = '  '.str_pad(number_format($this->linksCreated, 0, ',', ' '), 8, ' ', STR_PAD_LEFT)
            .'  créée(s)';
        if ($this->linksUpdated > 0) {
            $lines[] = '  '.str_pad(number_format($this->linksUpdated, 0, ',', ' '), 8, ' ', STR_PAD_LEFT)
                .'  quantité(s) corrigée(s)';
        }
        if ($this->linksMatching > 0) {
            $lines[] = '  '.str_pad(number_format($this->linksMatching, 0, ',', ' '), 8, ' ', STR_PAD_LEFT)
                .'  déjà juste(s), laissée(s) telle(s) quelle(s)';
        }

        if ($this->missingParents > 0) {
            $lines[] = '  '.str_pad((string) $this->missingParents, 8, ' ', STR_PAD_LEFT)
                .'  article(s) composé(s) absent(s) du catalogue : relancez « migrate.php product »';
        }
        if (!empty($this->missingChildren)) {
            $lines[] = '  '.str_pad((string) count($this->missingChildren), 8, ' ', STR_PAD_LEFT)
                .'  composant(s) introuvable(s) en cible : '
                .implode(', ', array_slice(array_keys($this->missingChildren), 0, 10));
        }

        if (!empty($this->discarded)) {
            $lines[] = '';
            $lines[] = 'Lignes de composition écartées :';
            foreach ($this->discarded as $libelle => $nb) {
                $lines[] = '  '.str_pad(number_format($nb, 0, ',', ' '), 8, ' ', STR_PAD_LEFT).'  '.$libelle;
            }
            $lines[] = '          Les articles composés sont des lots commerciaux : le stock des';
            $lines[] = '          composants suit celui du lot (incdec = 1).';
        }

        // Ce que la fiche produit affichera, une fois la reprise passée.
        $sql  = 'SELECT COUNT(DISTINCT pa.fk_product_pere) as parents, COUNT(*) as liens';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_association as pa';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pa.fk_product_pere';
        $sql .= " WHERE p.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);

            $lines[] = '';
            $lines[] = 'En base : '.number_format($obj->parents, 0, ',', ' ').' article(s) composé(s), '
                .number_format($obj->liens, 0, ',', ' ').' liaison(s)';
        }

        return $lines;
    }
}
