<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationpricelevel.class.php
 * \ingroup aeromigration
 * \brief   Réalignement de la catégorie tarifaire des clients : f_comptet -> llx_societe.
 *
 * PRÉREQUIS : la reprise des tiers doit avoir été passée. Chaque client est retrouvé par
 * son `ref_ext`.
 *
 * ------------------------------------------------------------------------------
 * CE SCRIPT NE REPREND RIEN — IL CORRIGE
 * ------------------------------------------------------------------------------
 *
 * La première reprise des tiers a recopié `N_CatTarif` tel quel dans `price_level`. La
 * correspondance a depuis changé : les catégories 1 et 2 sont permutées, le tarif du site
 * devenant le niveau 1 (voir aeromigration_price_level(), qui en porte la justification).
 *
 * Sans ce script, **146 388 clients du site resteraient au niveau 2 et seraient facturés au
 * tarif comptoir**. Le symptôme serait d'autant plus vicieux qu'il ne produit aucune erreur :
 * juste un prix faux, sur presque tout le fichier client.
 *
 * ## Pourquoi un script à part, et non un rejeu de « thirdparty »
 *
 * `MigrationThirdparty` sait déjà poser la catégorie, et il a été corrigé pour passer par la
 * même fonction de correspondance : les deux ne peuvent donc pas diverger. Mais le rejouer
 * en `--update` referait, pour chacun des 157 000 tiers, un mapping complet et un
 * `Societe::update()` — des dizaines de requêtes par fiche, pour n'en changer qu'un entier.
 *
 * `Societe::setPriceLevel()` (societe.class.php:2949) n'a besoin que de `$this->id` : ni
 * `fetch()`, ni objet chargé. Deux requêtes par tiers, et seulement pour ceux qui changent.
 * L'écart mesuré se compte en heures.
 *
 * ## Le piège de la lecture
 *
 * `Societe::fetch()` REMPLACE un `price_level` vide par 1 dès que le multi-prix est actif
 * (societe.class.php:2231). Comparer le niveau cible à celui d'un objet chargé conclurait
 * donc que les tiers sans catégorie sont déjà au niveau 1, alors qu'ils n'ont rien en base.
 * Le niveau courant est relu en SQL, à sa valeur brute — même précaution que
 * `MigrationThirdparty::prepare()`.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
