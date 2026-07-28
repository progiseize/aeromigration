<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationcontact.class.php
 * \ingroup aeromigration
 * \brief   Reprise des contacts : f_contactt -> objets Contact de Dolibarr.
 *
 * PRÉREQUIS : la reprise des tiers doit avoir été passée avant celle-ci. Chaque contact
 * est rattaché à sa société via le `ref_ext` posé sur le tiers (« SAGE:<CT_Num> ») ; un
 * contact dont le tiers n'a pas encore été migré est refusé et signalé, pas créé
 * orphelin.
 *
 * Deux particularités de la source méritent d'être connues :
 *
 * - Les contacts sont pour l'essentiel des doublons de leur tiers : 124 273 sur 155 368
 *   portent exactement le nom de leur société, parce que chez un particulier le contact
 *   Sage est le client lui-même. La reprise de la totalité est un choix assumé.
 *
 * - `CT_Civilite` n'est PAS une civilité mais une classification du genre et de la
 *   nature du tiers (1 = féminin, 2 = personne morale, 0 = masculin par défaut). Elle est
 *   inexploitable telle quelle : parmi les 0 figurent 2 865 femmes. La civilité Dolibarr
 *   est donc déduite de `CT_Qualite` du tiers, seul champ fiable.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

class MigrationContact extends AeroMigrationRunner
{
    /** @var string Identifiant du script en ligne de commande */
    public $code = 'contact';

    /** @var string Clé de traduction du libellé */
    public $label = 'AeroMigScriptContact';

    /**
     * Source jointe au tiers : la civilité et la désinscription newsletter ne figurent
     * que côté `f_comptet`.
     *
     * @var string
     */
    protected $srcTable = 'f_contactt as k LEFT JOIN f_comptet as c ON c.CT_Num = k.CT_Num';

    /** @var string Colonnes lues, dont deux empruntées au tiers */
    protected $srcFields = 'k.*, c.CT_Qualite as tiers_qualite, c.Unsubscribe_Newsletter as tiers_unsubscribe';

    /**
     * Colonne de parcours : la clé primaire de f_contactt, réellement auto-incrémentée
     * ici (104 072 à 259 465, toutes distinctes) contrairement à celle de f_comptet.
     *
     * @var string
     */
    protected $srcCursorField = 'cbMarq';

    /** @var string La source étant une jointure, le curseur doit être qualifié */
    protected $srcCursorSqlField = 'k.cbMarq';

    /** @var string Identifiant métier du contact dans la source, unique */
    protected $srcKeyField = 'CT_No';

    /** @var string Table Dolibarr cible */
    protected $dstTable = 'socpeople';

    /** @var string Élément Dolibarr, pour getEntity() */
    protected $dstElement = 'socpeople';

    /**
     * ref_ext du tiers -> ses coordonnées déjà reprises dans Dolibarr.
     *
     * On relit le tiers Dolibarr plutôt que la source : son adresse, son pays et son
     * département y sont déjà normalisés et résolus. Cela évite de dupliquer dans ce
     * script la table de correspondance des pays et la déduction du département, et
     * garantit que contact et tiers portent exactement la même information.
     *
     * @var array<string,array<string,mixed>>
     */
    protected $societeByRefExt = array();

    /**
     * Codes de tiers introuvables, bornés à quelques centaines : lancer la reprise des
     * contacts avant celle des tiers ferait sinon grossir ce tableau d'une entrée par
     * ligne. Le total exact est tenu à part.
     *
     * @var array<string,int>
     */
    protected $unresolvedParents = array();

    /** @var int Nombre total de contacts non rattachables */
    protected $unresolvedParentsCount = 0;

    /**
     * Correspondance des qualités du tiers vers les codes de civilité Dolibarr.
     *
     * Les clés sont normalisées (minuscules, sans accent, ponctuation réduite).
     * Les personnes morales n'ont pas de civilité : elles sont volontairement absentes.
     *
     * @var array<string,string>
     */
    protected $civilityAliases = array(
        'monsieur'        => 'MR',
        'm'               => 'MR',
        'mr'              => 'MR',
        'madame'          => 'MME',
        'mme'             => 'MME',
        'mademoiselle'    => 'MLE',
        'monsieur madame' => 'MR',
    );

