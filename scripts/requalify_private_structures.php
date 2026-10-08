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
 * ------------------------------------------------------------------------------
 * LE FICHIER RELU PAR LE CLIENT
 * ------------------------------------------------------------------------------
 *
 * Les deux CSV de structures portent une colonne « type_a_retenir », dont l'en-tête rappelle les sept
 * valeurs admises : Administration, Aéro-club, Association, Particulier, Revendeur, Société, Autre. Elle est
 * déjà remplie dans le lot 1 — le client corrige ce qu'il conteste — et vide dans le lot 2.
 *
 * `--types=FICHIER` relit le fichier rendu. Une ligne vide n'est pas touchée ; « Particulier » dit
 * que la fiche est bien classée ; une valeur qu'on ne reconnaît pas est signalée, jamais devinée.
 * Le fichier peut revenir d'un tableur avec des virgules ou en Windows-1252 : les deux se lisent.
 *
 * ------------------------------------------------------------------------------
 * « AUTRES » ÉTAIT « ASSOCIATION »
 * ------------------------------------------------------------------------------
 *
 * Le type « Association » n'existait pas à la reprise : les 598 tiers que l'ancien ERP qualifiait
 * ainsi ont été rangés dans « Autres ». `--other-to-asso` les fait passer dans le type créé en
 * 0.42.0. Passe à part, avec sa propre annulation.
 *
 * Usage :
 *   php requalify_private_structures.php                       simulation : chiffres seuls
 *   php requalify_private_structures.php --csv-dir=/tmp/tiers  simulation + les trois CSV
 *   php requalify_private_structures.php --confirm --csv-dir=/tmp/tiers   applique le lot 1 tel quel
 *   php requalify_private_structures.php --types=relu.csv      simulation du fichier relu
 *   php requalify_private_structures.php --types=relu.csv --confirm       applique le fichier relu
 *   php requalify_private_structures.php --other-to-asso [--confirm]      « Autres » → « Association »
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

$confirm     = false;
$limit       = 0;
$csvDir      = '';
$typesFiles  = array();
$otherToAsso = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--other-to-asso') {
        $otherToAsso = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--csv-dir=(.+)$/', $arg, $m)) {
        $csvDir = rtrim($m[1], '/\\');
        if (strpos($csvDir, '~') === 0) {
            $csvDir = getenv('HOME').substr($csvDir, 1);
        }
    } elseif (preg_match('/^--types=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $f) {
            $f = trim($f);
            if (strpos($f, '~') === 0) {
                $f = getenv('HOME').substr($f, 1);
            }
            if ($f !== '') {
                $typesFiles[] = $f;
            }
        }
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--csv-dir=DOSSIER] [--types=FICHIER[,FICHIER]] [--other-to-asso]\n";
        exit(1);
    }
}

