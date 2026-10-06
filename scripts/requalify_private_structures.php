<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/requalify_private_structures.php
 * \ingroup aeromigration
 * \brief   Requalifie les structures classées « Particulier », et recense les fiches de robots.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * Sur 147 380 tiers « Particulier » ayant un contact, 19 878 portent un nom qui n'est pas celui de
 * ce contact. Presque tous (19 799) tiennent ce nom du champ « société » de leur compte boutique,
 * et se répartissent en deux populations qui n'ont rien de commun :
 *
 * - environ **3 400 vraies structures** — « AERO-CLUB RHONE-ALPIN », contact « HENRY Luc » ;
 *   « OCCITANIE AVIATION SAS », contact « MORIZOT Yannick ». Leur nom est déjà le bon, seul leur
 *   TYPE est faux : un compte boutique devient un particulier, quoi qu'il ait saisi. 1 371 d'entre
 *   elles ont été facturées depuis 2024, ce sont des clients vivants ;
 * - environ **16 000 fiches de robots** — tiers « hZecECAfJOkaB », contact « xvBEzChekrfa
 *   UMlmTeEvJaZjsS ». Des inscriptions automatiques sur la boutique, de 2023 à 2025, arrivées jusque
 *   dans Dolibarr : 10 % du fichier tiers, et pas une seule pièce.
 *
 * Le type n'est pas une étiquette : sur un particulier, la fiche propose un nom, un prénom et une
 * date de naissance, et la synchronisation de la boutique recompose le nom du tiers depuis son
 * contact. Sur une structure mal classée, un correspondant qui corrige son prénom en ligne aurait
 * rebaptisé l'aéro-club de son propre nom — aeropresta 0.36.0 s'en garde, mais la fiche reste fausse.
 *
 * ------------------------------------------------------------------------------
 * CE QUE FAIT LE SCRIPT
 * ------------------------------------------------------------------------------
 *
 * Il ne se fie qu'à Dolibarr — la boutique n'est pas interrogée — et range chaque fiche concernée
 * dans l'un de trois lots :
 *
 * 1. **Structures classées** : le nom contient un mot qui ne laisse aucun doute — « aéro-club »,
 *    « SARL », « mairie », « association ». Leur type est corrigé (avec `--confirm`).
 * 2. **Structures à relire** : le nom n'est pas celui du contact, mais rien n'y désigne sûrement une
 *    nature. Rien n'est écrit : elles partent dans un CSV, triées par chiffre d'affaires récent, avec
 *    une colonne à remplir.
 * 3. **Fiches de robots** : rien n'est écrit non plus. Un CSV, pour que le client décide de leur sort.
 *
 * ------------------------------------------------------------------------------
 * LES MOTS QUI CLASSENT, ET CEUX QUI NE CLASSENT PAS
 * ------------------------------------------------------------------------------
 *
 * Un classement automatique doit être sûr, quitte à classer peu. Seuls comptent donc les mots
 * « forts » : formes juridiques, « aéro-club », « mairie », « association »… Les mots « faibles » —
 * « aviation », « services », « club », « école » — orientent sans prouver : « ÉCOLE DE PILOTAGE »
 * est tantôt une société, tantôt une association. Ils ne font qu'un indice dans le CSV à relire.
 *
 * Les mots du contact sont retirés du nom avant la recherche : « DE SA Maria » ne porte pas la forme
 * juridique « SA », c'est son nom de famille.
 *
 * ------------------------------------------------------------------------------
 * RECONNAÎTRE UN ROBOT
 * ------------------------------------------------------------------------------
 *
 * Un mot unique d'au moins six lettres, sans chiffre ni espace, où une minuscule précède une
 * majuscule : la signature d'une chaîne tirée au hasard. « Dupont » et « DUPONT » ne la portent pas.
 *
 * Le nom du tiers ne suffit pas — « AirWax », « ArmorSky » et « AirFrance » la portent aussi, et ce
 * sont de vrais clients. Il faut que le nom ou le prénom du CONTACT la porte également, et que la
 * fiche n'ait aucune pièce : ni facture, ni commande, ni devis, ni expédition. Une fiche au nom
 * tiré au hasard qui en aurait une part dans le lot à relire, jamais dans celui des robots.
 *
 * ------------------------------------------------------------------------------
 * ÉCRITURE
 * ------------------------------------------------------------------------------
 *
 * UPDATE ciblé sur `llx_societe.fk_typent`, par paquets. Pas de `Societe::update()` : il déclenche
 * COMPANY_MODIFY, donc la propagation vers la boutique et un événement d'agenda par fiche, pour un
 * champ que la boutique ignore.
 *
 * Les fiches touchées reçoivent l'horodatage de la passe dans `import_key`, colonne libre sur
 * `llx_societe` (aucun tiers n'en porte). La passe est donc réversible :
 *
 *     UPDATE llx_societe SET fk_typent = <id de TE_PRIVATE>, import_key = NULL
 *      WHERE import_key = '<clé affichée en fin de passe>';
 *
 * Rejouable : une fiche requalifiée n'est plus un particulier, elle sort du périmètre d'elle-même.
 *
 * Usage :
 *   php requalify_private_structures.php                       simulation : chiffres seuls
 *   php requalify_private_structures.php --csv-dir=/tmp/tiers  simulation + les trois CSV
 *   php requalify_private_structures.php --confirm --csv-dir=/tmp/tiers   applique le lot 1
 *   php requalify_private_structures.php --limit=50 --confirm  lot d'essai
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

@ini_set('memory_limit', '1G');

/** Fiches écrites par requête. */
const CHUNK = 500;

/** Nombre d'exemples listés au rapport, par lot. */
const SAMPLES = 8;

/** Début de la période « récente » pour le chiffre d'affaires des CSV. */
const RECENT_FROM = '2024-01-01';


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;
$csvDir  = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--csv-dir=(.+)$/', $arg, $m)) {
        $csvDir = rtrim($m[1], '/\\');
        if (strpos($csvDir, '~') === 0) {
            $csvDir = getenv('HOME').substr($csvDir, 1);
        }
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--csv-dir=DOSSIER]\n";
        exit(1);
    }
}

