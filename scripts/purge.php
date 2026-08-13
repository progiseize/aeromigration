<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/purge.php
 * \ingroup aeromigration
 * \brief   Annule ce qu'un script de reprise a produit, pour pouvoir le rejouer.
 *
 * Outil de mise au point : tant que le mapping n'est pas figé, il faut pouvoir défaire un
 * passage et le recommencer.
 *
 * Chaque script décrit lui-même comment se défaire (voir AeroMigrationRunner::purge) :
 *  - les scripts qui créent des objets suppriment ceux qui portent leur marqueur ref_ext,
 *    via l'API Dolibarr, sans jamais toucher à ce qui a été saisi à la main ;
 *  - les scripts qui se contentent d'enrichir des objets existants annulent leur propre
 *    écriture — la reprise des désinscriptions, par exemple, réinscrit les adresses
 *    qu'elle avait exclues, sans supprimer le moindre tiers.
 *
 * Usage :
 *   php purge.php <script> [--confirm] [--user=LOGIN] [--legacy] [--all]
 *
 * Sans --confirm, le script se contente de dénombrer ce qui serait défait.
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

if (substr($sapi_type, 0, 3) === 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}

require_once $path.'../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));


/*
 * Arguments
 */

$scriptCode = '';
$confirm    = false;
$userLogin  = '';
$legacy     = false;
$purgeAll   = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--all') {
        // Défaire au-delà de la reprise : vider ce que d'autres canaux ont posé. Réservé
        // aux scripts qui savent le faire, et à l'avant-mise en service.
        $purgeAll = true;
    } elseif ($arg === '--legacy') {
        // Ne défaire que ce qu'un modèle abandonné a laissé. Aujourd'hui, les
        // sous-entrepôts créés du temps où chaque emplacement en était un : les
        // supprimer sans emporter l'entrepôt qui porte désormais tout le stock.
        $legacy = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif ($scriptCode === '' && substr($arg, 0, 2) !== '--') {
        $scriptCode = $arg;
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        exit(1);
    }
}

$scripts    = aeromigrationGetScripts();
$definition = null;
foreach ($scripts as $s) {
    if ($s['code'] === $scriptCode) {
        $definition = $s;
        break;
    }
}

if ($definition === null) {
    echo "Usage: php ".$script_file." <script> [--confirm] [--user=LOGIN] [--legacy] [--all]\n\n";
    echo "Scripts disponibles :\n";
    foreach ($scripts as $s) {
        echo "  ".$s['code']."\n";
    }
    exit(1);
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
    $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1';
    $sql .= ' AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid ASC LIMIT 1';
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
 * Exécution
 */

dol_include_once($definition['file']);

/** @var AeroMigrationRunner $runner */
$runner = new $definition['class']($db, $user);

// Tous les scripts n'ont pas de vestiges à distinguer : l'option n'a d'effet que sur
// ceux qui exposent la propriété.
if ($legacy) {
    if (!property_exists($runner, 'legacyOnly')) {
        echo "L'option --legacy ne s'applique pas au script ".$definition['code'].".
";
        exit(1);
    }
    $runner->legacyOnly = true;
}

if ($purgeAll) {
    if (!property_exists($runner, 'purgeAll')) {
        echo "L'option --all ne s'applique pas au script ".$definition['code'].".
";
        exit(1);
    }
    $runner->purgeAll = true;
}

echo "Script    : ".$definition['code']."\n";
echo "Opération : ".$runner->getPurgeDescription()."\n";

$progress = function ($done, $total) {
    printf("\r  %d/%d traité(s)   ", $done, $total);
};

$result = $runner->purge($confirm, $progress);

echo "Concernés : ".$result['count']." enregistrement(s)\n";

if ($result['count'] === 0) {
    echo "Rien à faire.\n";
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            echo "  ".$err."\n";
        }
    }
    $db->close();
    exit(empty($result['errors']) ? 0 : 1);
}

if (!$confirm) {
    echo "\nSimulation : aucune modification effectuée.\n";
    echo "Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}

echo "\nTraités   : ".$result['deleted']."\n";

// Certains scripts ne suppriment pas tout ce qu'ils ont marqué : une facture créée par la
// boutique et seulement adoptée par la reprise perd son marqueur, mais reste en place.
// Confondre les deux dans un total unique laisse craindre une suppression qui n'a pas lieu.
if (!empty($result['unmarked'])) {
    echo "  dont supprimé(s)  : ".($result['deleted'] - $result['unmarked'])."\n";
    echo "  dont démarqué(s)  : ".$result['unmarked']." (objet conservé, marqueur de reprise retiré)\n";
}

echo "En échec  : ".$result['failed']."\n";

if (!empty($result['errors'])) {
    echo "\nDétail (20 premiers) :\n";
    foreach (array_slice($result['errors'], 0, 20) as $err) {
        echo "  ".$err."\n";
    }
}

$db->close();

exit($result['failed'] > 0 ? 1 : 0);
