<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/set_default_single_tariff.php
 * \ingroup aeromigration
 * \brief   Un article qui n'a qu'un seul tarif fournisseur : ce tarif devient le tarif par défaut.
 *
 * ------------------------------------------------------------------------------
 * LA RÈGLE (demande client du 15/10/2026)
 * ------------------------------------------------------------------------------
 *
 * Pour tout article qui porte UN SEUL tarif fournisseur, ce tarif est le tarif par défaut — même à
 * 0 €. C'est l'étoile d'aerotoolbox : l'extrafield booléen `aerotb_default` de
 * `llx_product_fournisseur_price_extrafields`, que la Vue 360°, l'onglet Prix d'achat, le réappro
 * et la valorisation du stock lisent.
 *
 * Reprise de l'existant seulement : les tarifs saisis ensuite restent au choix du client, aucune
 * règle n'est posée à l'écriture. Complète set_default_supplier.php, qui étoile le fournisseur
 * principal d'ADD : un article à tarif unique sans étoile est celui qu'ADD ne désignait pas — ou
 * qui n'existait pas dans ADD.
 *
 * Tous les articles comptent, actifs ou non, déclinaisons comprises : la règle ne fait pas
 * d'exception. Un article qui porte déjà son étoile est compté « déjà posé ».
 *
 * L'écriture passe par `aerotb_price_default_set()` (aerotoolbox, lib/aeroreappro.lib.php) : une
 * seule étoile par article, ligne d'extrafields créée au besoin, en transaction.
 *
 * Usage :
 *   php set_default_single_tariff.php                 simulation (rapport complet), rien d'écrit
 *   php set_default_single_tariff.php --confirm       applique
 *   php set_default_single_tariff.php --ref=#00175    un seul article
 *   php set_default_single_tariff.php --limit=N --confirm    lot d'essai
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
dol_include_once('/aerotoolbox/lib/aeroreappro.lib.php');

if (!function_exists('aerotb_price_default_set')) {
    echo "aerotoolbox 1.45.0 ou plus est requis (helper aerotb_price_default_set introuvable).\n";
    exit(1);
}

/** Nombre d'exemples listés. */
const SAMPLES = 10;


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;
$onlyRef = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--ref=(.+)$/', $arg, $m)) {
        $onlyRef = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--ref=REF] [--limit=N]\n";
        exit(1);
    }
}


/*
 * Les articles à tarif unique, avec ce tarif et son éventuelle étoile (une requête).
 */

$sql  = 'SELECT p.rowid AS pid, p.ref, t.price_id, t.price, t.fournisseur, pe.aerotb_default AS star';
$sql .= ' FROM (SELECT pfp.fk_product, MIN(pfp.rowid) AS price_id, MIN(pfp.price) AS price, MIN(s.nom) AS fournisseur';
$sql .= '   FROM '.MAIN_DB_PREFIX.'product_fournisseur_price AS pfp';
$sql .= '   LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = pfp.fk_soc';
$sql .= '   WHERE pfp.entity IN ('.getEntity('productsupplierprice').')';
$sql .= '   GROUP BY pfp.fk_product HAVING COUNT(*) = 1) AS t';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = t.fk_product';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields AS pe ON pe.fk_object = t.price_id';
if ($onlyRef !== '') {
    $sql .= " WHERE p.ref = '".$db->escape($onlyRef)."'";
}
$sql .= ' ORDER BY p.ref';

$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture impossible : ".$db->lasterror()."\n";
    exit(1);
}
$rows = array();
while ($o = $db->fetch_object($resql)) {
    $rows[] = $o;
}
$db->free($resql);


/*
 * Pose
 */

echo "Script      : article à tarif fournisseur unique → ce tarif par défaut (étoile aerotoolbox)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo str_repeat('-', 60)."\n";

$stats   = array('unique' => count($rows), 'deja' => 0, 'poses' => 0, 'poses_zero' => 0, 'erreur' => 0);
$samples = array('poses' => array(), 'poses_zero' => array(), 'erreur' => array());

foreach ($rows as $o) {
    if ((int) $o->star === 1) {
        $stats['deja']++;
        continue;
    }
    if ($limit > 0 && ($stats['poses'] + $stats['erreur']) >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
    $isZero = ((float) $o->price == 0);
    $label  = $o->ref.' ('.trim((string) $o->fournisseur).', '.price((float) $o->price).' €)';
    if ($confirm && !aerotb_price_default_set($db, (int) $o->pid, (int) $o->price_id, true)) {
        $stats['erreur']++;
        if (count($samples['erreur']) < SAMPLES) {
            $samples['erreur'][] = $o->ref.' : '.$db->lasterror();
        }
        continue;
    }
    $stats['poses']++;
    $key = $isZero ? 'poses_zero' : 'poses';
    if ($isZero) {
        $stats['poses_zero']++;
    }
    if (count($samples[$key]) < SAMPLES) {
        $samples[$key][] = $label;
    }
}


/*
 * Rapport
 */

$n = function ($v) {
    return number_format($v, 0, ',', ' ');
};
printf("Articles à tarif unique           : %s\n", $n($stats['unique']));
printf("  déjà étoilés                    : %s\n", $n($stats['deja']));
printf("  étoiles %s                 : %s\n", $confirm ? 'posées ' : 'à poser', $n($stats['poses']));
printf("    dont tarif à 0 €              : %s\n", $n($stats['poses_zero']));
printf("  écritures refusées              : %s\n", $n($stats['erreur']));

foreach (array('poses' => 'Exemples (tarif chiffré)', 'poses_zero' => 'Exemples (tarif à 0 €)', 'erreur' => 'Erreurs') as $k => $title) {
    if (!empty($samples[$k])) {
        echo "\n".$title." :\n  ".implode("\n  ", $samples[$k])."\n";
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit($stats['erreur'] > 0 ? 1 : 0);
