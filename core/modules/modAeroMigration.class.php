<?php
/* Copyright (C) 2026 Progiseize */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module AeroMigration.
 *
 * Module technique et temporaire : il héberge les scripts de reprise de données depuis
 * l'ancien ERP (Sage 100) vers Dolibarr. Les données sources sont les tables « f_* »
 * présentes dans la même base que Dolibarr (f_comptet, f_article, f_docentete_global…).
 *
 * Principe retenu : les scripts n'écrivent JAMAIS en SQL direct dans les tables llx_*.
 * Ils instancient les objets métier Dolibarr (Societe, Contact, Product, Commande…) et
 * appellent leurs méthodes create()/update(). On conserve ainsi les règles de gestion,
 * les extrafields, les numérotations et les triggers du coeur.
 *
 * Ce module n'ajoute aucune table ni aucun champ : il ne contient que de l'outillage.
 * Il a vocation à être désactivé une fois la reprise de données terminée.
 */
class modAeroMigration extends DolibarrModules
{
    /**
     * Constructeur.
     *
     * @param DoliDB $db Handler de base de données
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        $this->numero          = 399998;
        $this->family          = 'Progiseize';
        $this->module_position = '91';
        $this->name            = preg_replace('/^mod/i', '', get_class($this));
        $this->description     = 'Reprise de données de l\'ancien ERP vers Dolibarr';
        $this->version         = '0.8.1';
        $this->const_name      = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto           = 'fa-database_fas_#1A3085';

        $this->editor_name     = 'Progiseize';
        $this->editor_url      = 'https://progiseize.fr';

        // Aucun hook, aucun trigger, aucun modèle : le module n'intervient pas dans le
        // fonctionnement de Dolibarr. Il n'expose que des scripts lancés à la demande.
        $this->module_parts = array();

        // Aucun répertoire de travail à créer à l'activation.
        $this->dirs = array();

        // Point d'entrée du module : Configuration > Modules > AeroMigration > Configurer.
        $this->config_page_url = array('setup.php@aeromigration');

        // Aucune tâche planifiée : les reprises se lancent manuellement, jamais en cron.
        $this->cronjobs = array();

        $this->depends      = array();
        $this->requiredby   = array();
        $this->conflictwith = array();
        $this->langfiles    = array('aeromigration@aeromigration');
        $this->phpmin       = array(8, 0);

        // Le module ne crée aucune table : pas de fichiers dans sql/, donc pas d'appel
        // à _load_tables() dans init().

        // ── Permissions ────────────────────────────────────────────────────
        // Volontairement restreint : consulter l'état de la reprise d'une part, lancer
        // une reprise d'autre part. L'exécution modifie des données métier en masse et
        // ne doit être ouverte qu'aux administrateurs de la reprise.
        $this->rights_class = 'aeromigration';
        $this->rights       = array();
        $r = 0;
        $this->rights[$r][0] = 39999801;
        $this->rights[$r][1] = 'Consulter l\'état de la reprise de données';
        $this->rights[$r][4] = 'migration';
        $this->rights[$r][5] = 'read';
        $r++;
        $this->rights[$r][0] = 39999802;
        $this->rights[$r][1] = 'Lancer un script de reprise de données';
        $this->rights[$r][4] = 'migration';
        $this->rights[$r][5] = 'run';
        $r++;

        // Pas d'entrée de menu : le module est un outil d'administration, accessible
        // depuis sa page de configuration. À compléter si une page de suivi dédiée
        // devient nécessaire.
        $this->menu = array();

        // Aucun onglet greffé sur les fiches existantes.
        $this->tabs = array();

        // Aucun dictionnaire.
        $this->dictionaries = array();
    }

    /**
     * Activation du module.
     *
     * Complète au passage le dictionnaire des types de tiers avec les valeurs dont la
     * reprise a besoin. Le faire ici plutôt qu'à la main garantit qu'elles seront
     * présentes en production sans intervention.
     *
     * @param string $options Options
     * @return int            1 si OK, <=0 si KO
     */
    public function init($options = '')
    {
        $result = $this->_init(array(), $options);
        if ($result <= 0) {
            return $result;
        }

        return $this->createTypentEntries();
    }

    /**
     * Ajoute au dictionnaire des types de tiers les valeurs absentes du socle Dolibarr.
     *
     * Le référentiel natif ne couvre pas les natures rencontrées dans les données
     * reprises : la source distingue les sociétés, les aéro-clubs et les établissements,
     * là où Dolibarr ne propose que TE_PRIVATE, TE_ADMIN, TE_SMALL et consorts.
     *
     * L'opération est idempotente : chaque code n'est créé que s'il est absent, donc
     * réactiver le module ne produit pas de doublon. Les identifiants sont attribués
     * au-delà de 200000 pour ne jamais entrer en conflit avec ceux qu'une future
     * version de Dolibarr ajouterait à son propre dictionnaire.
     *
     * Ces entrées ne sont volontairement pas supprimées à la désactivation du module :
     * des tiers y feraient référence, et c'est le comportement standard de Dolibarr pour
     * les dictionnaires.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    private function createTypentEntries()
    {
        $entries = array(
            'TE_SOCIETE'  => 'Société',
            'TE_AEROCLUB' => 'Aéro-club',
            'TE_ETAB'     => 'Établissement',
        );

        // Point de départ des identifiants : au-delà de la plage utilisée par Dolibarr
        // comme de celle déjà consommée par une éventuelle installation antérieure.
        $sql   = 'SELECT MAX(id) as maxid FROM '.MAIN_DB_PREFIX.'c_typent';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $obj      = $this->db->fetch_object($resql);
        $nextId   = max(200000, (int) $obj->maxid) + 1;
        $this->db->free($resql);

        foreach ($entries as $code => $libelle) {
            $sql   = 'SELECT id FROM '.MAIN_DB_PREFIX."c_typent WHERE code = '".$this->db->escape($code)."'";
            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->error = $this->db->lasterror();
                return -1;
            }

            $exists = ($this->db->num_rows($resql) > 0);
            $this->db->free($resql);

            if ($exists) {
                continue;
            }

            $sql  = 'INSERT INTO '.MAIN_DB_PREFIX.'c_typent (id, code, libelle, fk_country, module, position, active)';
            $sql .= " VALUES (".((int) $nextId).", '".$this->db->escape($code)."', '".$this->db->escape($libelle)."',";
            $sql .= " NULL, 'aeromigration', 0, 1)";

            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return -1;
            }

            dol_syslog('modAeroMigration : type de tiers '.$code.' ajouté au dictionnaire (id '.$nextId.')', LOG_NOTICE);
            $nextId++;
        }

        return 1;
    }

    /**
     * Désactivation du module.
     *
     * @param string $options Options
     * @return int            1 si OK, <=0 si KO
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