    /**
     * Charge l'index des tiers déjà migrés, pour rattacher chaque contact à sa société.
     *
     * Une seule requête plutôt qu'un fetch() par contact : sur 155 369 lignes, c'est
     * l'écart entre quelques secondes et plusieurs heures.
     *
     * @return int 1 si OK, -1 en cas d'erreur SQL
     */
    protected function prepare()
    {
        $sql  = 'SELECT rowid, ref_ext, address, zip, town, fk_pays, fk_departement,';
        $sql .= ' phone, phone_mobile, email FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= " WHERE entity IN (".getEntity('societe').")";
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->societeByRefExt[$obj->ref_ext] = array(
                'id'           => (int) $obj->rowid,
                'address'      => (string) $obj->address,
                'zip'          => (string) $obj->zip,
                'town'         => (string) $obj->town,
                'country_id'   => (int) $obj->fk_pays,
                'state_id'     => (int) $obj->fk_departement,
                'phone'        => (string) $obj->phone,
                'phone_mobile' => (string) $obj->phone_mobile,
                'email'        => (string) $obj->email,
            );
        }
        $this->db->free($resql);

        if (empty($this->societeByRefExt)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => 'Aucun tiers repris en base : lancez d\'abord « migrate.php thirdparty »',
            );
            return -1;
        }

        return 1;
    }

    /**
     * Rapport de fin de passage : contacts non rattachables.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        if ($this->unresolvedParentsCount === 0) {
            return array();
        }

        arsort($this->unresolvedParents);

        $lines = array('Contacts non rattachés : '.$this->unresolvedParentsCount.' (tiers absent de la reprise)');
        $lines[] = 'Codes de tiers concernés (20 premiers) :';
        foreach (array_slice($this->unresolvedParents, 0, 20, true) as $label => $nb) {
            $lines[] = '  '.str_pad((string) $nb, 6, ' ', STR_PAD_LEFT).'  '.$label;
        }

        $lines[] = '';
        $lines[] = 'Lancez « migrate.php thirdparty » avant la reprise des contacts.';

        return $lines;
    }

    /**
     * Crée ou met à jour le contact Dolibarr correspondant à une ligne de f_contactt.
     *
     * @param stdClass $row        Ligne source
     * @param int      $existingId rowid du contact déjà migré, 0 si création
     * @return array{action:string,id:int}
     * @throws Exception Si la création ou la mise à jour échoue
     */
    protected function migrateRow($row, $existingId)
    {
        $contact = new Contact($this->db);

        if ($existingId > 0) {
            if ($contact->fetch($existingId) <= 0) {
                throw new Exception('Contact introuvable (rowid '.$existingId.') : '.$this->objectErrors($contact));
            }

            $this->mapFields($contact, $row);

            $result = $contact->update($existingId, $this->user);
            if ($result <= 0) {
                throw new Exception('Échec de la mise à jour : '.$this->objectErrors($contact));
            }

            return array('action' => 'updated', 'id' => $existingId);
        }

        $this->mapFields($contact, $row);

        $contact->ref_ext = $this->buildRefExt($this->getSourceKey($row));

        $result = $contact->create($this->user);
        if ($result <= 0) {
            throw new Exception('Échec de la création : '.$this->objectErrors($contact));
        }

        return array('action' => 'created', 'id' => (int) $contact->id);
    }

    /**
     * Contrôle une ligne en simulation : applique le mapping sans rien persister.
     *
     * @param stdClass $row Ligne source
     * @return void
     * @throws Exception Si la ligne ne permet pas de construire un contact valide
     */
    protected function validateRow($row)
    {
        $contact = new Contact($this->db);
        $this->mapFields($contact, $row);
    }

    /**
     * Reporte les champs de la source sur l'objet Contact.
     *
     * @param Contact  $contact Objet à alimenter
     * @param stdClass $row     Ligne source
     * @return void
     * @throws Exception Si le tiers de rattachement est introuvable
     */
    protected function mapFields(Contact $contact, $row)
    {
        // ── Rattachement au tiers ──────────────────────────────────────────
        // Un contact sans société n'a pas de sens ici : plutôt que de le créer orphelin,
        // on refuse la ligne et on la signale.
        $ctNum = trim((string) $row->CT_Num);
        if ($ctNum === '') {
            throw new Exception('Contact sans tiers de rattachement (CT_Num vide)');
        }

        $refExtTiers = $this->buildRefExt($ctNum);
        if (!isset($this->societeByRefExt[$refExtTiers])) {
            $this->unresolvedParentsCount++;
            if (isset($this->unresolvedParents[$ctNum]) || count($this->unresolvedParents) < 200) {
                if (!isset($this->unresolvedParents[$ctNum])) {
                    $this->unresolvedParents[$ctNum] = 0;
                }
                $this->unresolvedParents[$ctNum]++;
            }

            throw new Exception('Tiers '.$ctNum.' absent de la reprise');
        }

        $tiers          = $this->societeByRefExt[$refExtTiers];
        $contact->socid = $tiers['id'];

        // ── Identité ───────────────────────────────────────────────────────
        // Un nom de la source contient un retour à la ligne en plein milieu : les
        // espaces multiples et sauts de ligne sont réduits à un espace simple.
        $nom    = $this->cleanName($row->CT_Nom);
        $prenom = $this->cleanName($row->CT_Prenom);

        if ($nom === '' && $prenom === '') {
            throw new Exception('Contact sans nom ni prénom');
        }

        // 507 contacts portent la même valeur en nom et en prénom, souvent une saisie
        // dégradée (« # », « a », un numéro de téléphone). La recopier dans les deux
        // champs n'apporterait rien : on ne conserve que le nom.
        if ($nom !== '' && $prenom !== '' && $this->normalizeLabel($nom) === $this->normalizeLabel($prenom)) {
            $prenom = '';
        }

        // Dolibarr exige un nom : à défaut, le prénom en tient lieu.
        $contact->lastname  = ($nom !== '') ? $nom : $prenom;
        $contact->firstname = ($nom !== '') ? $prenom : '';

        $contact->civility_id = $this->resolveCivility($row->tiers_qualite);

        $poste = trim((string) $row->CT_Fonction);
        if ($poste !== '') {
            $contact->poste = $poste;
        }

        // ── Coordonnées ────────────────────────────────────────────────────
        // La source est presque muette de ce côté : 43 téléphones et 54 e-mails pour
        // 155 369 contacts. Ce qui existe en propre est prioritaire ; à défaut, on
        // reprend la coordonnée du tiers, faute de quoi la quasi-totalité des fiches
        // contact serait vide.
        $contact->phone_pro    = $this->formatPhone($row->CT_Telephone, $row->prefixe_telephone);
        $contact->phone_mobile = $this->formatPhone($row->CT_TelPortable, $row->prefixe_portable);
        $contact->fax          = $this->formatPhone($row->CT_Telecopie, $row->prefixe_telecopie);
        $contact->email        = trim((string) $row->CT_EMail);

        if ($contact->phone_pro === '') {
            $contact->phone_pro = $tiers['phone'];
        }
        if ($contact->phone_mobile === '') {
            $contact->phone_mobile = $tiers['phone_mobile'];
        }
        if ($contact->email === '') {
            $contact->email = $tiers['email'];
        }

        // ── Adresse ────────────────────────────────────────────────────────
        // f_contactt ne porte aucune colonne d'adresse : elle est reprise du tiers,
        // dans sa forme déjà normalisée côté Dolibarr (pays et département résolus).
        $contact->address    = $tiers['address'];
        $contact->zip        = $tiers['zip'];
        $contact->town       = $tiers['town'];
        $contact->country_id = $tiers['country_id'];
        $contact->state_id   = $tiers['state_id'];

        // La désinscription aux envois (Unsubscribe_Newsletter, 2 621 tiers) n'est PAS
        // traitée ici. Dolibarr ne la stocke plus sur le contact : la colonne no_email
        // est marquée « no more used » et setNoEmail() alimente llx_mailing_unsubscribe,
        // une simple liste d'adresses — sans effet lorsque le contact n'a pas d'e-mail,
        // ce qui est le cas de 155 315 des 155 369 contacts. L'adresse étant portée par
        // le tiers, cette reprise relève d'un traitement distinct.

        $contact->statut = 1;
    }

    /**
     * Nettoie un nom : espaces multiples, tabulations et sauts de ligne réduits à un
     * espace simple.
     *
     * @param string $value Valeur brute
     * @return string       Valeur nettoyée
     */
    protected function cleanName($value)
    {
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim($value);
    }

    /**
     * Déduit la civilité Dolibarr de la qualité du tiers.
     *
     * `CT_Civilite` du contact n'est pas utilisée : elle classe le genre et la nature du
     * tiers (1 = féminin, 2 = personne morale, 0 = masculin par défaut) et se révèle
     * fausse pour 2 865 femmes rangées parmi les 0. `CT_Qualite` est le seul champ
     * fiable.
     *
     * @param string $qualite Valeur de CT_Qualite du tiers
     * @return string         Code civilité, chaîne vide si indéterminable
     */
    protected function resolveCivility($qualite)
    {
        $qualite = trim((string) $qualite);
        if ($qualite === '') {
            return '';
        }

        $normalized = $this->normalizeLabel($qualite);

        return isset($this->civilityAliases[$normalized]) ? $this->civilityAliases[$normalized] : '';
    }
}
