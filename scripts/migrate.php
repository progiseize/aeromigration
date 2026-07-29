<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/migrate.php
 * \ingroup aeromigration
 * \brief   Lanceur en ligne de commande des scripts de reprise de données.
 *
 * La reprise se lance en CLI et jamais depuis le navigateur : les volumes en jeu
 * dépassent largement les limites de temps d'exécution et de mémoire d'une requête web.
 *
 * Usage :
 *   php migrate.php <script> [options]
 *
 * Options :
 *   --dry-run        Simulation : lit la source et compte, sans rien écrire
 *   --limit=N        S'arrête après N enregistrements traités
 *   --batch=N        Taille des tranches de lecture (défaut : 200)
 *   --cursor=N       Reprend le parcours après cette valeur de curseur
 *   --update         Met à jour les objets déjà migrés au lieu de les ignorer
 *   --user=LOGIN     Utilisateur au nom duquel créer les objets (défaut : 1er admin)
 *   --date=AAAA-MM-JJ  Date des écritures qui n'en ont pas dans la source
 *
 * Exemples :
 *   php migrate.php thirdparty --dry-run
 *   php migrate.php thirdparty --limit=50
 *   php migrate.php thirdparty --cursor=48120
 *   php migrate.php stock --date=2026-08-01
 */

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', '1');
}
if (!defined('NOSESSION')) {
    define('NOSESSION', '1');
}

$sapi_type   = php_sapi_name();
$script_file = basename(__FILE__);
$path        = __DIR__.'/';

// Refus du mode web : ce script n'a rien à faire derrière un serveur HTTP.
if (substr($sapi_type, 0, 3) === 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}

require_once $path.'../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));


/*
 * Lecture des arguments
 */

$scriptCode     = '';
$dryrun         = false;
$limit          = 0;
$batch          = 200;
$cursor         = null;
$updateExisting = false;
$userLogin      = '';
$referenceDate  = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--dry-run') {
        $dryrun = true;
    } elseif ($arg === '--update') {
        $updateExisting = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batch = (int) $m[1];
    } elseif (preg_match('/^--cursor=(.*)$/', $arg, $m)) {
        // Le curseur peut être un entier ou une chaîne selon la table source.
        $cursor = $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--date=(\d{4})-(\d{2})-(\d{2})$/', $arg, $m)) {
        // Date des écritures que la source ne date pas — un stock d'ouverture, par
        // exemple. Sans cette option, elles portent l'instant du passage.
        //
        // dol_mktime() plutôt que dol_stringtotime() : cette dernière vit dans
        // date.lib.php, que le bootstrap CLI ne charge pas.
        $referenceDate = dol_mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1], 'tzserver');
        if (empty($referenceDate) || $referenceDate <= 0) {
            echo "Date non exploitable : ".$m[1].'-'.$m[2].'-'.$m[3]."\n";
            exit(1);
        }
    } elseif ($scriptCode === '' && substr($arg, 0, 2) !== '--') {
        $scriptCode = $arg;
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        exit(1);
    }
}

$scripts = aeromigrationGetScripts();

if ($scriptCode === '') {
    echo "Usage: php ".$script_file." <script> [--dry-run] [--limit=N] [--batch=N] [--cursor=N] [--update] [--user=LOGIN] [--date=AAAA-MM-JJ]\n\n";
    echo "Scripts disponibles :\n";
    if (empty($scripts)) {
        echo "  (aucun)\n";
    } else {
        foreach ($scripts as $s) {
            echo "  ".str_pad($s['code'], 16).$langs->trans($s['label'])."\n";
        }
    }
    exit(1);
}

$definition = null;
foreach ($scripts as $s) {
    if ($s['code'] === $scriptCode) {
        $definition = $s;
        break;
    }
}
if ($definition === null) {
    echo "Script inconnu : ".$scriptCode."\n";
    exit(1);
}


/*
 * Utilisateur au nom duquel les objets seront créés
 */

$user = new User($db);

if ($userLogin !== '') {
    if ($user->fetch(0, $userLogin) <= 0) {
        echo "Utilisateur introuvable : ".$userLogin."\n";
        exit(1);
    }
} else {
    // À défaut, le premier administrateur actif.
    $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1';
    $sql .= ' AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid ASC LIMIT 1';
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) === 0) {
        echo "Aucun administrateur actif trouvé. Précisez --user=LOGIN.\n";
        exit(1);
    }
    $obj = $db->fetch_object($resql);
    $db->free($resql);
    if ($user->fetch((int) $obj->rowid) <= 0) {
        echo "Impossible de charger l'administrateur.\n";
        exit(1);
    }
}
$user->loadRights();


