<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/purge.php
 * \ingroup aeromigration
 * \brief   Supprime les objets créés par une reprise, pour repartir d'une base propre.
 *
 * Outil de mise au point : tant que le mapping n'est pas figé, il faut pouvoir annuler
 * un passage et le rejouer. La sélection s'appuie exclusivement sur le ref_ext posé par
 * la reprise (préfixe « SAGE: ») : un enregistrement créé manuellement dans Dolibarr,
 * qui n'a pas ce marqueur, ne peut pas être touché.
 *
 * La suppression passe par l'API Dolibarr (delete()), afin que les données liées
 * (extrafields, catégories, contacts…) et les triggers soient traités correctement.
 *
 * Usage :
 *   php purge.php <script> [--confirm] [--user=LOGIN]
 *
 * Sans --confirm, le script se contente de dénombrer ce qui serait supprimé.
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));


/*
 * Arguments
 */

$scriptCode = '';
$confirm    = false;
$userLogin  = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
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
    echo "Usage: php ".$script_file." <script> [--confirm] [--user=LOGIN]\n\n";
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
 * Cibles
 */

dol_include_once($definition['file']);

/** @var AeroMigrationRunner $runner */
$runner = new $definition['class']($db, $user);
$prefix = $runner->refExtPrefix;

// Seule la table cible du script est concernée, et uniquement les lignes marquées.
$targetTable = 'societe';

$sql = 'SELECT rowid, nom, ref_ext FROM '.MAIN_DB_PREFIX.$targetTable;
$sql .= " WHERE entity IN (".getEntity($targetTable).")";
$sql .= " AND ref_ext LIKE '".$db->escape($prefix)."%'";
$sql .= ' ORDER BY rowid';

$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

$targets = array();
while ($obj = $db->fetch_object($resql)) {
    $targets[] = $obj;
}
$db->free($resql);

echo "Script     : ".$definition['code']."\n";
echo "Marqueur   : ref_ext commençant par « ".$prefix." »\n";
echo "Concernés  : ".count($targets)." enregistrement(s)\n";

if (empty($targets)) {
    echo "Rien à supprimer.\n";
    $db->close();
    exit(0);
}

if (!$confirm) {
    echo "\nSimulation : aucune suppression effectuée.\n";
    echo "Relancez avec --confirm pour supprimer réellement.\n";
    $db->close();
    exit(0);
}


/*
 * Suppression
 */

$deleted = 0;
$failed  = 0;
$errors  = array();

foreach ($targets as $target) {
    $societe = new Societe($db);
    if ($societe->fetch((int) $target->rowid) <= 0) {
        $failed++;
        $errors[] = $target->ref_ext.' : chargement impossible';
        continue;
    }

    $db->begin();
    $result = $societe->delete((int) $target->rowid, $user);
    if ($result > 0) {
        $db->commit();
        $deleted++;
    } else {
        $db->rollback();
        $failed++;
        $message = !empty($societe->error) ? $societe->error : 'erreur inconnue';
        if (!empty($societe->errors)) {
            $message .= ' | '.implode(' | ', $societe->errors);
        }
        $errors[] = $target->ref_ext.' : '.$message;
    }

    if (($deleted + $failed) % 200 === 0) {
        printf("\r  %d/%d traité(s)   ", $deleted + $failed, count($targets));
    }
}

echo "\nSupprimés  : ".$deleted."\n";
echo "En échec   : ".$failed."\n";

if (!empty($errors)) {
    echo "\nDétail (20 premiers) :\n";
    foreach (array_slice($errors, 0, 20) as $err) {
        echo "  ".$err."\n";
    }
}

$db->close();

exit($failed > 0 ? 1 : 0);