if ($csvDir !== '' && !is_dir($csvDir) && !@mkdir($csvDir, 0775, true)) {
    echo "Impossible de créer le dossier ".$csvDir."\n";
    exit(1);
}


/*
 * Outils de lecture des noms
 */

/**
 * Forme de comparaison : sans accents, ponctuation et tirets ramenés à des espaces.
 *
 * @param  string $v Texte
 * @return string
 */
function rq_norm($v)
{
    $v = strtolower(dol_string_unaccent((string) $v));

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $v)));
}

/**
 * Les mots d'un texte, triés : « DUPONT Jean-Pierre » et « Jean Pierre Dupont » donnent la même liste.
 *
 * @param  string $v Texte
 * @return string[]
 */
function rq_words($v)
{
    $t = array_values(array_filter(explode(' ', rq_norm($v)), 'strlen'));
    sort($t);

    return $t;
}

/**
 * Une chaîne tirée au hasard ? Voir l'en-tête : un mot unique, lettres seules, six au moins, où une
 * minuscule précède une majuscule.
 *
 * @param  string $v Texte
 * @return bool
 */
function rq_random($v)
{
    $v = trim((string) $v);

    return (bool) (preg_match('/^[A-Za-z]{6,}$/', $v) && preg_match('/[a-z][A-Z]/', $v));
}

/**
 * Familles de mots FORTS : ceux qui suffisent à classer.
 *
 * L'ordre compte — la première famille reconnue l'emporte. « AÉRO-CLUB … SAS » est un aéro-club.
 *
 * @return array<string,string> code de type => expression
 */
function rq_strong()
{
    return array(
        'TE_AEROCLUB' => '/\b(aero ?clubs?|aeroclubs?|club ulm|ulm club|club aeronautique|cercle aeronautique'
            .'|aero ?modelisme|aeromodel\w*|vol a voile|planeurs?|para ?club)\b/',
        // Les seules collectivités : tout ce qui emploie ou enseigne — ENAC, DGAC, armée, université,
        // hôpital — est d'abord l'employeur ou l'école que des particuliers déclarent comme société.
        'TE_ADMIN'    => '/\b(mairie|commune de|ville de|conseil (general|regional|departemental)|prefecture'
            .'|sdis|cci|chambre de commerce)\b/',
        'TE_OTHER'    => '/\b(association|asso|amicale|comite|federation|ligue|fondation|musee|syndicat|cse)\b/',
        'TE_SOCIETE'  => '/\b(sarl|sas|sasu|eurl|sci|scp|snc|selarl|sprl|gmbh|ltd|limited|inc|llc|bv|srl'
            .'|ets|etablissements?|societe|entreprise|cie|compagnie|holding|groupe?)\b|( sa| spa)$|^sa /',
    );
}

