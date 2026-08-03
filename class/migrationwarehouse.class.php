<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationwarehouse.class.php
 * \ingroup aeromigration
 * \brief   Reprise des dépôts : f_depot -> entrepôts Dolibarr.
 *
 * Un dépôt de l'ancien ERP devient un entrepôt Dolibarr. Le dossier n'en compte qu'un de
 * réel, « boutique.aero », qui porte la totalité du stock, les seuils et les dix-neuf
 * caisses. Le script est néanmoins écrit pour plusieurs : cela ne coûte rien et évite d'y
 * revenir le jour où le client en ouvrira un second.
 *
 * ---------------------------------------------------------------------------
 * Ce que ce script ne fait plus
 * ---------------------------------------------------------------------------
 * Une première version créait un sous-entrepôt par emplacement de rangement : sept cent
 * dix-neuf entrepôts pour un dépôt réel. Chaque sélecteur d'entrepôt en était encombré, un
 * déplacement d'étagère devenait un transfert de stock, et les états par entrepôt
 * n'étaient plus lisibles.
 *
 * Un emplacement n'est pas une unité de gestion de stock. Il est désormais porté par le
 * dictionnaire `c_aerotoolbox_location` et par un champ de la fiche produit — voir les
 * scripts `location` et `productlocation`. L'entrepôt dit **combien**, l'emplacement dit
 * **où**.
 *
 * ---------------------------------------------------------------------------
 * Adoption plutôt que création
 * ---------------------------------------------------------------------------
 * La cible n'est pas vierge : l'entrepôt de la boutique préexiste à la reprise sur
 * l'instance en service. Un entrepôt qui porte déjà le nom du dépôt est donc **adopté** —
 * on ne le recrée pas, on ne le renomme pas, on lui pose son marqueur et on complète ce
 * qui est vide.
 *
 * Ce marqueur n'est pas décoratif : les scripts `stock` et `location` cherchent
 * `SAGE:DEPOT1` pour savoir où verser le stock et à quoi rattacher les emplacements, et
 * s'arrêtent net s'ils ne le trouvent pas.
 *
 * ---------------------------------------------------------------------------
 * Idempotence
 * ---------------------------------------------------------------------------
 * `llx_entrepot` n'a pas de `ref_ext` mais porte un `import_key`, qu'`Entrepot::create()`
 * écrit. C'est lui qui sert de marqueur, et les chemins génériques du socle qui s'appuient
 * sur `ref_ext` — `loadMigratedIndex()`, `countMigrated()`, `purge()` — sont donc tous
 * surchargés.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

