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
dol_include_once('/aeromigration/lib/aeromigration.lib.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class MigrationThirdparty extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'thirdparty';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptThirdparty';

    /** @var string Les tables de l'ancien ERP ne sont pas dans la base de Dolibarr */
    public $sourceDb = 'aeroprod';

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

    // ── Rapprochement avec la boutique en ligne ────────────────────────────

    /**
     * Identifiant de la boutique dans llx_prestasync_customer.
     *
     * Le client n'en exploite qu'une seule, et les 20 469 liens existants portent tous la
     * valeur 1. Propriété plutôt que constante en dur, pour rester ajustable.
     *
     * @var int
     */
    protected $prestaShopId = 1;

    /** @var bool La table de liaison Prestasync est-elle disponible ? */
    protected $prestaLinkAvailable = false;

    /** @var array<string,int> id client boutique -> rowid du tiers Dolibarr à rattacher */
    protected $societeByPrestaId = array();

    /** @var array<string,bool> Identifiants boutique déjà liés à un tiers */
    protected $prestaIdsLinked = array();

    /** @var int Nombre de tiers écartés du lien boutique car l'identifiant est déjà pris */
    protected $prestaIdConflicts = 0;

    /** @var int Nombre de liens boutique créés durant le passage */
    protected $prestaLinksCreated = 0;

    /** @var array<int,bool> Tiers déjà adoptés, pour n'en adopter aucun deux fois */
    protected $adoptedSocIds = array();

    // ── Génération des codes tiers ─────────────────────────────────────────

    /**
     * Compteurs de codes tiers, tenus en mémoire.
     *
     * Dolibarr recalcule le prochain numéro à CHAQUE création, par un
     * `SELECT MAX(CAST(SUBSTRING(code_client FROM 7) AS SIGNED))` sur toute la table.
     * Malgré le commentaire du coeur affirmant le contraire, cette requête n'exploite pas
     * l'index — ni le `SUBSTRING`, ni le `LIKE 'CU____-%'` ne le permettent. Mesurée à
     * 134 ms sur 113 000 tiers, et croissante : le coût total est quadratique, soit près
     * de deux heures pour la reprise complète.
     *
     * Le compteur est donc lu une seule fois au démarrage, puis incrémenté en mémoire.
     * Les codes produits sont rigoureusement identiques à ceux de mod_codeclient_monkey.
     *
     * @var array<string,int>
     */
    protected $codeCounters = array('client' => null, 'fournisseur' => null);

    /** @var string Préfixe des codes client, comme mod_codeclient_monkey */
    protected $codeClientPrefix = 'CU';

    /** @var string Préfixe des codes fournisseur, comme mod_codeclient_monkey */
    protected $codeFournisseurPrefix = 'SU';

    /**
     * Niveau de prix réellement stocké pour les tiers candidats à l'adoption.
     *
     * Indispensable car Societe::fetch() ne restitue pas la valeur brute : lorsque le
     * multiprix est actif et que la colonne est vide, il retourne 1 par défaut
     * (societe.class.php:2231). S'y fier ferait croire que toute fiche possède déjà un
     * niveau, et la reprise n'en poserait jamais.
     *
     * @var array<int,int>
     */
    protected $priceLevelBySocId = array();

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

        // ── Rapprochement avec la boutique en ligne ────────────────────────
        if ($this->loadPrestaLinks() < 0) {
            return -1;
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
     * Charge les correspondances avec les clients de la boutique en ligne.
     *
     * Deux index sont construits :
     *  - les tiers déjà remontés par la boutique et pas encore repris, candidats à
     *    l'adoption ;
     *  - l'ensemble des identifiants boutique déjà liés, pour ne jamais en associer un à
     *    deux tiers.
     *
     * La jointure sur llx_societe est indispensable : un lien peut désigner un tiers
     * supprimé. Le filtre sur ref_ext écarte ceux que la reprise gère déjà.
     *
     * L'absence de la table n'est pas une erreur : le module Prestasync peut ne pas être
     * installé, auquel cas le rapprochement est simplement sans objet.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function loadPrestaLinks()
    {
        $table = MAIN_DB_PREFIX.'prestasync_customer';

        // Le second paramètre à 1 rend l'échec silencieux : on teste l'existence.
        if (!$this->db->query('SELECT rowid FROM '.$table.' LIMIT 1', 1)) {
            dol_syslog('MigrationThirdparty : table '.$table.' absente, rapprochement boutique désactivé', LOG_NOTICE);
            return 1;
        }

        $this->prestaLinkAvailable = true;

        // L'index va de l'identifiant boutique vers le tiers, et non l'inverse : rien
        // n'interdit à plusieurs liens de désigner le même tiers, indexer par fk_soc_doli
        // en perdrait donc silencieusement.
        $sql   = 'SELECT fk_customer_presta, fk_soc_doli FROM '.$table;
        $sql  .= ' WHERE fk_presta = '.((int) $this->prestaShopId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        $socByPrestaId = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $prestaId = (string) $obj->fk_customer_presta;
            $this->prestaIdsLinked[$prestaId] = true;
            $socByPrestaId[$prestaId] = (int) $obj->fk_soc_doli;
        }
        $this->db->free($resql);

        if (empty($socByPrestaId)) {
            return 1;
        }

        // Parmi ces tiers, ceux qui existent encore et que la reprise n'a pas déjà pris
        // en charge : ce sont les candidats à l'adoption. La liste des rowid est
        // dédoublonnée, plusieurs identifiants boutique pouvant viser le même tiers.
        $rowids = array_unique(array_map('intval', array_values($socByPrestaId)));

        // price_level est relu ici, à sa valeur brute : Societe::fetch() la remplacerait
        // par 1 lorsqu'elle est vide et que le multiprix est actif.
        $eligible = array();
        $sql   = 'SELECT rowid, price_level FROM '.MAIN_DB_PREFIX.'societe';
        $sql  .= ' WHERE entity IN ('.getEntity('societe').')';
        $sql  .= " AND (ref_ext IS NULL OR ref_ext NOT LIKE '".$this->db->escape($this->refExtPrefix)."%')";
        $sql  .= ' AND rowid IN ('.implode(',', $rowids).')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rowid                           = (int) $obj->rowid;
            $eligible[$rowid]                = true;
            $this->priceLevelBySocId[$rowid] = (int) $obj->price_level;
        }
        $this->db->free($resql);

        foreach ($socByPrestaId as $prestaId => $socid) {
            if (isset($eligible[$socid])) {
                $this->societeByPrestaId[$prestaId] = $socid;
            }
        }

        return 1;
    }

    /**
     * Attribue le prochain code tiers, au format du module de codification Monkey.
     *
     * Reproduit `mod_codeclient_monkey::getNextValue()` : préfixe, année et mois sur
     * quatre chiffres, puis un compteur sur cinq chiffres — le remplissage disparaissant
     * au-delà de 99 999, ce qui arrive nécessairement avec 157 000 tiers.
     *
     * Le maximum n'est interrogé qu'au premier appel : c'est tout l'intérêt.
     *
     * @param string $type 'client' ou 'fournisseur'
     * @return string      Code attribué, chaîne vide si le maximum est illisible
     */
    protected function nextTiersCode($type)
    {
        $field  = ($type === 'fournisseur') ? 'code_fournisseur' : 'code_client';
        $prefix = ($type === 'fournisseur') ? $this->codeFournisseurPrefix : $this->codeClientPrefix;

        if ($this->codeCounters[$type] === null) {
            $posindice = strlen($prefix) + 6;

            $sql  = 'SELECT MAX(CAST(SUBSTRING('.$field.' FROM '.((int) $posindice).') AS SIGNED)) as max';
            $sql .= ' FROM '.MAIN_DB_PREFIX.'societe';
            $sql .= " WHERE ".$field." LIKE '".$this->db->escape($prefix)."____-%'";
            $sql .= ' AND entity IN ('.getEntity('societe').')';

            $resql = $this->db->query($sql);
            if (!$resql) {
                return '';
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);

            $this->codeCounters[$type] = ($obj && $obj->max !== null) ? (int) $obj->max : 0;
        }

        $this->codeCounters[$type]++;
        $num = $this->codeCounters[$type];

        // Monkey cesse de compléter à cinq chiffres au-delà de 99 999.
        $suffix = ($num >= 100000) ? (string) $num : sprintf('%05d', $num);

        return $prefix.dol_print_date(dol_now(), '%y%m', 'tzuserrel').'-'.$suffix;
    }

    /**
     * Identifiant du client dans la boutique en ligne, pour une ligne source.
     *
     * `id_externe` porte cet identifiant. Les valeurs vides et « 0 » ne désignent aucun
     * client : 1 540 tiers sont dans ce cas.
     *
     * @param stdClass $row Ligne source
     * @return string       Identifiant, chaîne vide si inexploitable
     */
    protected function getPrestaCustomerId($row)
    {
        $id = trim((string) $row->id_externe);

        if ($id === '' || $id === '0' || !ctype_digit($id)) {
            return '';
        }

        return $id;
    }

    /**
     * Déclare le tiers auprès de la boutique en ligne.
     *
     * Sans ce lien, la première commande du client ferait créer un second tiers par le
     * module de synchronisation : celui-ci ne recherche aucun tiers existant, il crée
     * directement (voir PrestaCustomer::createDoliObject). L'insertion reproduit à
     * l'identique ce que fait PrestaCustomer::setCustomDolLink(), qui n'exécute qu'un
     * INSERT sans traitement annexe.
     *
     * Un identifiant boutique ne peut désigner qu'un seul tiers : 313 valeurs
     * d'`id_externe` sont partagées dans la source, les doublons sont donc écartés et
     * comptés.
     *
     * @param int      $socid rowid du tiers venant d'être créé
     * @param stdClass $row   Ligne source
     * @return void
     * @throws Exception Si l'insertion échoue
     */
    protected function registerPrestaLink($socid, $row)
    {
        if (!$this->prestaLinkAvailable || $socid <= 0) {
            return;
        }

        $prestaId = $this->getPrestaCustomerId($row);
        if ($prestaId === '') {
            return;
        }

        if (isset($this->prestaIdsLinked[$prestaId])) {
            $this->prestaIdConflicts++;
            return;
        }

        $sql  = 'INSERT INTO '.MAIN_DB_PREFIX.'prestasync_customer';
        $sql .= ' (fk_presta, fk_customer_presta, fk_soc_doli, date_creation, tms)';
        $sql .= ' VALUES ('.((int) $this->prestaShopId).', '.((int) $prestaId).', '.((int) $socid).', NOW(), NOW())';

        if (!$this->db->query($sql)) {
            throw new Exception('Échec de l\'enregistrement du lien boutique : '.$this->db->lasterror());
        }

        $this->prestaIdsLinked[$prestaId] = true;
        $this->prestaLinksCreated++;
    }

    /**
     * Annonce l'action prévue en simulation, adoption comprise.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du tiers déjà repris, 0 sinon
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        if ($existingId > 0) {
            return 'updated';
        }

        $prestaId = $this->getPrestaCustomerId($row);
        if ($prestaId !== '' && isset($this->societeByPrestaId[$prestaId])) {
            return 'adopted';
        }

        return 'created';
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

        if ($this->prestaLinksCreated > 0 || $this->prestaIdConflicts > 0) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Boutique en ligne :';
            $lines[] = '  '.str_pad((string) $this->prestaLinksCreated, 6, ' ', STR_PAD_LEFT).'  lien(s) créé(s) dans llx_prestasync_customer';
            if ($this->prestaIdConflicts > 0) {
                $lines[] = '  '.str_pad((string) $this->prestaIdConflicts, 6, ' ', STR_PAD_LEFT).'  tiers non liés : identifiant boutique déjà attribué';
            }
        } elseif (!$this->prestaLinkAvailable) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Boutique en ligne : table de liaison absente, aucun rapprochement effectué.';
        }

        return $lines;
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

        // ── Adoption d'un tiers venu de la boutique ────────────────────────
        // Le tiers existe déjà dans Dolibarr, remonté par la synchronisation boutique.
        // On ne le recrée pas — le code client déclencherait d'ailleurs une collision
        // d'unicité — on le complète et on le rattache à la reprise.
        $prestaId  = $this->getPrestaCustomerId($row);
        $adoptedId = ($prestaId !== '' && isset($this->societeByPrestaId[$prestaId]))
            ? $this->societeByPrestaId[$prestaId]
            : 0;

        // Un tiers ne peut être adopté qu'une fois, même si plusieurs identifiants
        // boutique le désignent.
        if ($adoptedId > 0 && isset($this->adoptedSocIds[$adoptedId])) {
            $adoptedId = 0;
        }

        if ($adoptedId > 0) {
            if ($societe->fetch($adoptedId) <= 0) {
                throw new Exception('Tiers boutique introuvable (rowid '.$adoptedId.') : '.$this->objectErrors($societe));
            }

            // Niveau réellement stocké, et non celui restitué par fetch() qui vaut 1 par
            // défaut dès que le multiprix est actif.
            $currentLevel = isset($this->priceLevelBySocId[$adoptedId])
                ? $this->priceLevelBySocId[$adoptedId]
                : 0;

            // Mode complétion : la fiche existante fait foi, on ne remplit que les vides.
            $this->mapFields($societe, $row, true);
            $societe->ref_ext = $this->buildRefExt($this->getSourceKey($row));

            // Codes tiers laissés intacts : ceux attribués par la boutique font autorité.
            $result = $societe->update($adoptedId, $this->user, 1, 0, 0);
            if ($result <= 0) {
                throw new Exception('Échec de l\'adoption : '.$this->objectErrors($societe));
            }

            // Même règle que les autres champs : on ne pose la catégorie tarifaire que si
            // la fiche existante n'en a pas.
            if ($currentLevel <= 0) {
                $this->applyPriceLevel($societe, (int) $row->N_CatTarif, $currentLevel);
            }

            // Ce tiers ne doit plus être proposé à l'adoption d'une autre ligne source.
            unset($this->societeByPrestaId[$prestaId]);
            $this->adoptedSocIds[$adoptedId] = true;

            return array('action' => 'adopted', 'id' => $adoptedId);
        }

        $this->mapFields($societe, $row);

        // Trace de l'origine : c'est ce champ qui rend le script rejouable.
        $societe->ref_ext = $this->buildRefExt($this->getSourceKey($row));

        $result = $societe->create($this->user);
        if ($result <= 0) {
            throw new Exception('Échec de la création : '.$this->objectErrors($societe));
        }

        $this->applyPriceLevel($societe, (int) $row->N_CatTarif, 0);

        // Déclaration à la boutique, pour que ses futures commandes retrouvent ce tiers.
        $this->registerPrestaLink((int) $societe->id, $row);

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
     * ATTENTION : la catégorie source n'est PAS le niveau Dolibarr. Les deux premières
     * sont permutées, le tarif du site devenant le niveau 1 — voir
     * aeromigration_price_level(), qui porte la conversion et sa justification. Recopier
     * N_CatTarif tel quel, comme le faisait la première version de ce script, facture au
     * tarif comptoir les 146 388 clients de la boutique, sans qu'aucune erreur ne le dise.
     *
     * @param Societe $societe      Tiers déjà enregistré
     * @param int     $catTarif     Catégorie tarifaire de la source (N_CatTarif)
     * @param int     $currentLevel Niveau actuellement en base
     * @return void
     * @throws Exception Si l'écriture échoue
     */
    protected function applyPriceLevel(Societe $societe, $catTarif, $currentLevel)
    {
        $target = aeromigration_price_level($catTarif);

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
     * @param Societe  $societe  Objet à alimenter
     * @param stdClass $row      Ligne de f_comptet
     * @param bool     $fillOnly Mode complétion : ne renseigner que les champs vides
     * @return void
     * @throws Exception Si la ligne source ne permet pas de construire un tiers valide
     */
    protected function mapFields(Societe $societe, $row, $fillOnly = false)
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
        // Le nom d'un tiers adopté n'est jamais remplacé : celui de la boutique est le
        // nom sous lequel le client se connaît.
        if (!$fillOnly) {
            $societe->name = $name;
        }

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

        // Codes tiers : la valeur -1 demande à Dolibarr d'appliquer son module de
        // codification. C'est le même masque que celui utilisé par la synchronisation
        // boutique, ce qui évite toute collision — reprendre CT_Num heurterait de front
        // l'index unique uk_societe_code_client sur les tiers déjà remontés.
        // CT_Num reste conservé dans ref_ext, et pourra redevenir le code tiers plus tard
        // si le client tient à ses références historiques.
        // À la création uniquement : le code d'un tiers adopté ne se touche pas.
        // Le code est calculé ici plutôt que laissé à Dolibarr (valeur -1) : son module
        // de codification relit le maximum de la table à chaque création, ce qui rend la
        // reprise quadratique. Voir nextTiersCode().
        if (!$fillOnly && $societe->id <= 0) {
            if (!empty($societe->client)) {
                $code = $this->nextTiersCode('client');
                $societe->code_client = ($code !== '') ? $code : -1;
            }
            if (!empty($societe->fournisseur)) {
                $code = $this->nextTiersCode('fournisseur');
                $societe->code_fournisseur = ($code !== '') ? $code : -1;
            }
        }

        // ── Adresse ────────────────────────────────────────────────────────
        // Dolibarr n'a qu'un champ d'adresse, multi-ligne : le complément Sage vient
        // donc sur une seconde ligne. Les longueurs maximales relevées dans la source
        // (169 caractères pour l'ensemble) tiennent sans troncature dans les 255
        // caractères de llx_societe.address.
        $adresse    = trim((string) $row->CT_Adresse);
        $complement = trim((string) $row->CT_Complement);

        if ($adresse !== '' && $complement !== '') {
            $address = $adresse."\n".$complement;
        } else {
            $address = ($adresse !== '') ? $adresse : $complement;
        }

        $this->assign($societe, 'address', $address, $fillOnly);
        $this->assign($societe, 'zip', trim((string) $row->CT_CodePostal), $fillOnly);
        $this->assign($societe, 'town', trim((string) $row->CT_Ville), $fillOnly);

        // Pays laissé vide lorsque la source ne le renseigne pas ou que le libellé
        // n'est pas reconnu ; les libellés inconnus sont remontés en fin de passage.
        $this->assign($societe, 'country_id', $this->resolveCountry($row->CT_Pays), $fillOnly);

        // Département déduit du code postal, pour la France uniquement. Il suit le pays
        // effectivement retenu, qui peut être celui de la fiche existante en adoption.
        $this->assign($societe, 'state_id', $this->resolveDepartement($societe->zip, $societe->country_id), $fillOnly);

        // CT_CodeRegion est ignoré : renseigné sur 81 lignes seulement sur 157 103, et
        // sans correspondance directe avec le référentiel Dolibarr.

        // ── Coordonnées ────────────────────────────────────────────────────
        // Les numéros et adresses sont repris tels quels, sans filtrage : la source fait
        // foi, y compris pour ses valeurs de test (555-666-0606) et ses adresses
        // syntaxiquement invalides. Seule la mise en forme est normalisée.
        $this->assign($societe, 'phone', $this->formatPhone($row->CT_Telephone, $row->prefixe_telephone), $fillOnly);
        $this->assign($societe, 'phone_mobile', $this->formatPhone($row->Portable, $row->prefixe_portable), $fillOnly);
        $this->assign($societe, 'fax', $this->formatPhone($row->CT_Telecopie, $row->prefixe_telecopie), $fillOnly);
        $this->assign($societe, 'email', trim((string) $row->CT_EMail), $fillOnly);
        $this->assign($societe, 'url', $this->formatUrl($row->CT_Site), $fillOnly);

        // ── Identifiants légaux ────────────────────────────────────────────
        // Champs très peu renseignés dans la source : 41 SIRET, 16 codes APE et 133
        // « identifiants » pour 157 102 tiers. Capital_social n'est jamais alimenté et
        // n'est donc pas repris.
        $siret = preg_replace('/[^0-9]/', '', (string) $row->CT_Siret);
        if ($siret !== '') {
            $this->assign($societe, 'idprof2', $siret, $fillOnly);
            // Le SIREN est les neuf premiers chiffres du SIRET. On ne le déduit que
            // d'un SIRET bien formé : 4 valeurs de la source ne le sont pas.
            if (strlen($siret) === 14) {
                $this->assign($societe, 'idprof1', substr($siret, 0, 9), $fillOnly);
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
            $this->assign($societe, 'tva_intra', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $identifiant)), $fillOnly);
        }

        // Code APE/NAF : la source alterne « 5811Z » et « 63.12Z ». On retire le point
        // pour aligner sur la forme attendue par Dolibarr.
        $ape = trim((string) $row->CT_Ape);
        if ($ape !== '') {
            $this->assign($societe, 'idprof3', str_replace('.', '', $ape), $fillOnly);
        }

        // ── Nature du tiers ────────────────────────────────────────────────
        // CT_Qualite sert de civilité aux particuliers et de nature juridique aux
        // personnes morales. C'est le seul champ permettant de typer les tiers, et il
        // révèle que 94 % d'entre eux sont des particuliers.
        $this->assign($societe, 'typent_id', $this->resolveTypent($row->CT_Qualite), $fillOnly);

        // ── Statut ─────────────────────────────────────────────────────────
        // CT_Sommeil marque les comptes mis en sommeil (88 tiers) : la logique est
        // inversée par rapport à Dolibarr, où status = 1 signifie « en activité ».
        // En adoption, le statut de la fiche existante n'est jamais modifié : désactiver
        // un client actif de la boutique aurait des conséquences immédiates.
        if (!$fillOnly) {
            $societe->status = empty($row->CT_Sommeil) ? 1 : 0;
        }

        // La catégorie tarifaire (N_CatTarif) n'est pas traitée ici : ni create() ni
        // update() n'écrivent price_level, qui ne se pose que par setPriceLevel().
        // Elle est donc appliquée depuis migrateRow(), une fois le tiers persisté.

        // Devise de facturation. La table des devises Sage n'ayant pas été importée, les
        // codes sont déduits des pays des tiers concernés (voir $currencyAliases).
        $currency = $this->resolveCurrency($row->N_Devise);
        if ($currency !== '') {
            $this->assign($societe, 'multicurrency_code', $currency, $fillOnly);
        }

        // CT_Encours n'est repris dans aucun champ : la colonne vaut 0 sur la totalité
        // des lignes, comme Capital_social.

        // ── Divers ─────────────────────────────────────────────────────────
        $commentaire = trim((string) $row->CT_Commentaire);
        if ($commentaire !== '') {
            $this->assign($societe, 'note_private', $commentaire, $fillOnly);
        }

        // Date de création d'origine. Dolibarr ne la remplace par la date du jour que
        // si elle est vide (societe.class.php:1044) et update() n'y touche jamais :
        // l'ancienneté réelle des comptes est donc préservée, y compris si la reprise
        // est rejouée. Sans objet en adoption, la fiche existant déjà.
        if (!$fillOnly && !empty($row->CT_DateCreate)) {
            $dateCreation = $this->db->jdate($row->CT_DateCreate);
            if ($dateCreation > 0) {
                $societe->date_creation = $dateCreation;
            }
        }
    }

    /**
     * Affecte une valeur à une propriété de l'objet, en respectant le mode complétion.
     *
     * En mode complétion (adoption d'un tiers venu de la boutique), la fiche existante
     * fait autorité : on ne remplit que ce qu'elle a laissé vide. Une valeur source vide
     * n'écrase jamais rien, quel que soit le mode.
     *
     * @param Societe $societe  Objet à alimenter
     * @param string  $property Nom de la propriété
     * @param mixed   $value    Valeur issue de la source
     * @param bool    $fillOnly Mode complétion
     * @return void
     */
    protected function assign(Societe $societe, $property, $value, $fillOnly)
    {
        if ($value === '' || $value === null || $value === 0) {
            return;
        }

        if ($fillOnly && !empty($societe->$property)) {
            return;
        }

        $societe->$property = $value;
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