/**
 * Mots FAIBLES : ils orientent sans prouver, et ne servent que d'indice dans le CSV à relire.
 *
 * @return array<string,string> code de type suggéré => expression
 */
function rq_weak()
{
    return array(
        // Employeurs et écoles d'abord : « ENAC », « DGAC » ou « Armée de l'air » sont portés par des
        // dizaines de fiches distinctes — autant d'élèves et d'agents, pas autant d'administrations.
        'TE_ADMIN'    => '/\b(enac|dgac|cnrs|onera|armee|base aerienne|escadron|escadrille|regiment|gendarmerie'
            .'|ministere|universite|lycee|college|iut|hopital|chu|centre hospitalier|ecole|institut|centre)\b/',
        'TE_AEROCLUB' => '/\b(club|ulm|parachutisme|voltige|ailes?|aerodrome|aeroport)\b/',
        'TE_SOCIETE'  => '/\b(aviation|aero\w*|air|fly\w*|flight|helico\w*|pilot\w*|services?|conseil|consulting'
            .'|formation|training|maintenance|atelier|garage|cabinet|agence|studio|shop|boutique|store'
            .'|ingenierie|engineering|technolog\w*|industrie\w*|transport\w*|corp|ste)\b|^ce /',
        'TE_OTHER'    => '/\b(section|cercle|union|amis)\b/',
    );
}

/**
 * Le champ « société » rempli pour dire qu'il n'y en a pas ?
 *
 * « PAS DE SOCIETE », « Néant », « Particulier », « -- » : le client a répondu à la question au lieu
 * de laisser le champ vide, et sa réponse est devenue le nom de sa fiche. Ce ne sont pas des
 * structures — et « PAS DE SOCIETE » contient pourtant le mot « société ».
 *
 * @param  string $text Texte normalisé
 * @return bool
 */
function rq_novalue($text)
{
    return (bool) preg_match(
        '/^(pas de|sans|aucune?|neant|none|non|n a|na|nc|ras|particulier|perso|personnel|personnelle|prive|privee'
        .'|individuel|retraite|etudiant|eleve|pilote|monsieur|madame|mr|mme|m|test\w*|x+|[0-9]+)\b/', $text);
}

/**
 * Première famille reconnue dans un texte.
 *
 * @param  string               $text     Texte normalisé
 * @param  array<string,string> $families Familles
 * @param  string               $word     Sortie : le mot reconnu
 * @return string                         Code de type, vide si aucune
 */
function rq_family($text, $families, &$word = '')
{
    $word = '';
    foreach ($families as $code => $re) {
        if (preg_match($re, $text, $m)) {
            $word = trim($m[0]);

            return $code;
        }
    }

    return '';
}


/*
 * Référentiel des types
 */

$typent = array();
$resql  = $db->query('SELECT id, code, libelle FROM '.MAIN_DB_PREFIX.'c_typent');
while ($resql && ($o = $db->fetch_object($resql))) {
    $typent[$o->code] = array('id' => (int) $o->id, 'label' => (string) $o->libelle);
}
foreach (array('TE_PRIVATE', 'TE_SOCIETE', 'TE_AEROCLUB', 'TE_ADMIN', 'TE_OTHER') as $code) {
    if (empty($typent[$code]['id'])) {
        echo "Type de tiers absent du dictionnaire : ".$code." — activer aeromigration.\n";
        exit(1);
    }
}


/*
 * Chargement
 */

echo "Base : ".$conf->db->name."   Mode : ".($confirm ? 'ÉCRITURE' : 'simulation')."\n\n";

// Le contact porteur de chaque tiers : l'actif le plus ancien, comme aerotb_person_contact().
$holder = array();
$resql  = $db->query('SELECT fk_soc, rowid, lastname, firstname FROM '.MAIN_DB_PREFIX.'socpeople'
    .' WHERE fk_soc IS NOT NULL ORDER BY fk_soc, statut DESC, rowid ASC');