class MigrationWarehouse extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'warehouse';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptWarehouse';

    /**
     * Base où lire la source.
     *
     * `f_depot` n'a jamais été importée à côté des `llx_*` : elle vit dans l'export
     * intégral de l'éditeur. Modifiable par --source-db.
     *
     * @var string
     */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_depot';

    /** @var string Colonnes lues */
    protected $srcFields = 'DE_No, DE_Intitule, DE_Adresse, DE_Complement, DE_CodePostal,'
        .' DE_Ville, DE_Pays, DE_Telephone, DE_Telecopie';

    /** @var string Colonne de parcours : la clé primaire de la table */
    protected $srcCursorField = 'DE_No';

    /** @var string Le curseur est un entier */
    protected $srcCursorType = 'int';

    /** @var string Clé naturelle */
    protected $srcKeyField = 'DE_No';

    /**
     * Filtre de lecture.
     *
     * Le dépôt 999 « Siege boutique.aero » n'est pas un lieu de stockage : c'est une
     * écriture technique de l'ancien ERP, dont les 11 826 lignes de stock sont
     * intégralement à zéro. Le reprendre créerait un entrepôt vide dont personne ne saurait
     * quoi faire, et qu'il faudrait écarter de chaque écran.
     *
     * @var string
     */
    protected $srcWhere = "DE_No <> 999 AND TRIM(COALESCE(DE_Intitule, '')) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'entrepot';

    /** @var string Élément Dolibarr */
    protected $dstElement = 'stock';

    /**
     * Ne purger que les sous-entrepôts hérités du modèle abandonné.
     *
     * Posée par l'option `--legacy` du lanceur. Sans elle, la purge emporte aussi
     * l'entrepôt du dépôt, qui porte désormais la totalité du stock et auquel tous les
     * emplacements du dictionnaire sont rattachés — ce n'est presque jamais ce qu'on veut.
     *
     * @var bool
     */
    public $legacyOnly = false;

    // ── Index chargés au démarrage ─────────────────────────────────────────

    /** @var array<string,int> Libellé normalisé -> rowid d'entrepôt existant */
    protected $warehouseByLabel = array();

    // ── Compteurs de rapport ───────────────────────────────────────────────

    /** @var int Entrepôts créés */
    protected $created = 0;

    /** @var int Entrepôts déjà présents, adoptés et marqués */
    protected $adopted = 0;

    /** @var array<int,string> Libellés des dépôts traités, par numéro */
    protected $processed = array();

    /**
     * Charge l'index des entrepôts existants, par libellé.
     *
     * Tous sont indexés, pas seulement ceux de la reprise : c'est ce qui permet d'adopter
     * l'entrepôt de la boutique, créé avant elle et sans marqueur.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function prepare()
    {
        $sql  = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->warehouseByLabel[$this->labelKey($obj->ref)] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Clé de comparaison d'un libellé d'entrepôt.
     *
     * Calquée sur la collation de `uk_entrepot_label` : insensible à la casse et aux
     * accents. La reproduire fidèlement évite de croire créer un entrepôt que la base
     * refusera comme doublon.
     *
     * @param  string $label Libellé
     * @return string        Clé de comparaison
     */
    protected function labelKey($label)
    {
        return strtolower(dol_string_unaccent(trim((string) $label)));
    }

    /**
     * Libellé retenu pour un dépôt.
     *
     * Dolibarr refuse un entrepôt sans nom ; le numéro d'origine sert de repli, ce qui le
     * rend au moins identifiable.
     *
     * @param  stdClass $row Ligne source
     * @return string        Libellé
     */
    protected function resolveLabel($row)
    {
        $label = trim((string) $row->DE_Intitule);

        return ($label !== '') ? $label : ('Dépôt '.((int) $row->DE_No));
    }

    /**
     * Clé source d'un dépôt.
     *
     * Préfixée « DEPOT » plutôt que réduite au numéro : `SAGE:1` ne dirait pas de quoi il
     * s'agit dans une base où d'autres reprises posent leurs propres marqueurs.
     *
     * @param  stdClass $row Ligne source
     * @return string        Clé source
     */
    protected function getSourceKey($row)
    {
        return 'DEPOT'.((int) $row->DE_No);
    }

    /**
     * L'index d'idempotence porte sur import_key, la table n'ayant pas de ref_ext.
     *
     * @return int Nombre d'entrées chargées, -1 en cas d'erreur SQL
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();

        $sql  = 'SELECT rowid, import_key FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->migratedIndex[$obj->import_key] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return count($this->migratedIndex);
    }

    /**
     * Nombre d'entrepôts déjà repris.
     *
     * @return int Nombre d'entrepôts, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Vérifie qu'une ligne source est exploitable.
     *
     * @param  stdClass $row Ligne source
     * @return void
     * @throws Exception Si le numéro de dépôt est absent
     */
    protected function validateRow($row)
    {
        if ((int) $row->DE_No <= 0) {
            throw new Exception('Numéro de dépôt absent');
        }
    }

    /**
     * Action que produirait cette ligne, en simulation.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId rowid de l'entrepôt déjà repris, 0 sinon
     * @return string               created, adopted ou skipped
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'skipped';
        }

        $label = $this->resolveLabel($row);
        $this->processed[(int) $row->DE_No] = $label;

        if (isset($this->warehouseByLabel[$this->labelKey($label)])) {
            $this->adopted++;
            return 'adopted';
        }

        $this->created++;

        return 'created';
    }

    /**
     * Crée ou adopte l'entrepôt d'un dépôt.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId rowid de l'entrepôt déjà repris, 0 sinon
     * @return array{action:string,id:int}
     * @throws Exception En cas d'échec de création ou de marquage
     */
    protected function migrateRow($row, $existingId)
    {
        $label = $this->resolveLabel($row);
        $this->processed[(int) $row->DE_No] = $label;

        // Déjà repris : rien à refaire, le libellé et l'adresse appartiennent au client.
        if ($existingId > 0) {
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // Un entrepôt porte déjà ce nom sans venir de la reprise — celui de la boutique,
        // typiquement. On l'adopte plutôt que d'échouer sur l'unicité du libellé.
        $key = $this->labelKey($label);
        if (isset($this->warehouseByLabel[$key])) {
            $id = $this->warehouseByLabel[$key];
            if ($this->stampWarehouse($id, $row) <= 0) {
                throw new Exception('Marquage impossible sur l\'entrepôt #'.$id);
            }
            $this->adopted++;

            return array('action' => 'adopted', 'id' => $id);
        }

        $entrepot = new Entrepot($this->db);

        $entrepot->label            = $label;
        $entrepot->statut           = Entrepot::STATUS_OPEN_ALL;
        $entrepot->warehouse_usage  = Entrepot::USAGE_INTERNAL;
        $entrepot->user_creation_id = (int) $this->user->id;
        $entrepot->import_key       = $this->buildRefExt($this->getSourceKey($row));
        $entrepot->description      = 'Dépôt repris de l\'ancien ERP (ADD), n°'.((int) $row->DE_No);

        $entrepot->lieu    = trim((string) $row->DE_Ville);
        $entrepot->address = trim((string) $row->DE_Adresse);
        $complement        = trim((string) $row->DE_Complement);
        if ($complement !== '') {
            $entrepot->address .= "\n".$complement;
        }
        $entrepot->zip          = trim((string) $row->DE_CodePostal);
        $entrepot->town         = trim((string) $row->DE_Ville);
        $entrepot->country_code = $this->resolveCountryCode($row->DE_Pays);
        $entrepot->phone        = trim((string) $row->DE_Telephone);
        $entrepot->fax          = trim((string) $row->DE_Telecopie);

        if ($entrepot->create($this->user) <= 0) {
            throw new Exception('Création impossible : '.$this->objectErrors($entrepot));
        }

        $this->warehouseByLabel[$key] = (int) $entrepot->id;
        $this->created++;

        return array('action' => 'created', 'id' => (int) $entrepot->id);
    }

    /**
     * Pose le marqueur de reprise sur un entrepôt adopté.
     *
     * Sans lui, l'adoption reste invisible : l'entrepôt fait office de dépôt pendant ce
     * passage, mais rien ne le dit en base, et les scripts suivants ne le retrouveraient
     * pas. C'est arrivé en production, où l'entrepôt de la boutique préexistait.
     *
     * Un marqueur étranger n'est jamais écrasé : s'il y en a déjà un, il désigne un autre
     * dépôt et le remplacer ferait disparaître ce rattachement.
     *
     * `Entrepot::update()` réécrit toutes les colonnes depuis l'objet, ce qui n'est neutre
     * que parce que `fetch()` les charge toutes. Les triggers sont en revanche désactivés :
     * poser un marqueur technique n'est pas une modification métier, et `WAREHOUSE_MODIFY`
     * déclencherait une synchronisation vers la boutique pour rien.
     *
     * @param  int      $id  rowid de l'entrepôt
     * @param  stdClass $row Ligne source
     * @return int           1 si le marqueur est en place, -1 en cas d'échec
     */
    protected function stampWarehouse($id, $row)
    {
        $entrepot = new Entrepot($this->db);
        if ($entrepot->fetch((int) $id) <= 0) {
            return -1;
        }

        $wanted = $this->buildRefExt($this->getSourceKey($row));

        if ((string) $entrepot->import_key === $wanted) {
            return 1;
        }
        if (!empty($entrepot->import_key)) {
            $this->errors[] = array(
                'key'     => (string) $row->DE_No,
                'message' => 'Entrepôt « '.$entrepot->ref.' » déjà marqué '.$entrepot->import_key
                    .' : marqueur conservé, le dépôt '.((int) $row->DE_No).' n\'a pas été rattaché.',
            );
            return -1;
        }

        $entrepot->import_key = $wanted;

        // Les champs vides sont complétés depuis la source ; ceux que le client a
        // renseignés ne sont pas touchés.
        if (trim((string) $entrepot->address) === '') {
            $entrepot->address = trim((string) $row->DE_Adresse);
        }
        if (trim((string) $entrepot->zip) === '') {
            $entrepot->zip = trim((string) $row->DE_CodePostal);
        }
        if (trim((string) $entrepot->town) === '') {
            $entrepot->town = trim((string) $row->DE_Ville);
        }
        if (trim((string) $entrepot->phone) === '') {
            $entrepot->phone = trim((string) $row->DE_Telephone);
        }

        return ($entrepot->update((int) $id, $this->user, 1) > 0) ? 1 : -1;
    }

    /**
     * Code ISO du pays du dépôt.
     *
     * La source écrit le pays en clair. Un seul dépôt est concerné : une correspondance
     * complète serait disproportionnée, seul le cas français est traité, les autres
     * laissant le pays vide.
     *
     * @param  string $label Libellé du pays
     * @return string        Code ISO à deux lettres, chaîne vide si non reconnu
     */
    protected function resolveCountryCode($label)
    {
        return ($this->labelKey($label) === 'france') ? 'FR' : '';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        $n = $this->countPurgeable();

        // L'avertissement compte plus que le décompte. Entrepot::delete() ne vérifie pas
        // que l'entrepôt est vide : il supprime lui-même product_batch, stock_mouvement et
        // product_stock (entrepot.class.php:458-481), puis l'entrepôt. Tout stock resté là
        // disparaît sans un mot, et son historique de mouvements avec.
        $warning  = "ATTENTION : supprimer un entrepôt emporte SON STOCK et SES MOUVEMENTS.\n";
        $warning .= "Dolibarr ne refuse rien et n'avertit pas — il supprime product_stock et\n";
        $warning .= "stock_mouvement avant l'entrepôt. Vérifiez qu'ils sont vides d'abord :\n";
        $warning .= "« migrate.php stock » rapatrie dans le dépôt le stock des produits qu'il\n";
        $warning .= "lit, et son rapport signale ce qui reste ailleurs.\n";

        if ($this->legacyOnly) {
            $out  = "Supprime les ".($n < 0 ? '?' : $n)." sous-entrepôt(s) hérité(s) du modèle\n";
            $out .= "où chaque emplacement était un entrepôt. L'entrepôt du dépôt est conservé.\n\n";
            $out .= $warning;

            return $out;
        }

        $out  = "Supprime les entrepôts portant un marqueur de reprise ("
            .($n < 0 ? '?' : $n)."), y compris celui du dépôt.\n\n";
        $out .= $warning."\n";
        $out .= "Les emplacements du dictionnaire rattachés à ces entrepôts ne sont pas\n";
        $out .= "supprimés et garderaient un rattachement vers un entrepôt disparu :\n";
        $out .= "purgez-les avec purge.php location.\n\n";
        $out .= "Pour ne défaire que les sous-entrepôts du modèle abandonné, sans toucher au\n";
        $out .= "dépôt qui porte le stock : purge.php warehouse --legacy";

        return $out;
    }

    /**
     * Condition SQL désignant ce que la purge doit supprimer.
     *
     * @return string Clause SQL, sans le mot-clé WHERE
     */
    protected function purgeCondition()
    {
        $sql  = ' entity IN ('.getEntity($this->dstElement).')';
        $sql .= " AND import_key LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        // Un vestige se reconnaît à son parent : le dépôt est toujours à la racine.
        if ($this->legacyOnly) {
            $sql .= ' AND fk_parent > 0';
        }

        return $sql;
    }

    /**
     * Nombre d'entrepôts que la purge supprimerait.
     *
     * @return int Nombre d'entrepôts, -1 si le comptage échoue
     */
    protected function countPurgeable()
    {
        $resql = $this->db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable
            .' WHERE'.$this->purgeCondition(), 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Supprime les entrepôts créés par la reprise.
     *
     * @param  bool          $confirm  Faux pour un simple décompte
     * @param  callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        // Les enfants d'abord : un entrepôt parent ne se supprime pas tant qu'il en porte.
        // L'ancien modèle en comptait sept cents, et une instance reprise avant la refonte
        // les porte encore.
        $sql  = 'SELECT rowid, ref, import_key FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE'.$this->purgeCondition();
        $sql .= ' ORDER BY (fk_parent IS NULL OR fk_parent = 0) ASC, rowid DESC';

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
            $entrepot = new Entrepot($this->db);
            if ($entrepot->fetch((int) $row->rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = $row->ref.' : chargement impossible';
                continue;
            }

            $this->db->begin();
            if ($entrepot->delete($this->user) > 0) {
                $this->db->commit();
                $result['deleted']++;
            } else {
                $this->db->rollback();
                $result['failed']++;
                $result['errors'][] = $row->ref.' : '.$this->objectErrors($entrepot);
            }

            if (is_callable($progress) && (($result['deleted'] + $result['failed']) % 200 === 0)) {
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

        if (!empty($this->processed)) {
            ksort($this->processed);
            $lines[] = 'Dépôts repris :';
            foreach ($this->processed as $no => $label) {
                $lines[] = '     n°'.str_pad((string) $no, 6).$label;
            }
        }

        if ($this->created > 0) {
            $lines[] = '';
            $lines[] = $this->created.' entrepôt(s) créé(s).';
        }
        if ($this->adopted > 0) {
            $lines[] = '';
            $lines[] = $this->adopted.' entrepôt(s) déjà présent(s), adopté(s) et marqué(s).';
            $lines[] = '  Le libellé et l\'adresse n\'ont pas été touchés ; seuls les champs vides';
            $lines[] = '  ont été complétés depuis la source.';
        }

        // Les sous-entrepôts d'un modèle abandonné : ils ne gênent pas la reprise, mais
        // encombrent tous les sélecteurs d'entrepôt tant qu'ils sont là.
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$this->dstTable;
        $sql .= ' WHERE entity IN ('.getEntity($this->dstElement).')';
        $sql .= ' AND fk_parent > 0';

        $resql = $this->db->query($sql, 1);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ((int) $obj->nb > 0) {
                $lines[] = '';
                $lines[] = 'Sous-entrepôts hérités : '.((int) $obj->nb);
                $lines[] = '  Vestiges du modèle où chaque emplacement était un entrepôt. Les';
                $lines[] = '  emplacements sont désormais portés par le dictionnaire et la fiche';
                $lines[] = '  produit : ces entrepôts n\'ont plus d\'objet.';
                $lines[] = '  Videz leur stock — « migrate.php stock » le rapatrie — puis';
                $lines[] = '  supprimez-les par « purge.php warehouse ».';
            }
        }

        $total = $this->countMigrated();
        if ($total >= 0) {
            $lines[] = '';
            $lines[] = 'Entrepôts portant un marqueur de reprise : '.$total;
        }

        return $lines;
    }
}