dol_include_once('/aeromigration/lib/aeromigration.lib.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationPriceLevel extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'pricelevel';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptPriceLevel';

    /** @var string Les tables Sage ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_comptet';

    /** @var string Colonnes lues */
    protected $srcFields = 'CT_Num, N_CatTarif';

    /** @var string Colonne de parcours : la clé primaire de f_comptet */
    protected $srcCursorField = 'CT_Num';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du tiers dans la source */
    protected $srcKeyField = 'CT_Num';

    /**
     * Seuls les clients porteurs d'une catégorie sont parcourus.
     *
     * Un tiers sans catégorie n'a rien à réaligner : la reprise ne lui en avait pas posé,
     * et `getSellPrice()` le fera retomber sur le prix de base, qui est précisément le
     * tarif du site. Le parcours passe ainsi de 157 000 lignes à 157 189.
     *
     * @var string
     */
    protected $srcWhere = "CT_Type = 0 AND TRIM(CT_Num) <> '' AND N_CatTarif > 0";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'societe';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'societe';

    /** @var array<int,int> Niveau brut actuellement en base, par rowid de tiers */
    protected $currentLevels = array();

    /** @var int Tiers de la source absents de la reprise */
    protected $missing = 0;

    /** @var int Tiers déjà au bon niveau */
    protected $alreadyRight = 0;

    /** @var array<int,int> Catégories source sans correspondance, avec leur effectif */
    protected $unknownCategories = array();

    /** @var array<string,int> Mouvements constatés, indexés « depuis→vers » */
    protected $moves = array();

    /**
     * Constructeur.
     *
     * @param DoliDB $db   Handler de base de données
     * @param User   $user Utilisateur exécutant la reprise
     */
    public function __construct($db, $user)
    {
        parent::__construct($db, $user);

        // Ce script ne crée rien : il agit sur des tiers déjà repris, qui seraient sinon
        // tous considérés comme « déjà migrés » et ignorés par le socle.
        $this->updateExisting = true;
    }

    /**
     * Charge le niveau de prix actuellement en base, à sa valeur brute.
     *
     * @return int 1 si la préparation aboutit, -1 sinon
     */
    protected function prepare()
    {
        $this->currentLevels = array();

        $sql  = 'SELECT rowid, price_level FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= ' WHERE entity IN ('.getEntity('societe').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->currentLevels[(int) $obj->rowid] = (int) $obj->price_level;
        }
        $this->db->free($resql);

        if (empty($this->currentLevels)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Aucun tiers repris en base : lancez d\'abord « migrate.php thirdparty »',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Nombre de clients déjà alignés sur la correspondance officielle.
     *
     * Ce script ne crée aucun objet : l'avancement se mesure au nombre de tiers dont le
     * niveau en base est celui que la source commande, et non à un comptage de lignes.
     *
     * @return int Nombre de tiers alignés, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        $cases = array();
        for ($cat = 1; $cat <= 8; $cat++) {
            $level = aeromigration_price_level($cat);
            if ($level > 0) {
                $cases[] = 'WHEN '.$cat.' THEN '.$level;
            }
        }

        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe as s';
        $sql .= ' INNER JOIN '.$this->src('f_comptet').' as f';
        $sql .= "   ON CONCAT('".$this->db->escape($this->refExtPrefix)."', f.CT_Num) = s.ref_ext";
        $sql .= ' WHERE s.entity IN ('.getEntity('societe').')';
        $sql .= ' AND f.CT_Type = 0 AND f.N_CatTarif > 0';
        $sql .= ' AND s.price_level = CASE f.N_CatTarif '.implode(' ', $cases).' ELSE 0 END';

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Aligne la catégorie tarifaire du tiers sur la correspondance officielle.
     *
     * @param stdClass $row        Ligne de f_comptet
     * @param int      $existingId rowid du tiers repris, 0 s'il est absent
     * @return array{action:string,id:int}
     * @throws Exception Si l'écriture échoue
     */
    protected function migrateRow($row, $existingId)
    {
        if ($existingId <= 0) {
            // Un client présent dans la source mais pas en cible relève de la reprise des
            // tiers, pas de celle-ci : le signaler suffit.
            $this->missing++;
            return array('action' => 'skipped', 'id' => 0);
        }

        $target = aeromigration_price_level($row->N_CatTarif);
        if ($target <= 0) {
            $cat = (int) $row->N_CatTarif;
            if (!isset($this->unknownCategories[$cat])) {
                $this->unknownCategories[$cat] = 0;
            }
            $this->unknownCategories[$cat]++;
            return array('action' => 'skipped', 'id' => $existingId);
        }

        $current = isset($this->currentLevels[$existingId]) ? $this->currentLevels[$existingId] : 0;

        if ($current === $target) {
            $this->alreadyRight++;
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // setPriceLevel() journalise le changement dans llx_societe_prices. C'est la raison
        // de ne l'appeler que lorsque le niveau change vraiment : sans cette garde, chaque
        // passage ajouterait 157 000 lignes d'historique sans qu'aucune valeur ne bouge.
        $societe     = new Societe($this->db);
        $societe->id = $existingId;

        if ($societe->setPriceLevel($target, $this->user) < 0) {
            throw new Exception('Échec du positionnement de la catégorie tarifaire (rowid '.$existingId.')');
        }

        $this->currentLevels[$existingId] = $target;

        $move = $current.'→'.$target;
        if (!isset($this->moves[$move])) {
            $this->moves[$move] = 0;
        }
        $this->moves[$move]++;

        return array('action' => 'updated', 'id' => $existingId);
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la catégorie source n'a pas de correspondance
     */
    protected function validateRow($row)
    {
        if (aeromigration_price_level($row->N_CatTarif) <= 0) {
            throw new Exception('Catégorie tarifaire sans correspondance : '.(int) $row->N_CatTarif);
        }
    }

    /**
     * Verdict d'une ligne en simulation.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du tiers repris, 0 s'il est absent
     * @return string Action que le passage réel produirait
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId <= 0) {
            $this->missing++;
            return 'skipped';
        }

        $target  = aeromigration_price_level($row->N_CatTarif);
        $current = isset($this->currentLevels[$existingId]) ? $this->currentLevels[$existingId] : 0;

        if ($target <= 0 || $current === $target) {
            if ($current === $target) {
                $this->alreadyRight++;
            }
            return 'skipped';
        }

        $move = $current.'→'.$target;
        if (!isset($this->moves[$move])) {
            $this->moves[$move] = 0;
        }
        $this->moves[$move]++;

        return 'updated';
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        return 'Restauration de la catégorie tarifaire brute de l\'ancien ERP sur les tiers repris'
            .' (colonne price_level de '.MAIN_DB_PREFIX.'societe)';
    }

    /**
     * Rétablit la catégorie tarifaire telle que la source la porte, sans correspondance.
     *
     * Le comportement par défaut du socle est ici inapplicable : ce script ne crée aucun
     * tiers, et purger `llx_societe` les supprimerait tous. Ce qu'il faut défaire, c'est
     * l'inversion — donc remettre `price_level` sur la valeur brute de `N_CatTarif`, l'état
     * dans lequel la première reprise des tiers avait laissé la base.
     *
     * Réserve : un niveau posé à la main depuis la reprise sera écrasé. Rien ne permet de
     * distinguer les deux origines, `llx_societe` ne portant aucun marqueur pour ce champ.
     *
     * @param bool          $confirm  false pour dénombrer sans rien écrire
     * @param callable|null $progress Rappel de progression, reçoit ($traites, $total)
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $sql  = 'SELECT s.rowid, s.price_level, f.N_CatTarif FROM '.MAIN_DB_PREFIX.'societe as s';
        $sql .= ' INNER JOIN '.$this->src('f_comptet').' as f';
        $sql .= "   ON CONCAT('".$this->db->escape($this->refExtPrefix)."', f.CT_Num) = s.ref_ext";
        $sql .= ' WHERE s.entity IN ('.getEntity('societe').')';
        $sql .= ' AND f.CT_Type = 0 AND f.N_CatTarif > 0';
        $sql .= ' AND s.price_level <> f.N_CatTarif';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }

        $targets = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $targets[(int) $obj->rowid] = (int) $obj->N_CatTarif;
        }
        $this->db->free($resql);

        $result['count'] = count($targets);
        if (!$confirm || empty($targets)) {
            return $result;
        }

        foreach ($targets as $rowid => $level) {
            $societe     = new Societe($this->db);
            $societe->id = $rowid;

            if ($societe->setPriceLevel($level, $this->user) < 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : échec du positionnement';
                continue;
            }

            $result['deleted']++;

            if (is_callable($progress) && ($result['deleted'] % 500 === 0)) {
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

        if (!empty($this->moves)) {
            $lines[] = 'Mouvements de catégorie :';
            ksort($this->moves);
            foreach ($this->moves as $move => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 8, ' ', STR_PAD_LEFT).'  niveau '.$move;
            }
        }

        if ($this->alreadyRight > 0) {
            $lines[] = '  '.str_pad((string) $this->alreadyRight, 8, ' ', STR_PAD_LEFT)
                .'  tiers déjà au bon niveau';
        }

        if ($this->missing > 0) {
            $lines[] = '  '.str_pad((string) $this->missing, 8, ' ', STR_PAD_LEFT)
                .'  clients de la source absents de la reprise : relancez « migrate.php thirdparty »';
        }

        foreach ($this->unknownCategories as $cat => $nb) {
            $lines[] = '  '.str_pad((string) $nb, 8, ' ', STR_PAD_LEFT)
                .'  clients en catégorie '.$cat.', sans correspondance : niveau laissé en l\'état';
        }

        // État final, tel que l'écran des tiers l'affichera.
        $sql  = 'SELECT price_level, COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= ' WHERE entity IN ('.getEntity('societe').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";
        $sql .= ' GROUP BY price_level ORDER BY price_level';

        $resql = $this->db->query($sql);
        if ($resql) {
            $lines[] = 'Répartition des tiers repris, par niveau :';
            while ($obj = $this->db->fetch_object($resql)) {
                $level = (int) $obj->price_level;
                $label = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$level);
                $lines[] = '  '.str_pad((string) $obj->nb, 8, ' ', STR_PAD_LEFT)
                    .'  niveau '.($level > 0 ? $level : '-')
                    .($label !== '' ? ' — '.$label : '');
            }
            $this->db->free($resql);
        }

        return $lines;
    }
}