while ($resql && ($o = $db->fetch_object($resql))) {
    if (!isset($holder[(int) $o->fk_soc])) {
        $holder[(int) $o->fk_soc] = $o;
    }
}

// Les factures : nombre, dernière date, chiffre d'affaires récent.
$invoices = array();
$resql    = $db->query('SELECT fk_soc, COUNT(*) as nb, MAX(datef) as last,'
    ." SUM(CASE WHEN datef >= '".RECENT_FROM."' THEN total_ht ELSE 0 END) as recent"
    .' FROM '.MAIN_DB_PREFIX.'facture WHERE fk_statut > 0 GROUP BY fk_soc');
while ($resql && ($o = $db->fetch_object($resql))) {
    $invoices[(int) $o->fk_soc] = $o;
}

// Les tiers qui ont la moindre pièce : une fiche de robot n'en a aucune.
$hasDoc = array();
foreach (array('facture', 'commande', 'propal', 'expedition') as $table) {
    $resql = $db->query('SELECT DISTINCT fk_soc FROM '.MAIN_DB_PREFIX.$table);
    while ($resql && ($o = $db->fetch_object($resql))) {
        $hasDoc[(int) $o->fk_soc] = true;
    }
}

// Le compte boutique, pour que le CSV des robots permette de les retrouver des deux côtés.
$shopId = array();
$resql  = $db->query('SELECT fk_soc_doli, fk_customer_presta FROM '.MAIN_DB_PREFIX.'prestasync_customer');
while ($resql && ($o = $db->fetch_object($resql))) {
    $shopId[(int) $o->fk_soc_doli] = (int) $o->fk_customer_presta;
}


/*
 * Classement
 */

$strong = rq_strong();
$weak   = rq_weak();

$count = array('private' => 0, 'person' => 0, 'anonymized' => 0, 'variant' => 0, 'nocontact' => 0, 'shared' => 0);
$lots  = array('classed' => array(), 'review' => array(), 'robots' => array());
$byType = array();
// Combien de fiches portent le même nom de structure : au-delà de deux, ce n'est plus une structure
// et son doublon, c'est un employeur que plusieurs personnes ont déclaré.
$sameName = array();

$sql = 'SELECT s.rowid, s.nom, s.code_client, s.email, s.town, s.zip, s.datec'
    .' FROM '.MAIN_DB_PREFIX.'societe as s'
    .' WHERE s.fk_typent = '.$typent['TE_PRIVATE']['id'].' AND s.entity IN ('.getEntity('societe').')'
    .' ORDER BY s.rowid';
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des tiers impossible : ".$db->lasterror()."\n";
    exit(1);
}