// Trois passes, jamais deux à la fois : chacune a sa propre origine, donc sa propre annulation.
if ($otherToAsso && $typesFiles) {
    echo "--other-to-asso et --types ne se combinent pas : lancer deux passes.\n";
    exit(1);
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
 * Le nom d'une fiche débarrassé des mots de son contact : « AERO CLUB LANGROIS COUTURIER Michel »
 * et « AERO-CLUB LANGROIS » sont deux fiches du même club. Le nom entier s'il n'en reste rien.
 *
 * @param  string   $name         Nom normalisé du tiers
 * @param  string[] $contactWords Mots du contact
 * @return string
 */
function rq_key($name, $contactWords)
{
    $keep = array();
    foreach (explode(' ', $name) as $w) {
        if ($w !== '' && !(strlen($w) >= 2 && in_array($w, $contactWords, true))) {
            $keep[] = $w;
        }
    }

    return $keep ? implode(' ', $keep) : $name;
}

/**
 * L'adresse e-mail d'une fiche, si elle en est une : celles posées à la reprise pour les clients
 * qui n'en avaient pas — « client-123@boutique.aero » — ne désignent personne.
 *
 * @param  string $email Adresse
 * @return string        Adresse en minuscules, vide si absente ou factice
 */
function rq_real_email($email)
{
    $email = strtolower(trim((string) $email));
    if (strpos($email, '@') === false || preg_match('/^(client-[0-9]+@boutique[.]aero|email[0-9]+@default[.]com)$/', $email)) {
        return '';
    }

    return $email;
}

/**
 * Ce qui dit qu'une fiche au nom d'un aéro-club est bien celle du club.
 *
 * « Aéroclub de Dinard », contact « LEROUX Pierre », pierre.leroux920@orange.fr, une facture de
 * 40 € au tarif public : le club, ou un membre qui a cité le sien dans le champ « société » ? Rien
 * ne le dit. Sur les 431 fiches de la base du 02/10, 251 portent le tarif « Aéro-Clubs » — le
 * gérant a tranché, et elles achètent comme des clubs (13 factures en médiane) ; près de 90 n'ont
 * aucun signe, et une seule facture. Les signes, du plus sûr au moins sûr :
 *
 * - un tarif réservé, posé par le gérant ;
 * - aucun nom de personne : pas de contact, ou un contact saisi au nom du club ;
 * - une adresse sur un domaine propre — tout sauf une messagerie grand public, et sauf le domaine
 *   de la personne elle-même (« jos@vdkruk.com », contact « van der Kruk ») ;
 * - une boîte grand public au nom du club : « acgranville@orange.fr », « grenoblevv@gmail.com ».
 *
 * Une boîte qui porte le nom du contact est personnelle, quoi qu'elle contienne d'autre.
 *
 * @param  array<string,mixed> $r Ligne de fiche : name, contact, email, tarif
 * @return string                 Le signe retenu, vide s'il n'y en a aucun
 */
function rq_club_sign($r)
{
    if ($r['tarif'] !== '') {
        return 'tarif';
    }
    $strong = rq_strong();
    if ($r['contact'] === '' || rq_words($r['contact']) === rq_words($r['name'])
        || preg_match($strong['TE_AEROCLUB'], rq_norm($r['contact']))) {
        return 'sans personne';
    }

    $email = rq_real_email($r['email']);
    if ($email === '') {
        return '';
    }
    list($local, $domain) = explode('@', $email, 2);
    $flat = str_replace(' ', '', rq_norm($local));

    if (!preg_match('/^(gmail|googlemail|yahoo|ymail|rocketmail|hotmail|outlook|live|msn|icloud|me|mac|aol|gmx|proton|protonmail|pm'
        .'|orange|wanadoo|free|laposte|sfr|neuf|cegetel|club-internet|bbox|numericable|noos|aliceadsl|alice|nordnet'
        .'|voila|libertysurf|9online|dbmail|tele2|bluewin|skynet|telenet|hispeed|sunrise|web|t-online|mail|yopmail)[.]/', $domain)) {
        $site = str_replace(' ', '', rq_norm($domain));
        foreach (rq_words($r['contact']) as $w) {
            if (strlen($w) >= 4 && strpos($site, $w) !== false) {
                return '';
            }
        }

        return 'domaine';
    }

    foreach (rq_words($r['contact']) as $w) {
        if (strlen($w) >= 3 && strpos($flat, $w) !== false) {
            return '';
        }
    }
    if (preg_match('/aero|club|planeur|ulm|voile|avia|asso|contact|info|accueil|bureau|president|tresor|secretar|compta|admin/', $flat)
        || preg_match('/^ac[a-z]/', $flat)) {
        return 'boîte du club';
    }
    foreach (rq_words($r['name']) as $w) {
        if (strlen($w) >= 4 && strpos($flat, $w) !== false) {
            return 'boîte du club';
        }
    }

    return '';
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
        // « Association » a son type depuis la 0.42.0 ; « Autres » ne garde que ce qui n'en est pas une.
        'TE_ASSO'     => '/\b(association|asso|amicale|comite|federation|ligue)\b/',
        'TE_OTHER'    => '/\b(fondation|musee|syndicat|cse)\b/',
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
        'TE_ASSO'     => '/\b(section|cercle|union|amis)\b/',
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
 * Les types proposés au client, et le mot sous lequel il les écrit.
 *
 * Le dictionnaire en compte une quinzaine — « Grand groupe », « PME/PMI », « TPE »… — dont aucun
 * n'a jamais servi ici. Lui présenter la liste entière lui ferait choisir entre des nuances qui ne
 * changent rien : il ne voit que les sept qui décrivent sa clientèle (arbitrage du 06/10).
 *
 * @return array<string,string> code de type => libellé proposé
 */
function rq_choices()
{
    return array(
        'TE_ADMIN'    => 'Administration',
        'TE_AEROCLUB' => 'Aéro-club',
        'TE_ASSO'     => 'Association',
        'TE_PRIVATE'  => 'Particulier',
        // Type natif de Dolibarr, activé le 06/10 : la clientèle compte des revendeurs, l'une de ses
        // catégories tarifaires porte d'ailleurs ce nom.
        'TE_RETAIL'   => 'Revendeur',
        'TE_SOCIETE'  => 'Société',
        'TE_OTHER'    => 'Autre',
    );
}

/**
 * En-tête de la colonne que le client remplit : elle rappelle les valeurs admises.
 *
 * @return string
 */
function rq_choice_header()
{
    return 'type_a_retenir ('.implode(' / ', rq_choices()).')';
}

/**
 * Le type qu'une saisie du client désigne.
 *
 * Tolérant sur la forme — « aéroclub », « AERO-CLUB », « Autres », « asso » — parce que le fichier
 * revient d'un tableur, tapé à la main. Intransigeant sur le fond : une valeur qu'on ne reconnaît
 * pas n'est jamais devinée, elle est signalée avec son numéro de ligne.
 *
 * @param  string $value Saisie
 * @return string        Code de type, vide si la saisie n'est pas reconnue
 */
function rq_choice_code($value)
{
    $v   = rq_norm($value);
    $map = array(
        'administration' => 'TE_ADMIN',
        'aero club'      => 'TE_AEROCLUB',
        'aeroclub'       => 'TE_AEROCLUB',
        'association'    => 'TE_ASSO',
        'asso'           => 'TE_ASSO',
        'particulier'    => 'TE_PRIVATE',
        'revendeur'      => 'TE_RETAIL',
        'societe'        => 'TE_SOCIETE',
        'autre'          => 'TE_OTHER',
        'autres'         => 'TE_OTHER',
    );

    return isset($map[$v]) ? $map[$v] : '';
}

/**
 * Lit un CSV relu par le client : les fiches dont la colonne « type_a_retenir » est remplie.
 *
 * Le fichier revient d'un tableur : son séparateur peut être devenu une virgule, et son encodage du
 * Windows-1252 — c'est ce qu'Excel écrit quand on choisit « CSV (séparateur : point-virgule) ». Les
 * deux sont reconnus, et les colonnes se repèrent à leur en-tête, où qu'elles soient.
 *
 * @param  string $file    Fichier
 * @param  array  $problems Sortie : ce qui n'a pas pu être lu
 * @return array<int,string> identifiant du tiers => code de type
 */
function rq_read_choices($file, &$problems)
{
    $out = array();
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $problems[] = $file.' : fichier illisible';

        return $out;
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $first = isset($lines[0]) ? $lines[0] : '';
    $sep   = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';

    $head = array_map(function ($h) {
        return rq_norm($h);
    }, str_getcsv($first, $sep));
    $colId = array_search('id tiers', $head, true);
    $colTy = false;
    foreach ($head as $i => $h) {
        if (strpos($h, 'type a retenir') === 0) {
            $colTy = $i;
        }
    }
    if ($colId === false || $colTy === false) {
        $problems[] = $file.' : colonnes « id_tiers » et « type_a_retenir » introuvables';

        return $out;
    }

    foreach ($lines as $n => $line) {
        if ($n === 0 || trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, $sep);
        $value = isset($cells[$colTy]) ? trim($cells[$colTy]) : '';
        if ($value === '') {
            continue; // rien de saisi : on ne touche pas à la fiche
        }
        $id   = isset($cells[$colId]) ? (int) $cells[$colId] : 0;
        $code = rq_choice_code($value);
        if ($id <= 0 || $code === '') {
            $problems[] = basename($file).' ligne '.($n + 1).' : « '.$value.' » n\'est pas une valeur admise';
            continue;
        }
        $out[$id] = $code;
    }

    return $out;
}

/**
 * Écrit une passe de requalification, la compte, et dit comment la défaire.
 *
 * Commune aux trois passes du script. Chacune part d'UN type d'origine — « Particulier » pour le
 * classement, « Autres » pour la conversion en association — et c'est lui que l'annulation rétablit.
 *
 * @param  DoliDB $db      Base
 * @param  array  $perType code de type cible => identifiants de tiers
 * @param  array  $typent  Référentiel des types
 * @param  string $origin  Code du type d'origine
 * @param  string $extra   Condition supplémentaire sur les fiches à écrire
 * @return void
 */
function rq_apply($db, $perType, $typent, $origin, $extra = '')
{
    $total = 0;
    foreach ($perType as $ids) {
        $total += count($ids);
    }

    // La clé de la passe : elle marque chaque fiche touchée, et permet de tout défaire.
    //
    // QUATORZE CARACTÈRES, pas un de plus : c'est la largeur de `llx_societe.import_key`. Une clé plus
    // longue serait tronquée à l'écriture sans que rien ne le dise, et la commande d'annulation
    // affichée plus bas — qui cite la clé entière — ne retrouverait alors aucune fiche. Constaté à
    // l'essai avec un préfixe : cinq fiches écrites, zéro retrouvée. L'horodatage seul tient juste.
    $key = dol_print_date(dol_now(), '%Y%m%d%H%M%S');

    $error = '';
    $db->begin();
    foreach ($perType as $code => $ids) {
        foreach (array_chunk($ids, CHUNK) as $chunk) {
            // La clause sur le type d'origine garde le script rejouable et sûr : une fiche requalifiée
            // à la main entre la lecture et l'écriture n'est pas reprise.
            $sql = 'UPDATE '.MAIN_DB_PREFIX.'societe SET fk_typent = '.$typent[$code]['id']
                .", import_key = '".$db->escape($key)."'"
                .' WHERE rowid IN ('.implode(',', $chunk).') AND fk_typent = '.$typent[$origin]['id'].$extra;
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

    // Le compte se refait sur la clé elle-même : s'il ne retrouve pas tout ce qui vient d'être envoyé
    // à l'écriture, la commande d'annulation ne le retrouvera pas non plus, et il faut le dire avant
    // de l'afficher. Un écart en moins est normal si des fiches ont changé de type entre-temps.
    if ($written !== $total) {
        echo "\nATTENTION : ".$total." fiche(s) envoyée(s) à l'écriture, ".$written." retrouvée(s) sous la clé ".$key.".\n";
    }

    echo "\n".$written." fiche(s) requalifiée(s).\n";
    echo "Clé de la passe : ".$key."\n";
    echo "Pour défaire :\n";
    echo "   UPDATE ".MAIN_DB_PREFIX."societe SET fk_typent = ".$typent[$origin]['id'].", import_key = NULL"
        ." WHERE import_key = '".$key."';\n";
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
foreach (array('TE_PRIVATE', 'TE_SOCIETE', 'TE_AEROCLUB', 'TE_ADMIN', 'TE_OTHER', 'TE_ASSO', 'TE_RETAIL') as $code) {
    if (empty($typent[$code]['id'])) {
        echo "Type de tiers absent du dictionnaire : ".$code." — désactiver puis réactiver aeromigration.\n";
        exit(1);
    }
}


/*
 * Passe « Autres → Association »
 *
 * Les tiers classés « Autres » l'ont tous été par la reprise, faute de mieux : leur qualité dans
 * l'ancien ERP était « Association », et le dictionnaire n'avait pas ce type. Maintenant qu'il
 * existe, ils le rejoignent.
 *
 * Seules les fiches SANS clé de passe sont converties : un « Autre » que le client a lui-même choisi
 * dans le fichier relu porte la clé de sa passe, et n'a pas à être défait par celle-ci.
 */

if ($otherToAsso) {
    echo "Base : ".$conf->db->name."   Mode : ".($confirm ? 'ÉCRITURE' : 'simulation')."\n\n";

    $ids   = array();
    $noms  = array();
    $resql = $db->query('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe'
        .' WHERE fk_typent = '.$typent['TE_OTHER']['id'].' AND import_key IS NULL ORDER BY rowid');
    while ($resql && ($o = $db->fetch_object($resql))) {
        $ids[] = (int) $o->rowid;
        if (count($noms) < SAMPLES) {
            $noms[] = (string) $o->nom;
        }
    }
    if ($limit > 0) {
        $ids = array_slice($ids, 0, $limit);
    }
    echo "Tiers classés « ".$typent['TE_OTHER']['label']." » par la reprise : ".count($ids)."\n";
    echo "   ".implode("\n   ", $noms)."\n";

    if (!$confirm) {
        echo "\nSimulation : rien n'a été écrit. ".count($ids)." fiche(s) passeraient en « ".$typent['TE_ASSO']['label']." » avec --confirm.\n";
        exit(0);
    }
    if (!$ids) {
        echo "\nRien à convertir.\n";
        exit(0);
    }
    rq_apply($db, array('TE_ASSO' => $ids), $typent, 'TE_OTHER', ' AND import_key IS NULL');
    exit(0);
}


/*
 * Passe « fichier relu »
 *
 * Le client a rempli la colonne « type_a_retenir ». Sa saisie fait foi, fiche par fiche ; une ligne
 * laissée vide n'est pas touchée, et « Particulier » dit seulement que la fiche est bien classée.
 */

if ($typesFiles) {
    echo "Base : ".$conf->db->name."   Mode : ".($confirm ? 'ÉCRITURE' : 'simulation')."\n\n";

    $choices  = array();
    $problems = array();
    foreach ($typesFiles as $file) {
        $read = rq_read_choices($file, $problems);
        echo basename($file)." : ".count($read)." fiche(s) avec un type saisi\n";
        $choices = $read + $choices;
    }

    // Les fiches encore « Particulier » : les autres ont déjà été reclassées, on ne les reprend pas.
    $still = array();
    foreach (array_chunk(array_keys($choices), 5000) as $chunk) {
        $resql = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe'
            .' WHERE rowid IN ('.implode(',', array_map('intval', $chunk)).') AND fk_typent = '.$typent['TE_PRIVATE']['id']);
        while ($resql && ($o = $db->fetch_object($resql))) {
            $still[(int) $o->rowid] = true;
        }
    }

    $perType = array();
    $kept    = 0;
    $gone    = 0;
    foreach ($choices as $id => $code) {
        if ($code === 'TE_PRIVATE') {
            $kept++;
            continue;
        }
        if (!isset($still[$id])) {
            $gone++;
            continue;
        }
        $perType[$code][] = (int) $id;
    }
    if ($limit > 0) {
        $left = $limit;
        foreach ($perType as $code => $ids) {
            $perType[$code] = array_slice($ids, 0, max(0, $left));
            $left -= count($perType[$code]);
        }
        $perType = array_filter($perType);
    }

    $labels = rq_choices();
    $total  = 0;
    echo "\nÀ requalifier :\n";
    foreach ($labels as $code => $label) {
        if ($code === 'TE_PRIVATE') {
            continue;
        }
        $n = isset($perType[$code]) ? count($perType[$code]) : 0;
        $total += $n;
        printf("   %-16s %6d\n", $label, $n);
    }
    printf("Laissées en « Particulier » à la demande du client : %d\n", $kept);
    printf("Déjà reclassées par ailleurs, ignorées             : %d\n", $gone);
    if ($problems) {
        echo "\nNon reconnu (".count($problems).") — rien n'est écrit pour ces lignes :\n   "
            .implode("\n   ", array_slice($problems, 0, 20))."\n";
    }

    if (!$confirm) {
        echo "\nSimulation : rien n'a été écrit. ".$total." fiche(s) seraient requalifiées avec --confirm.\n";
        exit(0);
    }
    if (!$total) {
        echo "\nRien à requalifier.\n";
        exit(0);
    }
    rq_apply($db, $perType, $typent, 'TE_PRIVATE');
    exit(0);
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

// Les niveaux de prix. Celui d'un tiers a été choisi par le gérant, fiche par fiche : « Aéro-Clubs »
// ou « Revendeur » sur un tiers classé « Particulier » en dit plus long que n'importe quel mot de
// son nom. Le premier niveau est le tarif de tout le monde, il ne dit rien.
$levels = array();
for ($i = 2; $i <= getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT'); $i++) {
    $label = trim(getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$i));
    if ($label !== '') {
        $levels[$i] = $label;
    }
}

/**
 * Le type qu'un niveau de prix suggère, d'après son libellé.
 *
 * @param  string $label Libellé du niveau
 * @return string        Code de type, vide si le libellé ne désigne aucune nature
 */
function rq_level_hint($label)
{
    $l = rq_norm($label);
    if (preg_match('/\brevendeurs?\b/', $l)) {
        return 'TE_RETAIL';
    }
    if (preg_match('/\b(aero ?clubs?|aeroclubs?)\b/', $l)) {
        return 'TE_AEROCLUB';
    }

    return '';
}


/*
 * Classement
 */

$strong = rq_strong();
$weak   = rq_weak();

$count = array('private' => 0, 'person' => 0, 'anonymized' => 0, 'variant' => 0, 'nocontact' => 0, 'shared' => 0,
    'special' => 0, 'samename' => 0, 'member' => 0, 'bylevel' => 0);
$lots  = array('classed' => array(), 'review' => array(), 'robots' => array());
$byType = array();
// Combien de fiches portent le même nom de structure : au-delà de deux, ce n'est plus une structure
// et son doublon, c'est un employeur que plusieurs personnes ont déclaré.
$sameName = array();

$sql = 'SELECT s.rowid, s.nom, s.code_client, s.email, s.town, s.zip, s.datec, s.price_level'
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

    $tarif = isset($levels[(int) $s->price_level]) ? $levels[(int) $s->price_level] : '';

    $inv = isset($invoices[$id]) ? $invoices[$id] : null;
    $row = array(
        'tarif'   => $tarif,
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

    // ── Le tarif tranche ──
    // « Aéro-Clubs » ou « Revendeur » : le gérant a accordé ce tarif fiche par fiche, il sait à qui.
    // La fiche prend le type que dit son tarif, quoi que dise son nom — y compris quand elle porte
    // celui d'une personne, le correspondant saisi à la place du club. Arbitrage du 06/10, sur le
    // retour du client : un aéro-club au bon tarif n'a pas à rester « Particulier ».
    //
    // Une exception : « VILLE DE NIORT » ou un syndicat mixte au tarif « Aéro-Clubs » ont un tarif de
    // faveur, ils ne sont pas devenus des aéro-clubs. Un nom de collectivité garde son type.
    $byLevel = rq_level_hint($tarif);
    if ($byLevel !== '' && !in_array(rq_family($name, $strong), array('TE_ADMIN', 'TE_OTHER'), true)) {
        $key            = rq_key($name, $ct ? rq_words($ct->lastname.' '.$ct->firstname) : array());
        $row['key']     = $key;
        $sameName[$key] = isset($sameName[$key]) ? $sameName[$key] + 1 : 1;
        $count['bylevel']++;
        $lots['classed'][] = $row + array('type' => $byLevel, 'word' => 'tarif '.$tarif, 'bylevel' => true);
        continue;
    }

    // Une fiche envoyée à la relecture : son tarif, s'il n'est pas celui de tout le monde, passe
    // devant tout autre indice — c'est le gérant qui l'a posé.
    $review = function ($hint, $word) use ($row, $tarif) {
        if ($tarif !== '') {
            $word = 'tarif « '.$tarif.' »'.($word !== '' ? ' ; '.$word : '');
        }

        return $row + array('hint' => $hint, 'word' => $word);
    };

    // Le nom du tiers est celui de son contact, à l'ordre, aux accents et aux tirets près : c'est une
    // personne, et la fiche est bien classée. SAUF si elle porte un tarif réservé qui ne dit pas de
    // type — « École de pilotage », « Airbus » : un « DUPONT Jean » à ce tarif n'est pas un client
    // comme les autres, et seul le gérant sait ce qu'il est.
    if ($ct) {
        $a = rq_norm($ct->lastname.' '.$ct->firstname);
        if ($name === $a || $name === rq_norm($ct->firstname.' '.$ct->lastname)
            || rq_words($s->nom) === rq_words($ct->lastname.' '.$ct->firstname)) {
            // Le contact a parfois été saisi au nom de la structure : tiers « ACBA AEROCLUB », contact
            // « ACBA AEROCLUB ». Les deux noms sont identiques, et ce n'est pourtant pas une personne.
            // Un mot fort le trahit — mais « Ligue » ou « Comité » sont aussi des noms de famille :
            // la fiche part à la relecture avec sa suggestion, elle n'est pas classée d'office.
            // « Aéroclub », lui, n'est le nom de famille de personne : la fiche est classée.
            $w        = '';
            $asEntity = rq_family($name, $strong, $w);
            if ($asEntity === 'TE_AEROCLUB') {
                $row['key']        = $name;
                $sameName[$name]   = isset($sameName[$name]) ? $sameName[$name] + 1 : 1;
                $lots['classed'][] = $row + array('type' => $asEntity, 'word' => $w);
            } elseif ($asEntity !== '') {
                $count['samename']++;
                $lots['review'][] = $review($asEntity, '« '.$w.' » ; porte le nom de son contact');
            } elseif ($tarif !== '') {
                $count['special']++;
                $lots['review'][] = $review('', 'porte le nom de son contact');
            } else {
                $count['person']++;
            }
            continue;
        }
    }

    // ── Fiche de robot ──
    if (rq_random($s->nom) && $ct && (rq_random($ct->lastname) || rq_random($ct->firstname))) {
        if (empty($hasDoc[$id])) {
            $lots['robots'][] = $row;
        } else {
            // Un nom tiré au hasard, mais une pièce : ce n'est pas à nous de trancher.
            $lots['review'][] = $review('', 'nom au hasard, mais a des pièces');
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
        $lots['review'][] = $review('', 'pas une société ? le nom devrait être celui du contact');
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
        $lots['review'][] = $review($hint, ($word !== '') ? '« '.$word.' »' : '');
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

    $lots['review'][] = $review($hint, ($word !== '') ? '« '.$word.' »' : '');
}
$db->free($resql);

// UN NOM PORTÉ PAR TROIS FICHES OU PLUS N'EST PAS CLASSÉ D'OFFICE.
//
// « AIRBUS OPERATIONS SAS » porte une forme juridique sans équivoque, et pourtant ses fiches sont des
// salariés d'Airbus qui achètent pour eux : en faire autant de « sociétés » serait faux. Deux fiches
// du même nom sont le plus souvent un doublon de la même structure, et restent classées.
//
// Les aéro-clubs font exception : un club n'a pas de salariés par dizaines, et ses trois ou quatre
// fiches sont les comptes que ses bénévoles successifs ont ouverts à son nom. Le nombre de fiches
// reste affiché dans le CSV, le gérant garde la main.
$kept = array();
foreach ($lots['classed'] as $r) {
    if ($sameName[$r['key']] >= 3 && $r['type'] !== 'TE_AEROCLUB' && empty($r['bylevel'])) {
        $count['shared']++;
        $lots['review'][] = array('word' => '« '.$r['word'].' » ; nom porté par '.$sameName[$r['key']].' fiches',
            'hint' => $r['type']) + $r;
        continue;
    }
    // Un aéro-club que rien ne confirme : peut-être un membre qui a cité son club. Au gérant de voir.
    if ($r['type'] === 'TE_AEROCLUB' && rq_club_sign($r) === '') {
        $count['member']++;
        $lots['review'][] = array('word' => '« '.$r['word'].' » ; '
            .(rq_real_email($r['email']) !== '' ? 'e-mail personnel' : 'sans e-mail').', tarif public',
            'hint' => $r['type']) + $r;
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
$choiceLabels = rq_choices();
foreach (array('TE_AEROCLUB', 'TE_SOCIETE', 'TE_ASSO', 'TE_ADMIN', 'TE_RETAIL', 'TE_OTHER') as $code) {
    printf("        %-16s %6d\n", $choiceLabels[$code], isset($byType[$code]) ? $byType[$code] : 0);
}
printf("        dont classées par leur tarif (Aéro-Clubs, Revendeur)        : %d\n", $count['bylevel']);
printf("LOT 2 — structures à relire (CSV, rien n'est écrit)     : %7d   dont %d facturées depuis %s\n",
    count($lots['review']), $recentOf($lots['review']), RECENT_FROM);
printf("        dont un mot fort, mais un nom porté par 3 fiches ou plus : %d\n", $count['shared']);
printf("        dont un aéro-club que rien ne confirme (membre du club ?)  : %d\n", $count['member']);
printf("        dont une personne à son nom, mais à un tarif réservé       : %d\n", $count['special']);
printf("        dont un contact saisi au nom de la structure               : %d\n", $count['samename']);
printf("LOT 3 — fiches de robots (CSV, rien n'est écrit)        : %7d   soit %.1f %% des particuliers\n",
    count($lots['robots']), $count['private'] ? 100 * count($lots['robots']) / $count['private'] : 0);

foreach (array('classed' => 'LOT 1', 'review' => 'LOT 2', 'robots' => 'LOT 3') as $key => $title) {
    echo "\n".$title." — exemples :\n";
    foreach (array_slice($lots[$key], 0, SAMPLES) as $r) {
        $tail = '';
        if ($key === 'classed') {
            $tail = '  → '.$choiceLabels[$r['type']].' (« '.$r['word'].' »)';
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
    // Les deux fichiers de structures portent la même colonne à remplir, dont l'en-tête rappelle les
    // valeurs admises. Dans le premier elle est déjà remplie — la proposition du script, que le
    // client corrige s'il n'est pas d'accord ; dans le second elle est vide. Les deux se relisent
    // avec --types.
    $write('structures_classees.csv',
        array('code_client', 'nom_du_tiers', rq_choice_header(), 'mot_reconnu', 'tarif', 'contact', 'email', 'ville',
            'nb_factures', 'derniere_facture', 'ca_ht_depuis_'.substr(RECENT_FROM, 0, 4), 'fiches_du_meme_nom', 'id_tiers'),
        $lots['classed'],
        function ($r) use ($choiceLabels, $money, $sameName) {
            return array($r['code'], $r['name'], $choiceLabels[$r['type']], $r['word'], $r['tarif'], $r['contact'], $r['email'],
                $r['town'], $r['nbinv'], $r['last'], $money($r['recent']), $sameName[$r['key']], $r['id']);
        });
    $write('structures_a_relire.csv',
        array('code_client', 'nom_du_tiers', rq_choice_header(), 'indice', 'tarif', 'contact', 'email', 'ville',
            'nb_factures', 'derniere_facture', 'ca_ht_depuis_'.substr(RECENT_FROM, 0, 4), 'fiches_du_meme_nom', 'id_tiers'),
        $lots['review'],
        function ($r) use ($choiceLabels, $money, $sameName) {
            // « Aéro-club ? (tarif « Aéro-Clubs ») » : la suggestion d'abord, ce qui la fonde ensuite.
            if ($r['hint'] !== '') {
                $hint = $choiceLabels[$r['hint']].' ? ('.$r['word'].')';
            } else {
                $hint = $r['word'];
            }
            $same = (isset($r['key']) && isset($sameName[$r['key']])) ? $sameName[$r['key']] : 1;

            return array($r['code'], $r['name'], '', $hint, $r['tarif'], $r['contact'], $r['email'], $r['town'],
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

$perType = array();
foreach ($todo as $r) {
    $perType[$r['type']][] = (int) $r['id'];
}
rq_apply($db, $perType, $typent, 'TE_PRIVATE');