/*
 * Exécution
 */

dol_include_once($definition['file']);

/** @var AeroMigrationRunner $runner */
$runner = new $definition['class']($db, $user);

$runner->dryrun      = $dryrun;
$runner->limit       = $limit;
$runner->batchSize   = $batch;
$runner->startCursor = $cursor;

// --update force la mise à jour, mais son absence ne doit pas écraser le réglage d'un
// script qui agit par nature sur des enregistrements existants.
if ($updateExisting) {
    $runner->updateExisting = true;
}

// Même précaution : sans --date, on laisse au script sa propre valeur.
if ($referenceDate > 0) {
    $runner->referenceDate = $referenceDate;
}

// countSource() tient compte du curseur : sur une reprise, il retourne ce qui reste à
// lire, non la source entière. La limite s'y ajoute pour donner le nombre de lignes que
// ce passage traitera réellement — c'est sur lui que se calcule la progression.
$remaining = $runner->countSource();
$total     = ($limit > 0 && $limit < $remaining) ? $limit : $remaining;

echo "Script          : ".$langs->trans($definition['label'])." (".$definition['code'].")\n";
echo "Utilisateur     : ".$user->login."\n";
echo "Mode            : ".($dryrun ? "SIMULATION (aucune écriture)" : "ÉCRITURE")."\n";
echo "Tranche         : ".$batch."\n";
if ($cursor !== null && $cursor !== '') {
    echo "Reprise après   : ".$cursor."\n";
    echo "Restant à lire  : ".$remaining." enregistrement(s)\n";
} else {
    echo "Source          : ".$remaining." enregistrement(s)\n";
}
if ($limit > 0) {
    echo "Limite          : ".$limit."\n";
}
if ($total !== $remaining) {
    echo "Ce passage      : ".$total." enregistrement(s)\n";
}
if ($runner->referenceDate > 0) {
    echo "Date des écritures : ".dol_print_date($runner->referenceDate, 'day')."\n";
}
echo str_repeat('-', 60)."\n";

$timeStart = microtime(true);

// Progression affichée après chaque tranche : sur une reprise longue, il faut pouvoir
// suivre l'avancement et récupérer le curseur en cas d'interruption.
$runner->progressCallback = function ($stats, $cursor) use ($total) {
    $done = $stats['read'];
    $pct  = ($total > 0) ? round($done * 100 / $total, 1) : 0;
    printf(
        "\r%7d/%-7d (%5.1f%%)  créés %-7d adoptés %-6d maj %-6d ignorés %-7d erreurs %-5d  curseur %-18s",
        $done,
        $total,
        $pct,
        $stats['created'],
        $stats['adopted'],
        $stats['updated'],
        $stats['skipped'],
        $stats['error'],
        (string) $cursor
    );
};

$result = $runner->run();

$duration = microtime(true) - $timeStart;

echo "\n".str_repeat('-', 60)."\n";
echo "Lus             : ".$runner->stats['read']."\n";
echo "Créés           : ".$runner->stats['created']."\n";
echo "Adoptés         : ".$runner->stats['adopted']."  (déjà présents, complétés)\n";
echo "Mis à jour      : ".$runner->stats['updated']."\n";
// « Ignorés » ne veut pas dire « déjà migrés » partout : un script peut aussi écarter une
// ligne qu'il ne sait pas rattacher. C'est le rapport de fin de passage qui en donne la
// ventilation, le récapitulatif se garde d'en préjuger.
echo "Ignorés         : ".$runner->stats['skipped']."\n";
echo "En erreur       : ".$runner->stats['error']."\n";
echo "Durée           : ".round($duration, 1)." s\n";
echo "Dernier curseur : ".$runner->lastCursor."\n";

$report = $runner->getReport();
if (!empty($report)) {
    echo "\n";
    foreach ($report as $line) {
        echo $line."\n";
    }
}

if (!empty($runner->errors)) {
    echo "\nDétail des erreurs (".count($runner->errors)."), 20 premières :\n";
    foreach (array_slice($runner->errors, 0, 20) as $err) {
        echo "  [".$err['key']."] ".$err['message']."\n";
    }
}

$db->close();

exit($result > 0 ? 0 : 1);