while ($s = $db->fetch_object($resql)) {
    $count['private']++;
    $id   = (int) $s->rowid;
    $name = rq_norm($s->nom);
    $ct   = isset($holder[$id]) ? $holder[$id] : null;

    if (strpos($name, 'anonymi') !== false) {
        $count['anonymized']++;
        continue;
    }

    // Le nom du tiers est celui de son contact, à l'ordre, aux accents et aux tirets près.
    if ($ct) {
        $a = rq_norm($ct->lastname.' '.$ct->firstname);
        if ($name === $a || $name === rq_norm($ct->firstname.' '.$ct->lastname)
            || rq_words($s->nom) === rq_words($ct->lastname.' '.$ct->firstname)) {
            $count['person']++;
            continue;
        }
    }

    $inv = isset($invoices[$id]) ? $invoices[$id] : null;
    $row = array(
        'id'      => $id,
        'code'    => (string) $s->code_client,
        'name'    => (string) $s->nom,
        'contact' => $ct ? trim($ct->lastname.' '.$ct->firstname) : '',
        'email'   => (string) $s->email,
        'town'    => trim($s->zip.' '.$s->town),
        'created' => substr((string) $s->datec, 0, 10),
        'nbinv'   => $inv ? (int) $inv->nb : 0,
        'last'    => $inv ? substr((string) $inv->last, 0, 10) : '',
        'recent'  => $inv ? (float) $inv->recent : 0.0,
        'shop'    => isset($shopId[$id]) ? $shopId[$id] : '',
    );

    // ── Fiche de robot ──
    if (rq_random($s->nom) && $ct && (rq_random($ct->lastname) || rq_random($ct->firstname))) {
        if (empty($hasDoc[$id])) {
            $lots['robots'][] = $row;
        } else {
            // Un nom tiré au hasard, mais une pièce : ce n'est pas à nous de trancher.
            $lots['review'][] = $row + array('hint' => '', 'word' => 'nom au hasard, mais a des pièces');
        }
        continue;
    }

    // Les mots du contact sortent du nom avant toute recherche : « DE SA Maria » n'est pas une SA.
    $rest = $name;
    if ($ct) {
        foreach (rq_words($ct->lastname.' '.$ct->firstname) as $w) {
            if (strlen($w) >= 2) {
                $rest = trim(preg_replace('/\s+/', ' ', preg_replace('/\b'.preg_quote($w, '/').'\b/', ' ', $rest)));
            }
        }
    }

    // Il ne reste rien : tous les mots du nom sont dans le contact. Deux cas, que seul un mot fort
    // départage — « Manuel Javier », contact « GARCIA Manuel Javier », est une personne dont la
    // fiche porte le seul prénom ; « AEROCLUB DE BERGERAC », contact saisi au nom du club, est bien
    // un aéro-club. Dans le second, la recherche reprend sur le nom entier.
    if ($rest === '') {
        if (rq_family($name, $strong) === '') {
            $count['variant']++;
            continue;
        }
        $rest = $name;
    }

    $row['key']      = $rest;
    $sameName[$rest] = isset($sameName[$rest]) ? $sameName[$rest] + 1 : 1;

    // ── Pas une structure : le champ « société » rempli pour dire qu'il n'y en a pas ──
    if (rq_novalue($rest)) {
        $lots['review'][] = $row + array('hint' => '', 'word' => 'pas une société ? le nom devrait être celui du contact');
        continue;
    }

    // ── Structure classée : un mot fort ──
    $word = '';
    $code = rq_family($rest, $strong, $word);
    if ($code !== '') {
        $lots['classed'][] = $row + array('type' => $code, 'word' => $word);
        continue;
    }

    $hint = rq_family($rest, $weak, $word);

    if (!$ct) {
        // Aucun contact : rien ne dit que le nom n'est pas celui d'une personne. On ne retient la
        // fiche que si un mot, même faible, y désigne autre chose.
        if ($hint === '') {
            $count['nocontact']++;
            continue;
        }
        $lots['review'][] = $row + array('hint' => $hint, 'word' => $word);
        continue;
    }

    // Le nom contient le nom de famille du contact : un couple, un nom d'usage, un nom tronqué par
    // l'ancien ERP. Sans mot qui désigne autre chose, c'est une personne.
    $family = rq_norm($ct->lastname);
    if ($hint === '' && $family !== '' && strlen($family) >= 3
        && preg_match('/\b'.preg_quote($family, '/').'\b/', $name)) {
        $count['variant']++;
        continue;
    }

    $lots['review'][] = $row + array('hint' => $hint, 'word' => $word);
}
$db->free($resql);

// UN NOM PORTÉ PAR TROIS FICHES OU PLUS N'EST PAS CLASSÉ D'OFFICE.
//
// « AIRBUS OPERATIONS SAS » porte une forme juridique sans équivoque, et pourtant ses fiches sont des
// salariés d'Airbus qui achètent pour eux : en faire autant de « sociétés » serait faux. Deux fiches
// du même nom sont le plus souvent un doublon de la même structure, et restent classées.
$kept = array();
foreach ($lots['classed'] as $r) {
    if ($sameName[$r['key']] >= 3) {
        $count['shared']++;
        $lots['review'][] = $r + array('hint' => $r['type']);
        continue;
    }
    $kept[]             = $r;
    $byType[$r['type']] = isset($byType[$r['type']]) ? $byType[$r['type']] + 1 : 1;
}
$lots['classed'] = $kept;

