<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationnewsletter.class.php
 * \ingroup aeromigration
 * \brief   Reprise des désinscriptions aux envois : f_comptet -> llx_mailing_unsubscribe.
 *
 * PRÉREQUIS : la reprise des tiers doit avoir été passée. Chaque désinscription est
 * appliquée au tiers Dolibarr correspondant, retrouvé par son `ref_ext`.
 *
 * Dolibarr ne stocke pas cette information sur la fiche : la colonne `no_email` de
 * `llx_socpeople` est marquée « no more used » dans le coeur. La désinscription est une
 * simple liste d'adresses, `llx_mailing_unsubscribe`, consultée au moment des envois en
 * masse. C'est donc l'ADRESSE qui est désinscrite, pas le tiers — avec une conséquence à
 * connaître : 9 adresses de la source sont partagées entre un tiers désinscrit et un
 * tiers qui ne l'est pas. Les désinscrire les retire des envois pour les deux, ce qui est
 * le comportement de Dolibarr et non un défaut de la reprise.
 *
 * L'écriture passe par Societe::setNoEmail(), qui vérifie l'existence avant d'insérer :
 * le script est donc naturellement rejouable.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationNewsletter extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'newsletter';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptNewsletter';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

    /** @var string Table source */
    protected $srcTable = 'f_comptet';

    /** @var string Colonne de parcours : la clé primaire de f_comptet */
    protected $srcCursorField = 'CT_Num';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du tiers dans la source */
    protected $srcKeyField = 'CT_Num';

    /**
     * Seuls les tiers désinscrits sont parcourus : 2 621 sur 157 102. Inutile de relire
     * toute la table pour ne rien faire de l'immense majorité.
     *
     * @var string
     */
    protected $srcWhere = "TRIM(CT_Num) <> '' AND Unsubscribe_Newsletter = 1";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'societe';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'societe';

    /** @var int Nombre de tiers sans adresse, donc non désinscriptibles */
    protected $withoutEmail = 0;

    /**
     * Description de la purge.
     *
     * @return string
     */
    /**
     * Nombre de désinscriptions déjà posées par la reprise.
     *
     * Ce script ne crée aucun objet : il inscrit des adresses dans la liste d'exclusion
     * des envois en masse. Le comptage porte donc sur les tiers désinscrits dans la source
     * dont l'adresse figure effectivement dans cette liste — même critère que la purge.
     *
     * @return int Nombre de désinscriptions, -1 si le comptage échoue
     */
    public function countMigrated()
    {
        // Le parcours part de la liste d'exclusion, qui compte quelques milliers de lignes,
        // et non des 157 000 tiers : la jointure sur f_comptet passerait par un
        // CONCAT() entre deux collations différentes, que MySQL ne sait pas indexer.
        // Mesuré : 665 ms contre 1 942, pour un résultat identique.
        $sql  = 'SELECT COUNT(DISTINCT u.email) as nb FROM '.MAIN_DB_PREFIX.'mailing_unsubscribe as u';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.email = u.email';
        $sql .= '   AND s.entity IN ('.getEntity('societe').')';
        $sql .= ' WHERE u.entity IN ('.getEntity('mailing', 0).')';
        $sql .= " AND s.ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    public function getPurgeDescription()
    {
        return 'Réinscription des tiers désinscrits par la reprise (table '
            .MAIN_DB_PREFIX.'mailing_unsubscribe)';
    }

    /**
     * Annule les désinscriptions posées par ce script.
     *
     * Le comportement par défaut du socle est ici inapplicable : ce script ne crée aucun
     * tiers, il enrichit ceux qui existent. Purger `llx_societe` les supprimerait tous.
     * Ce qu'il faut défaire, ce sont les lignes ajoutées à `llx_mailing_unsubscribe`, en
     * appelant `Societe::setNoEmail(0)` — le pendant exact de ce qu'a fait la reprise.
     *
     * Réserve : une adresse désinscrite à la main avant la reprise, sur un tiers que la
     * source considère aussi comme désinscrit, sera réinscrite. Rien ne permet de
     * distinguer les deux origines, cette table ne portant aucun marqueur.
     *
     * @param bool          $confirm  false pour dénombrer sans rien supprimer
     * @param callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array<int,string>}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        // Les tiers que la source déclare désinscrits, et qui sont effectivement inscrits
        // sur la liste d'exclusion des envois.
        $sql  = 'SELECT s.rowid FROM '.MAIN_DB_PREFIX.'societe as s';
        $sql .= ' INNER JOIN '.$this->src('f_comptet').' as f ON CONCAT(\''.$this->db->escape($this->refExtPrefix).'\', f.CT_Num) = s.ref_ext';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mailing_unsubscribe as u ON u.email = s.email';
        $sql .= '   AND u.entity IN ('.getEntity('mailing', 0).')';
        $sql .= ' WHERE s.entity IN ('.getEntity('societe').')';
        $sql .= ' AND f.Unsubscribe_Newsletter = 1';
        $sql .= " AND TRIM(COALESCE(s.email, '')) <> ''";

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
            $societe = new Societe($this->db);
            if ($societe->fetch($rowid) <= 0) {
                $result['failed']++;
                $result['errors'][] = 'rowid '.$rowid.' : chargement impossible';
                continue;
            }

            // setNoEmail(0) retire l'adresse de la liste d'exclusion.
            if ($societe->setNoEmail(0) < 0) {
                $result['failed']++;
                $result['errors'][] = $societe->email.' : '.$this->objectErrors($societe);
                continue;
            }

            $result['deleted']++;

            if (is_callable($progress) && ($result['deleted'] % 200 === 0)) {
                call_user_func($progress, $result['deleted'], $result['count']);
            }
        }

        return $result;
    }

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
        // tous considérés comme « déjà migrés » et ignorés.
        $this->updateExisting = true;
    }

    /**
     * Applique la désinscription au tiers Dolibarr correspondant.
     *
     * @param stdClass $row        Ligne de f_comptet
     * @param int      $existingId rowid du tiers repris, 0 s'il est absent
     * @return array{action:string,id:int}
     * @throws Exception Si le tiers est absent ou si l'écriture échoue
     */
    protected function migrateRow($row, $existingId)
    {
        if ($existingId <= 0) {
            throw new Exception('Tiers absent de la reprise : lancez d\'abord « migrate.php thirdparty »');
        }

        $societe = new Societe($this->db);
        if ($societe->fetch($existingId) <= 0) {
            throw new Exception('Tiers introuvable (rowid '.$existingId.') : '.$this->objectErrors($societe));
        }

        // setNoEmail() ne fait rien sans adresse : 4 tiers désinscrits n'en ont aucune,
        // leur désinscription n'est donc pas reprenable.
        if (empty($societe->email)) {
            $this->withoutEmail++;
            return array('action' => 'skipped', 'id' => $existingId);
        }

        // Vérifie l'existence avant d'insérer : rejouer le script n'ajoute rien.
        if ($societe->setNoEmail(1) < 0) {
            throw new Exception('Échec de la désinscription : '.$this->objectErrors($societe));
        }

        return array('action' => 'updated', 'id' => $existingId);
    }

    /**
     * Contrôle une ligne en simulation, sans rien écrire.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si le tiers n'est pas repris
     */
    protected function validateRow($row)
    {
        $refExt = $this->buildRefExt($this->getSourceKey($row));

        if (!isset($this->migratedIndex[$refExt])) {
            throw new Exception('Tiers absent de la reprise : lancez d\'abord « migrate.php thirdparty »');
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

        $sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'mailing_unsubscribe';
        $sql  .= ' WHERE entity IN ('.getEntity('mailing', 0).')';
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            $lines[] = 'Adresses désinscrites en base : '.$obj->nb;
        }

        if ($this->withoutEmail > 0) {
            $lines[] = '  '.str_pad((string) $this->withoutEmail, 6, ' ', STR_PAD_LEFT)
                .'  tiers désinscrits sans adresse e-mail : rien à reprendre pour eux';
        }

        return $lines;
    }
}
