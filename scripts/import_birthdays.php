<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_birthdays.php
 * \ingroup aeromigration
 * \brief   Pose les dates de naissance de la boutique sur les contacts des tiers particuliers.
 *
 * ------------------------------------------------------------------------------
 * D'OÙ VIENT LA DONNÉE, ET POURQUOI ELLE PASSE PAR UN FICHIER
 * ------------------------------------------------------------------------------
 *
 * L'ancien ERP n'a jamais enregistré les dates de naissance (148 remplies sur 157 803 tiers) :
 * la source est PRESTASHOP (`ps_customer.birthday`, saisie à l'inscription — 103 916 clients,
 * 66,9 %). Le fichier `migrationdata/naissances_contacts_*.csv` en est l'extraction, avec des
 * CLÉS PORTABLES uniquement — jamais d'id Dolibarr, recréés à chaque reprise :
 *
 *     id_customer_presta;ct_num_add;email;prenom;nom;date_naissance
 *
 * La résolution suit la règle des fichiers de liaison : le CODE TIERS ADD d'abord (celui que
 * la reprise pose en `ref_ext` « SAGE:<code> », 99,8 % des lignes), l'e-mail en repli pour les
 * clients nés dans la boutique — et uniquement s'il ne désigne qu'UN tiers. Avant le jour J,
 * régénérer le fichier depuis la dernière photo des bases, comme les liaisons.
 *
 * ## Où la date se pose — et sur qui
 *
 * Sur le CONTACT du tiers (`llx_socpeople.birthday`, le champ natif de Dolibarr — les tiers
 * n'en ont pas), et pour les TIERS PARTICULIERS UNIQUEMENT (type « TE_PRIVATE ») : sur une
 * société ou un aéroclub, une date de naissance n'aurait pas de sens.
 *
 * Le cas simple domine (126 713 particuliers ont exactement un contact) ; quand le tiers en
 * porte plusieurs — les adresses de livraison créées en contacts par la boutique —, le contact
 * est apparié par NOM normalisé (sans accents ni casse), prénom+nom d'abord, nom seul ensuite.
 * Aucune correspondance unique = ligne écartée et comptée, jamais devinée.
 *
 * ## Écriture et idempotence
 *
 * Écriture par `setValueFrom()` (l'API générique du coeur, comme `ref_ext` des réceptions) —
 * une date déjà conforme n'est pas réécrite, le script est rejouable et `--limit` permet un
 * lot d'essai. Une date différente déjà en place est REMPLACÉE : la boutique fait foi, c'est
 * elle que le client alimente.
 *
 * Usage :
 *   php import_birthdays.php                 simulation : ventilation complète, rien d'écrit
 *   php import_birthdays.php --confirm       applique
 *   php import_birthdays.php --limit=100 --confirm    lot d'essai
 *   php import_birthdays.php --file=/chemin/vers/fichier.csv
 *   php import_birthdays.php --user=LOGIN
 */

foreach (array('NOTOKENRENEWAL', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOLOGIN', 'NOSESSION') as $c) {
    if (!defined($c)) {
        define($c, '1');
    }
}

$sapi_type   = php_sapi_name();
$script_file = basename(__FILE__);
$path        = __DIR__.'/';

if (substr($sapi_type, 0, 3) === 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}

require_once $path.'../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

$langs->loadLangs(array('admin'));

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** Code du type de tiers « particulier ». */
const TYPENT_PRIVATE = 'TE_PRIVATE';

/** Nombre d'exemples listés par catégorie d'écart. */
const SAMPLES = 8;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$file      = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--file=CSV] [--user=LOGIN]\n";
        exit(1);
    }
}


/*
 * Utilisateur
 */

$user = new User($db);
if ($userLogin !== '') {
    if ($user->fetch(0, $userLogin) <= 0) {
        echo "Utilisateur introuvable : ".$userLogin."\n";
        exit(1);
    }
} else {
    $sql   = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1';
    $sql  .= ' AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid ASC LIMIT 1';
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) === 0) {
        echo "Aucun administrateur actif trouvé. Précisez --user=LOGIN.\n";
        exit(1);
    }
    $obj = $db->fetch_object($resql);
    $db->free($resql);
    $user->fetch((int) $obj->rowid);
}
$user->loadRights();


/*
 * Le fichier : le plus récent de migrationdata/, sauf --file.
 */

if ($file === '') {
    $candidates = glob(__DIR__.'/../migrationdata/naissances_contacts_*.csv');
    if (empty($candidates)) {
        echo "Aucun fichier migrationdata/naissances_contacts_*.csv trouvé.\n";
        echo "Générez l'extraction depuis la base PrestaShop, ou précisez --file=.\n";
        exit(1);
    }
    sort($candidates);
    $file = end($candidates);
}
if (!is_readable($file)) {
    echo "Fichier illisible : ".$file."\n";
    exit(1);
}