// Les fiches à relire : les clients vivants d'abord.
usort($lots['review'], function ($a, $b) {
    if ($a['recent'] != $b['recent']) {
        return ($a['recent'] < $b['recent']) ? 1 : -1;
    }

    return strcmp((string) $b['last'], (string) $a['last']);
});
usort($lots['classed'], function ($a, $b) {
    return strcmp($a['type'].$a['name'], $b['type'].$b['name']);
});


/*
 * Rapport
 */

$recentOf = function ($lot) {
    $n = 0;
    foreach ($lot as $r) {
        if ($r['last'] >= RECENT_FROM) {
            $n++;
        }
    }

    return $n;
};

printf("Tiers « Particulier »                                   : %7d\n", $count['private']);
printf("  dont le nom est celui du contact                     : %7d\n", $count['person']);
printf("  dont le nom contient le nom de famille (variantes)   : %7d\n", $count['variant']);
printf("  anonymisés                                           : %7d\n", $count['anonymized']);
printf("  sans contact et sans mot de structure                : %7d\n", $count['nocontact']);
echo "\n";
printf("LOT 1 — structures classées (type corrigé)              : %7d   dont %d facturées depuis %s\n",
    count($lots['classed']), $recentOf($lots['classed']), RECENT_FROM);
foreach (array('TE_AEROCLUB', 'TE_SOCIETE', 'TE_ADMIN', 'TE_OTHER') as $code) {
    printf("        %-16s %6d\n", $typent[$code]['label'], isset($byType[$code]) ? $byType[$code] : 0);
}
printf("LOT 2 — structures à relire (CSV, rien n'est écrit)     : %7d   dont %d facturées depuis %s\n",
    count($lots['review']), $recentOf($lots['review']), RECENT_FROM);
printf("        dont un mot fort, mais un nom porté par 3 fiches ou plus : %d\n", $count['shared']);
printf("LOT 3 — fiches de robots (CSV, rien n'est écrit)        : %7d   soit %.1f %% des particuliers\n",
    count($lots['robots']), $count['private'] ? 100 * count($lots['robots']) / $count['private'] : 0);

foreach (array('classed' => 'LOT 1', 'review' => 'LOT 2', 'robots' => 'LOT 3') as $key => $title) {
    echo "\n".$title." — exemples :\n";
    foreach (array_slice($lots[$key], 0, SAMPLES) as $r) {
        $tail = '';
        if ($key === 'classed') {
            $tail = '  → '.$typent[$r['type']]['label'].' (« '.$r['word'].' »)';
        } elseif ($key === 'review' && $r['recent'] > 0) {
            $tail = '  '.price2num($r['recent'], 'MT').' € HT depuis '.substr(RECENT_FROM, 0, 4);
        }
        printf("   %-13s %-38s [%s]%s\n", $r['code'], dol_trunc($r['name'], 36), dol_trunc($r['contact'], 28), $tail);
    }
}


/*
 * Les CSV
 */

if ($csvDir !== '') {
    $write = function ($file, $head, $rows, $map) use ($csvDir) {
        $fh = fopen($csvDir.'/'.$file, 'w');
        if (!$fh) {
            echo "Écriture impossible : ".$csvDir.'/'.$file."\n";

            return;
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $head, ';');
        foreach ($rows as $r) {
            fputcsv($fh, $map($r), ';');
        }
        fclose($fh);
        echo "   ".$csvDir.'/'.$file."  (".count($rows)." lignes)\n";
    };
    $money = function ($v) {
        return str_replace('.', ',', (string) round((float) $v, 2));
    };

    echo "\nCSV écrits :\n";
    $write('structures_classees.csv',
        array('code_client', 'nom_du_tiers', 'type_propose', 'mot_reconnu', 'contact', 'email', 'ville',
            'nb_factures', 'derniere_facture', 'ca_ht_depuis_'.substr(RECENT_FROM, 0, 4), 'fiches_du_meme_nom', 'id_tiers'),
        $lots['classed'],
        function ($r) use ($typent, $money, $sameName) {
            return array($r['code'], $r['name'], $typent[$r['type']]['label'], $r['word'], $r['contact'], $r['email'],
                $r['town'], $r['nbinv'], $r['last'], $money($r['recent']), $sameName[$r['key']], $r['id']);
        });
    $write('structures_a_relire.csv',
        array('code_client', 'nom_du_tiers', 'type_a_retenir', 'indice', 'contact', 'email', 'ville',
            'nb_factures', 'derniere_facture', 'ca_ht_depuis_'.substr(RECENT_FROM, 0, 4), 'fiches_du_meme_nom', 'id_tiers'),
        $lots['review'],
        function ($r) use ($typent, $money, $sameName) {
            $hint = ($r['hint'] !== '') ? $typent[$r['hint']]['label'].' ? (« '.$r['word'].' »)' : $r['word'];
            $same = (isset($r['key']) && isset($sameName[$r['key']])) ? $sameName[$r['key']] : 1;

            return array($r['code'], $r['name'], '', $hint, $r['contact'], $r['email'], $r['town'],
                $r['nbinv'], $r['last'], $money($r['recent']), $same, $r['id']);
        });
    $write('fiches_robots.csv',
        array('code_client', 'nom_du_tiers', 'contact', 'email', 'ville', 'fiche_creee_le', 'id_tiers', 'id_compte_boutique'),
        $lots['robots'],
        function ($r) {
            return array($r['code'], $r['name'], $r['contact'], $r['email'], $r['town'], $r['created'], $r['id'], $r['shop']);
        });
}


