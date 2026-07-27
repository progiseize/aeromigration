<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationthirdparty.class.php
 * \ingroup aeromigration
 * \brief   Reprise des tiers : f_comptet -> objets Societe de Dolibarr.
 *
 * La source distingue les tiers par CT_Type : 0 pour les clients, 1 pour les
 * fournisseurs. Le script traite les deux en un seul passage ; restreindre à l'un ou
 * l'autre se fait via la propriété $srcWhere.
 *
 * Le mapping des champs est volontairement isolé dans mapFields() : c'est le seul
 * endroit à faire évoluer quand le tableau de correspondance sera arrêté. Tout le reste
 * (lots, reprise, idempotence, transactions) est pris en charge par le socle.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationThirdparty extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'thirdparty';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptThirdparty';

    /** @var string Table source */
    protected $srcTable = 'f_comptet';

    /**
     * Colonne de parcours : la clé primaire de f_comptet.
     *
     * La colonne technique cbMarq n'est pas utilisable ici, elle vaut 0 sur la totalité
     * des lignes de cette table. CT_Num est en revanche la PRIMARY KEY : unique, indexée
     * et totalement ordonnée, donc parfaite pour une pagination par curseur.
     *
     * @var string
     */
    protected $srcCursorField = 'CT_Num';

    /** @var string Le curseur est ici une chaîne */
    protected $srcCursorType = 'string';

    /** @var string Clé naturelle du tiers dans la source */
    protected $srcKeyField = 'CT_Num';

    /**
     * La source contient une ligne technique entièrement vide (ni code, ni libellé).
     * On l'écarte ici plutôt que de la laisser remonter en erreur à chaque passage, ce
     * qui masquerait les anomalies réelles.
     *
     * @var string
     */
    protected $srcWhere = "TRIM(CT_Num) <> ''";

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'societe';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'societe';

    /** @var array<string,int> Libellé de pays normalisé -> rowid de llx_c_country */
    protected $countryByLabel = array();

    /** @var array<string,int> Code ISO -> rowid de llx_c_country */
    protected $countryByCode = array();

    /** @var array<string,int> Code département français -> rowid de llx_c_departements */
    protected $departementByCode = array();

    /** @var int rowid de la France, pour conditionner la déduction du département */
    protected $franceCountryId = 0;

    /** @var array<string,int> Valeurs de CT_Pays non résolues, avec leur nombre d'occurrences */
    protected $unresolvedCountries = array();

    /** @var array<string,int> Code de type de tiers -> id de llx_c_typent */
    protected $typentByCode = array();

    /** @var array<string,int> Valeurs de CT_Qualite non résolues, avec leur nombre d'occurrences */
    protected $unresolvedQualities = array();

    /** @var array<string,bool> Codes devise déclarés dans l'entité (llx_multicurrency) */
    protected $declaredCurrencies = array();

    /** @var array<string,int> Devises non exploitables, avec leur nombre d'occurrences */
    protected $unresolvedCurrencies = array();

    /**
     * Correspondance des codes devise Sage vers les codes ISO.
     *
     * La table des devises de l'ancien ERP n'a pas été importée : les codes ont été
     * déduits des pays des tiers concernés, la répartition ne laissant guère de doute.
     *
     *   1  → zone euro, France comprise : c'est la devise de tenue        (37 tiers)
     *   2  → États-Unis, Canada, Chine, Hong-Kong, Australie              (43 tiers)
     *   4  → Royaume-Uni sous toutes ses graphies                         (16 tiers)
     *   11 → Canada, sur un tiers isolé                                   (1 tier)
     *
     * @var array<int,string>
     */
    protected $currencyAliases = array(
        1  => 'EUR',
        2  => 'USD',
        4  => 'GBP',
        11 => 'CAD',
    );

    /**
     * Correspondance des qualités Sage vers les codes du dictionnaire des types de tiers.
     *
     * `CT_Qualite` sert de civilité pour les particuliers et de nature juridique pour les
     * personnes morales : c'est le seul champ de la source permettant de distinguer les
     * deux, et 94 % des tiers sont des particuliers.
     *
     * Les clés sont normalisées comme pour les pays, ce qui regroupe les variantes de
     * forme (« Monsieur » et « M », « Aéro-club » et « Aéroclub », « Mr. » et « mr »).
     *
     * TE_SOCIETE, TE_AEROCLUB et TE_ETAB sont ajoutés au dictionnaire à l'activation du
     * module (voir modAeroMigration::createTypentEntries).
     *
     * @var array<string,string>
     */
    protected $qualityAliases = array(
        'monsieur'         => 'TE_PRIVATE',
        'm'                => 'TE_PRIVATE',
        'mr'               => 'TE_PRIVATE',
        'mme'              => 'TE_PRIVATE',
        'madame'           => 'TE_PRIVATE',
        'mademoiselle'     => 'TE_PRIVATE',
        'monsieur madame'  => 'TE_PRIVATE',
        'societe'          => 'TE_SOCIETE',
        'entreprise'       => 'TE_SOCIETE',
        'aero club'        => 'TE_AEROCLUB',
        'aeroclub'         => 'TE_AEROCLUB',
        'etablissement'    => 'TE_ETAB',
        'administration'   => 'TE_ADMIN',
        'association'      => 'TE_OTHER',
    );

    /**
     * Correspondance des libellés de pays saisis dans Sage vers les codes ISO.
     *
     * Le référentiel Dolibarr (llx_c_country) stocke ses libellés en anglais et les
     * traduit à l'affichage, alors que la source contient du français saisi librement.
     * Les clés sont NORMALISÉES (minuscules, sans accent, ponctuation réduite à des
     * espaces) : une seule entrée absorbe donc toutes les variantes de forme —
     * « Nouvelle Calédonie » et « Nouvelle-Calédonie », « Pays-Bas » et « Pays bas »,
     * « Côte d'Ivoire » et « Côte D’Ivoire ».
     *
     * Les libellés déjà identiques à ceux de Dolibarr (France, Portugal, Canada…) ne
     * figurent pas ici : ils sont résolus par le rapprochement direct.
     *
     * @var array<string,string>
     */
    protected $countryAliases = array(
        'belgique'                        => 'BE',
        'suisse'                          => 'CH',
        'la reunion'                      => 'RE',
        'reunion'                         => 'RE',
        'polynesie francaise'             => 'PF',
        'allemagne'                       => 'DE',
        'espagne'                         => 'ES',
        'espana'                          => 'ES',
        'nouvelle caledonie'              => 'NC',
        'italie'                          => 'IT',
        'italia'                          => 'IT',
        'guyane'                          => 'GF',
        'guyane francaise'                => 'GF',
        'grande bretagne'                 => 'GB',
        'angleterre'                      => 'GB',
        'royaume uni'                     => 'GB',
        'england'                         => 'GB',
        'britain'                         => 'GB',
        'uk'                              => 'GB',
        'maroc'                           => 'MA',
        'etats unis'                      => 'US',
        'algerie'                         => 'DZ',
        'pays bas'                        => 'NL',
        'nederland'                       => 'NL',
        'pologne'                         => 'PL',
        'autriche'                        => 'AT',
        'grece'                           => 'GR',
        'roumanie'                        => 'RO',
        'andorre'                         => 'AD',
        'saint pierre et miquelon'        => 'PM',
        'suede'                           => 'SE',
        'irlande'                         => 'IE',
        'danemark'                        => 'DK',
        'emirats arabes unis'             => 'AE',
        'cote ivoire'                     => 'CI',
        'cote d ivoire'                   => 'CI',
        'republique tcheque'              => 'CZ',
        'wallis et futuna'                => 'WF',
        'hong kong'                       => 'HK',
        'finlande'                        => 'FI',
        'norvege'                         => 'NO',
        'norge'                           => 'NO',
        'tunisie'                         => 'TN',
        'russie'                          => 'RU',
        'bulgarie'                        => 'BG',
        'hongrie'                         => 'HU',
        'ile maurice'                     => 'MU',
        'chine'                           => 'CN',
        'inde'                            => 'IN',
        'slovaquie'                       => 'SK',
        'chypre'                          => 'CY',
        'tchad'                           => 'TD',
        'lettonie'                        => 'LV',
        'colombie'                        => 'CO',
        'saint martin france'             => 'MF',
        'saint martin partie francaise'   => 'MF',
        'slovenie'                        => 'SI',
        'liban'                           => 'LB',
        'arabie saoudite'                 => 'SA',
        'republique du congo'             => 'CG',
        'rebublique du congo'             => 'CG', // faute de frappe présente dans la source
        'singapour'                       => 'SG',
        'japon'                           => 'JP',
        'republique democratique du congo' => 'CD',
        'congo kinshasa'                  => 'CD',
        'viet nam'                        => 'VN',
        'malte'                           => 'MT',
        'kuwait'                          => 'KW',
        'lituanie'                        => 'LT',
        'bresil'                          => 'BR',
        'croatie'                         => 'HR',
        'estonie'                         => 'EE',
        'quatar'                          => 'QA',
        'ethiopie'                        => 'ET',
        'serbie'                          => 'RS',
        'guadeloupe 2'                    => 'GP',
        'cap vert'                        => 'CV',
        'jordanie'                        => 'JO',
        'iles comores'                    => 'KM',
        'islande'                         => 'IS',
        'bahrein'                         => 'BH',
        'mongolie'                        => 'MN',
        'central african republic'        => 'CF',
        'mauritanie'                      => 'MR',
        'turquie'                         => 'TR',
        'guinee equatoriale'              => 'GQ',
        // Volontairement absents : décision actée de laisser le pays vide pour ces
        // libellés, qui concernent 22 tiers sur 157 102. Ils continuent d'être listés
        // dans le rapport de fin de passage, ce qui permet de les traiter à la main
        // dans Dolibarr si le besoin s'en fait sentir.
        //   « Antilles françaises » (18 tiers) : ambigu, Guadeloupe ou Martinique
        //   « ARMEES » (2), « SP 55047 » (1)   : valeurs hors référentiel
        //   « CÃTE D » (1)                     : libellé corrompu à la source
    );

    /**
     * Charge les référentiels pays et départements.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function prepare()
    {
        // ── Pays ───────────────────────────────────────────────────────────
        $sql   = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.'c_country';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->countryByCode[$obj->code] = (int) $obj->rowid;
            $label = $this->normalizeLabel($obj->label);
            if ($label !== '') {
                $this->countryByLabel[$label] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        $this->franceCountryId = isset($this->countryByCode['FR']) ? $this->countryByCode['FR'] : 0;

        // ── Départements français ──────────────────────────────────────────
        // Le filtre sur le pays est indispensable : llx_c_departements contient les
        // subdivisions de nombreux pays et le code « 01 » désigne aussi bien l'Ain
        // qu'Anvers ou Adrar. La jointure se fait sur code_region, pas sur rowid.
        $sql  = 'SELECT d.rowid, d.code_departement FROM '.MAIN_DB_PREFIX.'c_departements as d';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_regions as r ON r.code_region = d.fk_region';
        $sql .= ' WHERE r.fk_pays = '.((int) $this->franceCountryId);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->departementByCode[strtoupper($obj->code_departement)] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        // ── Types de tiers ─────────────────────────────────────────────────
        // Les identifiants sont relus depuis le dictionnaire plutôt que codés en dur :
        // ceux des entrées ajoutées par le module dépendent du contenu de la base au
        // moment de son activation.
        $sql   = 'SELECT id, code FROM '.MAIN_DB_PREFIX.'c_typent';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->typentByCode[$obj->code] = (int) $obj->id;
        }
        $this->db->free($resql);

        // Les trois codes propres au module doivent exister : sans eux, sociétés,
        // aéro-clubs et établissements se retrouveraient sans type. Ils sont créés à
        // l'activation du module ; s'ils manquent, c'est que celle-ci n'a pas été
        // rejouée depuis leur ajout.
        foreach (array('TE_SOCIETE', 'TE_AEROCLUB', 'TE_ETAB') as $code) {
            if (!isset($this->typentByCode[$code])) {
                $this->errors[] = array(
                    'key'     => '',
                    'message' => 'Type de tiers '.$code.' absent du dictionnaire : désactivez puis réactivez le module AeroMigration',
                );
                return -1;
            }
        }

        // ── Devises déclarées dans l'entité ────────────────────────────────
        // Seules les devises présentes dans llx_multicurrency sont exploitables :
        // Societe::create() vide multicurrency_code sans rien signaler lorsque le code
        // ne s'y trouve pas. On veut au contraire savoir ce qui n'a pas pu être repris.
        $sql   = 'SELECT code FROM '.MAIN_DB_PREFIX.'multicurrency';
        $sql  .= ' WHERE entity IN ('.getEntity('multicurrency').')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->declaredCurrencies[$obj->code] = true;
        }
        $this->db->free($resql);

        return 1;
    }

    /**
     * Résout la devise du tiers à partir du code Sage.
     *
     * @param int $rawCode Valeur de N_Devise
     * @return string      Code ISO exploitable, chaîne vide si absente ou non déclarée
     */
    protected function resolveCurrency($rawCode)
    {
        $rawCode = (int) $rawCode;
        if ($rawCode <= 0) {
            return '';
        }

        if (!isset($this->currencyAliases[$rawCode])) {
            $label = 'code Sage '.$rawCode;
            if (!isset($this->unresolvedCurrencies[$label])) {
                $this->unresolvedCurrencies[$label] = 0;
            }
            $this->unresolvedCurrencies[$label]++;
            return '';
        }

        $iso = $this->currencyAliases[$rawCode];

        // Devise identifiée mais absente de llx_multicurrency : la reprendre serait
        // inutile puisque Dolibarr l'effacerait. On la signale pour que la devise
        // puisse être déclarée, puis la reprise rejouée en --update.
        if (!isset($this->declaredCurrencies[$iso])) {
            $label = $iso.' (non déclarée dans Configuration > Multidevise)';
            if (!isset($this->unresolvedCurrencies[$label])) {
                $this->unresolvedCurrencies[$label] = 0;
            }
            $this->unresolvedCurrencies[$label]++;
            return '';
        }

        return $iso;
    }

    /**
     * Rapport de fin de passage : libellés de pays non reconnus.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $lines = array();

        if (!empty($this->unresolvedCountries)) {
            arsort($this->unresolvedCountries);
            $lines[] = 'Pays non reconnus (tiers créés sans pays) :';
            foreach ($this->unresolvedCountries as $label => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 6, ' ', STR_PAD_LEFT).'  '.$label;
            }
        }

        if (!empty($this->unresolvedQualities)) {
            arsort($this->unresolvedQualities);
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Qualités non reconnues (tiers créés sans type) :';
            foreach ($this->unresolvedQualities as $label => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 6, ' ', STR_PAD_LEFT).'  '.$label;
            }
        }

        if (!empty($this->unresolvedCurrencies)) {
            arsort($this->unresolvedCurrencies);
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Devises non reprises (tiers créés sans devise) :';
            foreach ($this->unresolvedCurrencies as $label => $nb) {
                $lines[] = '  '.str_pad((string) $nb, 6, ' ', STR_PAD_LEFT).'  '.$label;
            }
        }

        return $lines;
    }

    /**
     * Normalise un libellé pour le rapprochement : minuscules, sans accent, ponctuation
     * et séparateurs ramenés à des espaces simples.
     *
     * C'est ce qui permet de traiter « Pays-Bas », « Pays bas » et « PAYS BAS » comme
     * une seule et même valeur.
     *
     * @param string $label Libellé brut
     * @return string       Libellé normalisé
     */
    protected function normalizeLabel($label)
    {
        $label = dol_string_unaccent((string) $label);
        $label = strtolower($label);
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label);

        return trim($label);
    }

    /**
     * Résout le pays Dolibarr à partir du libellé Sage.
     *
     * @param string $rawLabel Valeur de CT_Pays
     * @return int             rowid du pays, 0 si vide ou non reconnu
     */
    protected function resolveCountry($rawLabel)
    {
        $rawLabel = trim((string) $rawLabel);
        if ($rawLabel === '') {
            return 0;
        }

        $normalized = $this->normalizeLabel($rawLabel);
        if ($normalized === '') {
            return 0;
        }

        // 1. Correspondance explicite français -> code ISO.
        if (isset($this->countryAliases[$normalized])) {
            $code = $this->countryAliases[$normalized];
            if (isset($this->countryByCode[$code])) {
                return $this->countryByCode[$code];
            }
        }

        // 2. Rapprochement direct sur le libellé du référentiel.
        if (isset($this->countryByLabel[$normalized])) {
            return $this->countryByLabel[$normalized];
        }

        // 3. Non résolu : on laisse le pays vide et on le signale.
        if (!isset($this->unresolvedCountries[$rawLabel])) {
            $this->unresolvedCountries[$rawLabel] = 0;
        }
        $this->unresolvedCountries[$rawLabel]++;

        return 0;
    }

    /**
     * Résout le type de tiers à partir de la qualité Sage.
     *
     * @param string $rawLabel Valeur de CT_Qualite
     * @return int             id du type de tiers, 0 si vide ou non reconnu
     */
    protected function resolveTypent($rawLabel)
    {
        $rawLabel = trim((string) $rawLabel);
        if ($rawLabel === '') {
            return 0;
        }

        $normalized = $this->normalizeLabel($rawLabel);
        if ($normalized === '') {
            return 0;
        }

        if (isset($this->qualityAliases[$normalized])) {
            $code = $this->qualityAliases[$normalized];
            if (isset($this->typentByCode[$code])) {
                return $this->typentByCode[$code];
            }
        }

        // Qualités isolées et non décidables (« Head of Training », « Président »,
        // « Laboratoire compo »…) : le tiers reste sans type, et la valeur est signalée.
        if (!isset($this->unresolvedQualities[$rawLabel])) {
            $this->unresolvedQualities[$rawLabel] = 0;
        }
        $this->unresolvedQualities[$rawLabel]++;

        return 0;
    }

    /**
     * Déduit le département français à partir du code postal.
     *
     * Ne s'applique qu'aux adresses situées en France : les DOM étant repris comme des
     * pays distincts (Réunion, Guadeloupe…), leur rattacher un département français
     * serait incohérent.
     *
     * @param string $zip       Code postal
     * @param int    $countryId Pays résolu
     * @return int              rowid du département, 0 si indéterminable
     */
    protected function resolveDepartement($zip, $countryId)
    {
        if ($countryId <= 0 || $countryId !== $this->franceCountryId) {
            return 0;
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $zip);
        if (strlen($digits) < 4) {
            return 0;
        }

        // Les codes postaux français font 5 chiffres ; on complète un éventuel zéro de
        // tête perdu lors d'un export (ex : « 1000 » pour l'Ain).
        $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);
        $prefix = substr($digits, 0, 2);

        // La Corse n'a pas de code numérique : 2A pour la Corse-du-Sud, 2B pour la
        // Haute-Corse, départagées par la tranche de code postal.
        if ($prefix === '20') {
            $prefix = ((int) $digits <= 20199) ? '2A' : '2B';
        }

        return isset($this->departementByCode[$prefix]) ? $this->departementByCode[$prefix] : 0;
    }

    /**
     * Crée ou met à jour le tiers Dolibarr correspondant à une ligne de f_comptet.
     *
     * @param stdClass $row        Ligne de f_comptet
     * @param int      $existingId rowid du tiers déjà migré, 0 si création
     * @return array{action:string,id:int}
     * @throws Exception Si la création ou la mise à jour échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $societe = new Societe($this->db);

        if ($existingId > 0) {
            if ($societe->fetch($existingId) <= 0) {
                throw new Exception('Tiers introuvable (rowid '.$existingId.') : '.$this->objectErrors($societe));
            }

            // Niveau de prix déjà en base, relu par fetch() : sert à n'appeler
            // setPriceLevel() que s'il change réellement.
            $currentLevel = (int) $societe->price_level;

            $this->mapFields($societe, $row);

            // Les deux 1 autorisent la modification des codes client et fournisseur :
            // sans eux, update() ignore silencieusement le code repris de Sage.
            $result = $societe->update($existingId, $this->user, 1, 1, 1);
            if ($result <= 0) {
                throw new Exception('Échec de la mise à jour : '.$this->objectErrors($societe));
            }

            $this->applyPriceLevel($societe, (int) $row->N_CatTarif, $currentLevel);

            return array('action' => 'updated', 'id' => $existingId);
        }

        $this->mapFields($societe, $row);

        // Trace de l'origine : c'est ce champ qui rend le script rejouable.
        $societe->ref_ext = $this->buildRefExt($this->getSourceKey($row));

        $result = $societe->create($this->user);
        if ($result <= 0) {
            throw new Exception('Échec de la création : '.$this->objectErrors($societe));
        }

        $this->applyPriceLevel($societe, (int) $row->N_CatTarif, 0);

        return array('action' => 'created', 'id' => (int) $societe->id);
    }

    /**
     * Applique la catégorie tarifaire au tiers persisté.
     *
     * price_level n'est écrit ni par create() ni par update() : Dolibarr passe par
     * setPriceLevel(), qui met à jour le tiers et journalise le changement dans
     * llx_societe_prices. Cette journalisation impose de n'appeler la méthode que
     * lorsque le niveau change vraiment, sans quoi chaque passage en --update
     * ajouterait une ligne d'historique sans objet.
     *
     * @param Societe $societe      Tiers déjà enregistré
     * @param int     $target       Niveau issu de la source (N_CatTarif)
     * @param int     $currentLevel Niveau actuellement en base
     * @return void
     * @throws Exception Si l'écriture échoue
     */
    protected function applyPriceLevel(Societe $societe, $target, $currentLevel)
    {
        if ($target <= 0 || $societe->id <= 0 || $target === $currentLevel) {
            return;
        }

        if ($societe->setPriceLevel($target, $this->user) < 0) {
            throw new Exception('Échec du positionnement de la catégorie tarifaire');
        }
    }

    /**
     * Contrôle une ligne en simulation : applique le mapping sans rien persister.
     *
     * @param stdClass $row Ligne de f_comptet
     * @return void
     * @throws Exception Si la ligne ne permet pas de construire un tiers valide
     */
    protected function validateRow($row)
    {
        $societe = new Societe($this->db);
        $this->mapFields($societe, $row);
    }

    /**
     * Reporte les champs de la source sur l'objet Societe.
     *
     * ------------------------------------------------------------------------------
     * MAPPING PROVISOIRE — à compléter avec le tableau de correspondance définitif.
     *
     * Seuls les champs indispensables à la création d'un tiers valide sont renseignés
     * ici, de façon à pouvoir dérouler et mesurer le script de bout en bout. Les
     * champs restants (adresse, coordonnées, identifiants légaux, encours, catégorie
     * tarifaire, fidélité, RGPD…) seront ajoutés ensuite, sans toucher au reste du
     * module.
     *
     * Colonnes source disponibles, pour mémoire :
     *   Identité   CT_Intitule, CT_Qualite, CT_Nom, CT_Prenom, CT_Contact
     *   Adresse    CT_Adresse, CT_Complement, CT_CodePostal, CT_Ville, CT_Pays
     *   Contact    CT_Telephone, Portable, CT_Telecopie, CT_EMail, CT_Site
     *   Légal      CT_Siret, CT_Identifiant (TVA), CT_Ape, Capital_social
     *   Gestion    CT_Encours, N_CatTarif, CT_Sommeil, CT_DateCreate, CT_Commentaire
     *   Fidélité   num_carte_fid, Total_points_fidlit, Fin_validit_carte_fidlit
     *   RGPD       rgpd_mail, rgpd_sms, Unsubscribe_Newsletter
     * ------------------------------------------------------------------------------
     *
     * @param Societe  $societe Objet à alimenter
     * @param stdClass $row     Ligne de f_comptet
     * @return void
     * @throws Exception Si la ligne source ne permet pas de construire un tiers valide
     */
    protected function mapFields(Societe $societe, $row)
    {
        $name = trim((string) $row->CT_Intitule);
        if ($name === '') {
            // Un tiers sans libellé ne peut pas être créé : on se rabat sur le code
            // Sage plutôt que de perdre la ligne, et l'anomalie reste visible en base.
            $name = trim((string) $row->CT_Num);
        }
        if ($name === '') {
            throw new Exception('Ni CT_Intitule ni CT_Num exploitables');
        }
        $societe->name = $name;

        // CT_Type : 0 = client (156 712 lignes), 1 = fournisseur (390). Le discriminant
        // est fiable : la codification le confirme (tout fournisseur a un CT_Num en
        // « F… », aucun client n'en porte) et les comptes généraux suivent (411 pour les
        // clients, 401 pour les fournisseurs).
        //
        // Un compte source donne un tiers Dolibarr, et un seul : pas de fusion. 22
        // fournisseurs portent pourtant le même nom qu'un client, mais l'homonymie ne
        // prouve rien dans une source où « Air France » existe en quinze exemplaires.
        // Le dédoublonnage relève du métier, pas de la reprise ; Dolibarr propose un
        // outil de fusion de tiers pour le traiter au cas par cas ensuite.
        if ((int) $row->CT_Type === 1) {
            $societe->fournisseur = 1;
            $societe->client      = 0;
        } else {
            $societe->client      = 1;
            $societe->fournisseur = 0;
        }

        // Codes tiers : on reprend le code Sage tel quel plutôt que de laisser Dolibarr
        // en générer un nouveau (ce que ferait la valeur -1). La référence historique
        // reste ainsi lisible côté Dolibarr, et les rapprochements avec l'ancien ERP
        // restent possibles pendant toute la durée de la bascule.
        $code = trim((string) $row->CT_Num);
        if (!empty($societe->client)) {
            $societe->code_client = $code;
        }
        if (!empty($societe->fournisseur)) {
            $societe->code_fournisseur = $code;
        }

        // ── Adresse ────────────────────────────────────────────────────────
        // Dolibarr n'a qu'un champ d'adresse, multi-ligne : le complément Sage vient
        // donc sur une seconde ligne. Les longueurs maximales relevées dans la source
        // (169 caractères pour l'ensemble) tiennent sans troncature dans les 255
        // caractères de llx_societe.address.
        $adresse    = trim((string) $row->CT_Adresse);
        $complement = trim((string) $row->CT_Complement);

        if ($adresse !== '' && $complement !== '') {
            $societe->address = $adresse."\n".$complement;
        } else {
            $societe->address = ($adresse !== '') ? $adresse : $complement;
        }

        $societe->zip  = trim((string) $row->CT_CodePostal);
        $societe->town = trim((string) $row->CT_Ville);

        // Pays laissé vide lorsque la source ne le renseigne pas ou que le libellé
        // n'est pas reconnu ; les libellés inconnus sont remontés en fin de passage.
        $societe->country_id = $this->resolveCountry($row->CT_Pays);

        // Département déduit du code postal, pour la France uniquement.
        $societe->state_id = $this->resolveDepartement($societe->zip, $societe->country_id);

        // CT_CodeRegion est ignoré : renseigné sur 81 lignes seulement sur 157 103, et
        // sans correspondance directe avec le référentiel Dolibarr.

        // ── Coordonnées ────────────────────────────────────────────────────
        // Les numéros et adresses sont repris tels quels, sans filtrage : la source fait
        // foi, y compris pour ses valeurs de test (555-666-0606) et ses adresses
        // syntaxiquement invalides. Seule la mise en forme est normalisée.
        $societe->phone        = $this->formatPhone($row->CT_Telephone, $row->prefixe_telephone);
        $societe->phone_mobile = $this->formatPhone($row->Portable, $row->prefixe_portable);
        $societe->fax          = $this->formatPhone($row->CT_Telecopie, $row->prefixe_telecopie);
        $societe->email        = trim((string) $row->CT_EMail);
        $societe->url          = $this->formatUrl($row->CT_Site);

        // ── Identifiants légaux ────────────────────────────────────────────
        // Champs très peu renseignés dans la source : 41 SIRET, 16 codes APE et 133
        // « identifiants » pour 157 102 tiers. Capital_social n'est jamais alimenté et
        // n'est donc pas repris.
        $siret = preg_replace('/[^0-9]/', '', (string) $row->CT_Siret);
        if ($siret !== '') {
            $societe->idprof2 = $siret;
            // Le SIREN est les neuf premiers chiffres du SIRET. On ne le déduit que
            // d'un SIRET bien formé : 4 valeurs de la source ne le sont pas.
            if (strlen($siret) === 14) {
                $societe->idprof1 = substr($siret, 0, 9);
            }
        }

        // CT_Identifiant mélange deux natures de données : de vrais numéros de TVA
        // intracommunautaire (44) et des identifiants internes numériques (89, du type
        // « 171 » ou « 4048 »). Seuls les premiers sont repris, reconnaissables à leur
        // code pays sur deux lettres ; verser les seconds dans tva_intra y injecterait
        // des numéros de TVA inexistants.
        $identifiant = trim((string) $row->CT_Identifiant);
        if ($identifiant !== '' && preg_match('/^[A-Za-z]{2}/', $identifiant)) {
            // La source panache les séparateurs : « BE0425.648.074 »,
            // « BE 0 430 246 468 », « FR 11 702 044 710 ».
            $societe->tva_intra = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $identifiant));
        }

        // Code APE/NAF : la source alterne « 5811Z » et « 63.12Z ». On retire le point
        // pour aligner sur la forme attendue par Dolibarr.
        $ape = trim((string) $row->CT_Ape);
        if ($ape !== '') {
            $societe->idprof3 = str_replace('.', '', $ape);
        }

        // ── Nature du tiers ────────────────────────────────────────────────
        // CT_Qualite sert de civilité aux particuliers et de nature juridique aux
        // personnes morales. C'est le seul champ permettant de typer les tiers, et il
        // révèle que 94 % d'entre eux sont des particuliers.
        $societe->typent_id = $this->resolveTypent($row->CT_Qualite);

        // ── Statut ─────────────────────────────────────────────────────────
        // CT_Sommeil marque les comptes mis en sommeil (88 tiers) : la logique est
        // inversée par rapport à Dolibarr, où status = 1 signifie « en activité ».
        $societe->status = empty($row->CT_Sommeil) ? 1 : 0;

        // La catégorie tarifaire (N_CatTarif) n'est pas traitée ici : ni create() ni
        // update() n'écrivent price_level, qui ne se pose que par setPriceLevel().
        // Elle est donc appliquée depuis migrateRow(), une fois le tiers persisté.

        // Devise de facturation. La table des devises Sage n'ayant pas été importée, les
        // codes sont déduits des pays des tiers concernés (voir $currencyAliases).
        $currency = $this->resolveCurrency($row->N_Devise);
        if ($currency !== '') {
            $societe->multicurrency_code = $currency;
        }

        // CT_Encours n'est repris dans aucun champ : la colonne vaut 0 sur la totalité
        // des lignes, comme Capital_social.

        // ── Divers ─────────────────────────────────────────────────────────
        $commentaire = trim((string) $row->CT_Commentaire);
        if ($commentaire !== '') {
            $societe->note_private = $commentaire;
        }

        // Date de création d'origine. Dolibarr ne la remplace par la date du jour que
        // si elle est vide (societe.class.php:1044) et update() n'y touche jamais :
        // l'ancienneté réelle des comptes est donc préservée, y compris si la reprise
        // est rejouée.
        if (!empty($row->CT_DateCreate)) {
            $dateCreation = $this->db->jdate($row->CT_DateCreate);
            if ($dateCreation > 0) {
                $societe->date_creation = $dateCreation;
            }
        }
    }

    /**
     * Met un numéro de téléphone au format international lorsqu'un préfixe pays est
     * renseigné dans la source.
     *
     * Le zéro de tête d'un numéro national disparaît en notation internationale :
     * « 0671834495 » avec le préfixe « 33 » devient « +33 671834495 ». Sans préfixe, le
     * numéro est repris tel quel — c'est le cas de l'immense majorité des lignes, seules
     * 37 d'entre elles portent un préfixe.
     *
     * @param string $number Numéro brut
     * @param string $prefix Préfixe pays brut (« 33 », « +33 », « 852 »…)
     * @return string        Numéro mis en forme, chaîne vide si la source est vide
     */
    protected function formatPhone($number, $prefix)
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }

        // Le préfixe est saisi tantôt « 33 », tantôt « +33 » : on ne garde que les
        // chiffres avant de reconstruire la notation.
        $prefix = preg_replace('/[^0-9]/', '', (string) $prefix);
        if ($prefix === '') {
            return $number;
        }

        if (substr($number, 0, 1) === '0') {
            $number = ltrim(substr($number, 1));
        }

        return '+'.$prefix.' '.$number;
    }

    /**
     * Complète une adresse de site web dépourvue de schéma.
     *
     * La source mélange les formes « https://airbus-shop.com/fr/ » et
     * « www.cepadues.com » ; sans schéma, Dolibarr interprète l'adresse comme un lien
     * relatif et le lien devient inutilisable.
     *
     * @param string $url Adresse brute
     * @return string     Adresse normalisée, chaîne vide si la source est vide
     */
    protected function formatUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