/**
 * Normalise un nom pour l'appariement : minuscules, sans accents, espaces réduits.
 *
 * @param  string $s Valeur brute
 * @return string
 */
function nkey($s)
{
    $s = strtolower(dol_string_unaccent(trim((string) $s)));

    return preg_replace('/\s+/', ' ', $s);
}


/*
 * Index en mémoire — une requête chacun, jamais une par ligne.
 */

// 1. Tiers repris : code ADD (minuscule) -> rowid.
$socByCt = array();
$resql = $db->query('SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'societe'
    ." WHERE entity IN (".getEntity('societe').") AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = strtolower(trim(substr($o->ref_ext, strlen(REF_EXT_PREFIX))));
    if ($key !== '') {
        $socByCt[$key] = (int) $o->rowid;
    }
}
if ($resql) {
    $db->free($resql);
}

// 2. Repli e-mail : e-mail (minuscule) -> rowid, ou -1 si plusieurs tiers le partagent.
$socByEmail = array();
$resql = $db->query('SELECT rowid, email FROM '.MAIN_DB_PREFIX.'societe'
    ." WHERE entity IN (".getEntity('societe').") AND email IS NOT NULL AND email <> ''");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = strtolower(trim((string) $o->email));
    if ($key === '') {
        continue;
    }
    $socByEmail[$key] = isset($socByEmail[$key]) ? -1 : (int) $o->rowid;
}
if ($resql) {
    $db->free($resql);
}

// 3. Le type de chaque tiers — seuls les particuliers sont servis.
$privateTypentId = 0;
$resql = $db->query('SELECT id FROM '.MAIN_DB_PREFIX."c_typent WHERE code = '".TYPENT_PRIVATE."' LIMIT 1");
if ($resql && ($o = $db->fetch_object($resql))) {
    $privateTypentId = (int) $o->id;
}
if ($privateTypentId <= 0) {
    echo "Type de tiers ".TYPENT_PRIVATE." introuvable dans le dictionnaire.\n";
    exit(1);
}

$socTypent = array();
$resql = $db->query('SELECT rowid, fk_typent FROM '.MAIN_DB_PREFIX.'societe'
    .' WHERE entity IN ('.getEntity('societe').')');
while ($resql && ($o = $db->fetch_object($resql))) {
    $socTypent[(int) $o->rowid] = (int) $o->fk_typent;
}
if ($resql) {
    $db->free($resql);
}

// 4. Les contacts, par tiers : c'est sur eux que la date se pose.
$contactsBySoc = array();
$resql = $db->query('SELECT rowid, fk_soc, lastname, firstname, birthday FROM '.MAIN_DB_PREFIX.'socpeople'
    .' WHERE entity IN ('.getEntity('contact').') AND fk_soc IS NOT NULL');
while ($resql && ($o = $db->fetch_object($resql))) {
    $contactsBySoc[(int) $o->fk_soc][] = array(
        'id'        => (int) $o->rowid,
        'lastname'  => nkey($o->lastname),
        'firstname' => nkey($o->firstname),
        'birthday'  => ($o->birthday !== null) ? substr($o->birthday, 0, 10) : '',
    );
}
if ($resql) {
    $db->free($resql);
}


/*
 * Parcours du fichier.
 */

$stats = array(
    'lignes'        => 0,
    'via_ct'        => 0,
    'via_email'     => 0,
    'maj'           => 0,
    'deja'          => 0,
    'tiers_absent'  => 0,
    'non_particulier' => 0,
    'sans_contact'  => 0,
    'ambigu'        => 0,
    'date_invalide' => 0,
    'erreur'        => 0,
);
$samples = array('tiers_absent' => array(), 'non_particulier' => array(), 'sans_contact' => array(), 'ambigu' => array());

$fh = fopen($file, 'r');
if (!$fh) {
    echo "Ouverture impossible : ".$file."\n";
    exit(1);
}
$header = fgetcsv($fh, 0, ';');   // en-tête, ignoré — l'ordre des colonnes est le contrat

$contact = new Contact($db);
$written = 0;
$batch   = 0;