/*
 * Écriture du lot 1
 */

$todo = $lots['classed'];
if ($limit > 0) {
    $todo = array_slice($todo, 0, $limit);
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. ".count($todo)." fiche(s) seraient requalifiées avec --confirm.\n";
    exit(0);
}
if (!$todo) {
    echo "\nRien à requalifier.\n";
    exit(0);
}

// La clé de la passe : elle marque chaque fiche touchée, et permet de tout défaire.
//
// QUATORZE CARACTÈRES, pas un de plus : c'est la largeur de `llx_societe.import_key`. Une clé plus
// longue serait tronquée à l'écriture sans que rien ne le dise, et la commande d'annulation
// affichée plus bas — qui cite la clé entière — ne retrouverait alors aucune fiche. Constaté à
// l'essai avec un préfixe : cinq fiches écrites, zéro retrouvée. L'horodatage seul tient juste.
$key = dol_print_date(dol_now(), '%Y%m%d%H%M%S');

$perType = array();
foreach ($todo as $r) {
    $perType[$r['type']][] = (int) $r['id'];
}

$error = '';
$db->begin();
foreach ($perType as $code => $ids) {
    foreach (array_chunk($ids, CHUNK) as $chunk) {
        // La clause sur fk_typent garde le script rejouable et sûr : une fiche requalifiée à la main
        // entre la lecture et l'écriture n'est pas reprise.
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'societe SET fk_typent = '.$typent[$code]['id']
            .", import_key = '".$db->escape($key)."'"
            .' WHERE rowid IN ('.implode(',', $chunk).') AND fk_typent = '.$typent['TE_PRIVATE']['id'];
        if (!$db->query($sql)) {
            $error = $db->lasterror();
            break 2;
        }
    }
}

if ($error !== '') {
    $db->rollback();
    echo "\nÉCHEC, rien n'a été écrit : ".$error."\n";
    exit(1);
}
$db->commit();

$written = 0;
$resql   = $db->query('SELECT COUNT(*) as n FROM '.MAIN_DB_PREFIX."societe WHERE import_key = '".$db->escape($key)."'");
if ($resql && ($o = $db->fetch_object($resql))) {
    $written = (int) $o->n;
}

// Le compte se refait sur la clé elle-même : s'il ne retrouve pas tout ce qui vient d'être envoyé à
// l'écriture, la commande d'annulation ne le retrouvera pas non plus, et il faut le dire avant de
// l'afficher. Un écart en moins est normal si des fiches ont été requalifiées à la main entre-temps.
if ($written !== count($todo)) {
    echo "\nATTENTION : ".count($todo)." fiche(s) envoyée(s) à l'écriture, ".$written." retrouvée(s) sous la clé ".$key.".\n";
}

echo "\n".$written." fiche(s) requalifiée(s).\n";
echo "Clé de la passe : ".$key."\n";
echo "Pour défaire :\n";
echo "   UPDATE ".MAIN_DB_PREFIX."societe SET fk_typent = ".$typent['TE_PRIVATE']['id'].", import_key = NULL"
    ." WHERE import_key = '".$key."';\n";