echo "Script      : dates de naissance boutique -> contacts des tiers particuliers\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Fichier     : ".$file."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 6) {
        continue;
    }
    $stats['lignes']++;

    list(, $ctNum, $email, $prenom, $nom, $date) = $row;
    $ctNum = strtolower(trim((string) $ctNum));
    $email = strtolower(trim((string) $email));
    $date  = trim((string) $date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $stats['date_invalide']++;
        continue;
    }

    // ── Résolution du tiers : code ADD d'abord, e-mail unique en repli ─────
    $socId = 0;
    if ($ctNum !== '' && isset($socByCt[$ctNum])) {
        $socId = $socByCt[$ctNum];
        $stats['via_ct']++;
    } elseif ($email !== '' && isset($socByEmail[$email]) && $socByEmail[$email] > 0) {
        $socId = $socByEmail[$email];
        $stats['via_email']++;
    } else {
        $stats['tiers_absent']++;
        if (count($samples['tiers_absent']) < SAMPLES) {
            $samples['tiers_absent'][] = ($ctNum !== '' ? $ctNum : $email);
        }
        continue;
    }

    // ── Particuliers uniquement ────────────────────────────────────────────
    if (!isset($socTypent[$socId]) || $socTypent[$socId] !== $privateTypentId) {
        $stats['non_particulier']++;
        if (count($samples['non_particulier']) < SAMPLES) {
            $samples['non_particulier'][] = ($ctNum !== '' ? $ctNum : $email);
        }
        continue;
    }

    // ── Le contact cible ───────────────────────────────────────────────────
    $list = isset($contactsBySoc[$socId]) ? $contactsBySoc[$socId] : array();
    $target = null;

    if (count($list) === 1) {
        $target = &$list[0];
    } elseif (count($list) > 1) {
        // Plusieurs contacts (adresses de livraison…) : appariement par nom normalisé.
        // Prénom+nom identiques = LA MÊME PERSONNE dupliquée par la boutique — plusieurs
        // correspondances sont donc acceptées, la première (rowid le plus ancien, celui de
        // la reprise) reçoit la date. Le nom SEUL reste à unicité stricte : deux prénoms
        // différents sous un même nom, c'est un couple ou une famille, on ne devine pas.
        $nomK = nkey($nom);
        $preK = nkey($prenom);
        $hits = array();
        foreach ($list as $i => $c) {
            if ($c['lastname'] === $nomK && $c['firstname'] === $preK) {
                $hits[] = $i;
            }
        }
        if (empty($hits)) {
            foreach ($list as $i => $c) {
                if ($c['lastname'] === $nomK) {
                    $hits[] = $i;
                }
            }
            if (count($hits) > 1) {
                $hits = array();
            }
        }
        if (!empty($hits)) {
            $target = &$list[$hits[0]];
        }
    }

    if ($target === null) {
        $key = (count($list) === 0) ? 'sans_contact' : 'ambigu';
        $stats[$key]++;
        if (count($samples[$key]) < SAMPLES) {
            $samples[$key][] = ($ctNum !== '' ? $ctNum : $email);
        }
        unset($target);
        continue;
    }

    // ── Idempotence : une date déjà conforme n'est pas réécrite ────────────
    if ($target['birthday'] === $date) {
        $stats['deja']++;
        unset($target);
        continue;
    }

    $stats['maj']++;

    if ($confirm) {
        $ts = dol_mktime(12, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
        $contact->id = $target['id'];
        if ($contact->setValueFrom('birthday', $ts, '', null, 'date', '', $user) < 0) {
            $stats['erreur']++;
            $stats['maj']--;
        } else {
            // L'index suit l'écriture : un doublon de ligne dans le fichier devient « déjà à jour ».
            $target['birthday'] = $date;
            $written++;
            if (++$batch >= 500) {
                $db->commit();
                $db->begin();
                $batch = 0;
            }
            if ($written % 10000 === 0) {
                echo "  ".number_format($written, 0, ',', ' ')." écrite(s)…\n";
            }
        }
    }
    unset($target);

    if ($limit > 0 && $stats['maj'] >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}
fclose($fh);

if ($confirm) {
    $db->commit();
}

printf("\nLignes lues        : %s\n", number_format($stats['lignes'], 0, ',', ' '));
printf("Résolues par code ADD : %s — par e-mail : %s\n",
    number_format($stats['via_ct'], 0, ',', ' '), number_format($stats['via_email'], 0, ',', ' '));
printf("Dates %s : %s — déjà à jour : %s\n",
    $confirm ? 'écrites' : 'à écrire',
    number_format($stats['maj'], 0, ',', ' '), number_format($stats['deja'], 0, ',', ' '));

$ecarts = array(
    'tiers_absent'    => 'tiers introuvable (ni code ADD, ni e-mail unique)',
    'non_particulier' => 'tiers non particulier : hors périmètre',
    'sans_contact'    => 'particulier sans aucun contact',
    'ambigu'          => 'plusieurs contacts sans correspondance de nom unique',
    'date_invalide'   => 'date de naissance invalide dans le fichier',
    'erreur'          => 'écriture refusée',
);
$shown = false;
foreach ($ecarts as $key => $libelle) {
    if ($stats[$key] > 0) {
        if (!$shown) {
            echo "\nÉcarts :\n";
            $shown = true;
        }
        printf("  %s  %s%s\n", str_pad(number_format($stats[$key], 0, ',', ' '), 7, ' ', STR_PAD_LEFT), $libelle,
            (!empty($samples[$key])) ? ' — '.implode(', ', $samples[$key]) : '');
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['erreur'] > 0 ? 1 : 0);
