<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/fix_levels_above_base.php
 * \ingroup aeromigration
 * \brief   Ramène au prix de base les niveaux de prix qui lui sont supérieurs.
 *
 * Règle posée par le client le 18/09 : une catégorie tarifaire (Aéro-Clubs, Revendeur, Airbus,
 * École, ENAC, FFA) est une remise sur le prix public, jamais une majoration. Un niveau 2 à 7
 * au-dessus du niveau 1 est une anomalie — reprise d'une ligne ADD dépassée, retouche du niveau 1
 * après coup, indexation calculée sur une base fausse — et se corrige en revenant au prix de base.
 *
 * Sur la copie du 17/09 : 19 articles, 25 niveaux (fichier
 * `niveaux_au_dessus_du_prix_de_base_20260918.csv`, racine du projet).
 *
 * Le niveau est réécrit par `updatePrice()` au prix HT du niveau 1, indexé sur lui à 0 % — ou,
 * si une règle de famille ADD vaut pour cette catégorie (z_tarifparticulier sans article, statut
 * O, dates ouvertes), à la remise de cette règle : le prix fixe obsolète masquait la règle, qui
 * reprend la main une fois le prix retiré (cas du `#11390`, Aéro-Clubs −5 % par la famille 11).
 * L'indexation (aerotb_price_follow / aerotb_price_pct) passe par le contexte lu par le trigger
 * d'aerotoolbox, comme la reprise. Le niveau 1 n'est jamais touché, `llx_product.price` non plus.
 * L'envoi vers la boutique, s'il est activé, suit par le trigger PRODUCT_PRICE_MODIFY.
 *
 * Usage :
 *   php fix_levels_above_base.php                 simulation
 *   php fix_levels_above_base.php --confirm       applique
 *   php fix_levels_above_base.php --ref=#01024    un seul article
 *   php fix_levels_above_base.php --csv=FICHIER   écrit la liste (article, niveau, avant, après)
 *   php fix_levels_above_base.php --user=LOGIN
 */

foreach (array('NOTOKENRENEWAL', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOLOGIN', 'NOSESSION') as $c) {
    if (!defined($c)) {
        define($c, '1');
    }
}
if (substr(php_sapi_name(), 0, 3) === 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".basename(__FILE__)." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}

require_once __DIR__.'/../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/aerotoolbox/lib/aeroprice.lib.php');
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$confirm   = false;
$userLogin = '';
$csvPath   = '';
$onlyRef   = '';
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--csv=(.+)$/', $arg, $m)) {
        $csvPath = $m[1];
    } elseif (preg_match('/^--ref=(.+)$/', $arg, $m)) {
        $onlyRef = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\nUsage: php ".basename(__FILE__)." [--confirm] [--ref=REF] [--csv=FICHIER] [--user=LOGIN]\n";
        exit(1);
    }
}

$user = new User($db);
if ($userLogin !== '') {
    if ($user->fetch(0, $userLogin) <= 0) {
        echo "Utilisateur introuvable : ".$userLogin."\n";
        exit(1);
    }
} else {
    $resql = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1'
        .' AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid ASC LIMIT 1');
    if (!$resql || !($o = $db->fetch_object($resql))) {
        echo "Aucun administrateur actif trouvé. Précisez --user=LOGIN.\n";
        exit(1);
    }
    $user->fetch((int) $o->rowid);
}
$user->loadRights();

// L'indexation sur le niveau 1 : posée si aerotoolbox la porte (colonnes présentes).
$ruleSupported = function_exists('aerotbPricePctFromAmount')
    && $db->query('SELECT '.AEROTB_PRICE_COL_PCT.', '.AEROTB_PRICE_COL_FOLLOW.' FROM '.MAIN_DB_PREFIX.'product_price LIMIT 1', 1);

// Les règles de famille ADD encore vivantes : catégorie → famille → remise %.
$srcDb = trim(getDolGlobalString('AEROMIG_SOURCE_DB', ''));
$src = ($srcDb === '' || $srcDb === $db->database_name) ? '' : '`'.$srcDb.'`.';
$familyRules = array();
$resql = $db->query('SELECT N_CatTarif, CL_No, remise FROM '.$src.'z_tarifparticulier'
    ." WHERE COALESCE(TRIM(AR_Ref), '') = '' AND CL_No > 0 AND AG_No1 = 0 AND COALESCE(statut, '') <> 'S'"
    ." AND remise <> 0 AND AR_DateDebut <= NOW() AND AR_DateFin >= NOW()", 1);
while ($resql && ($o = $db->fetch_object($resql))) {
    $cat = (int) $o->N_CatTarif;
    $cl  = (int) $o->CL_No;
    if (!isset($familyRules[$cat][$cl]) || (float) $o->remise > $familyRules[$cat][$cl]) {
        $familyRules[$cat][$cl] = (float) $o->remise;
    }
}
if (!$resql) {
    echo "Règles de famille ADD illisibles (".($src ?: 'base courante').") : les niveaux reviennent au prix de base sans règle.\n";
}

echo "Mode : ".($confirm ? 'ÉCRITURE' : 'SIMULATION')."\n";
echo str_repeat('-', 60)."\n";

/*
 * Les niveaux au-dessus du niveau 1 : dernière ligne de chaque couple (produit, niveau).
 */
$sql = 'SELECT p.rowid AS pid, p.ref, p.label, p.tva_tx, p.tosell,'
    .' l.price_level, l.price AS lvl_ht, l.price_ttc AS lvl_ttc, l.date_price,'
    .' b.price AS base_ht, b.price_ttc AS base_ttc,'
    .' a.CL_No1, a.CL_No2, a.CL_No3, a.CL_No4'
    .' FROM '.MAIN_DB_PREFIX.'product_price l'
    .' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = l.fk_product'
    .' LEFT JOIN '.$src.'f_article a ON a.AR_Ref = CAST(SUBSTRING(p.ref, 2) AS UNSIGNED)'
    .' INNER JOIN '.MAIN_DB_PREFIX.'product_price b ON b.fk_product = l.fk_product AND b.price_level = 1'
    .'   AND b.rowid = (SELECT MAX(rowid) FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product = l.fk_product AND price_level = 1)'
    .' WHERE l.price_level > 1'
    .'   AND l.rowid = (SELECT MAX(rowid) FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product = l.fk_product AND price_level = l.price_level)'
    .'   AND l.price > b.price + 0.005'
    .'   AND p.entity IN ('.getEntity('product').')'
    .($onlyRef !== '' ? " AND p.ref = '".$db->escape($onlyRef)."'" : '')
    .' ORDER BY p.ref, l.price_level';
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture impossible : ".$db->lasterror()."\n";
    exit(1);
}

$rows = array();
while ($o = $db->fetch_object($resql)) {
    $rows[] = $o;
}
$byProduct = array();
foreach ($rows as $o) {
    $byProduct[(int) $o->pid][] = $o;
}
printf("%d niveau(x) au-dessus du prix de base, sur %d article(s)\n\n", count($rows), count($byProduct));

$labels = array(2 => 'Aéro-Clubs', 3 => 'Revendeur', 4 => 'Airbus', 5 => 'École de pilotage', 6 => 'Marché ENAC', 7 => 'FFA');
$fh = null;
if ($csvPath !== '') {
    $fh = fopen($csvPath, 'w');
    if (!$fh) {
        echo "Fichier impossible à écrire : ".$csvPath."\n";
        exit(1);
    }
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('Réf', 'Libellé', 'Niveau', 'Catégorie', 'Base HT', 'Base TTC', 'Avant HT', 'Avant TTC', 'Écart %', 'Date du prix', 'Après HT', 'Règle de famille %', 'Action'), ';');
}

$done = 0;
$failed = 0;
foreach ($byProduct as $pid => $levels) {
    $first = $levels[0];
    printf("%-8s %-50s base %s HT\n", $first->ref, dol_trunc($first->label, 48), price2num($first->base_ht, 'MU'));

    $object = null;
    if ($confirm) {
        $object = new Product($db);
        if ($object->fetch($pid) <= 0) {
            echo "   article introuvable\n";
            $failed += count($levels);
            continue;
        }
    }

    foreach ($levels as $o) {
        $level = (int) $o->price_level;
        $pct = round(((float) $o->lvl_ht / (float) $o->base_ht - 1) * 100, 2);
        $action = $confirm ? 'corrigé' : 'à corriger';

        // Cible : le prix de base, ou la règle de famille de la catégorie si l'article en a une
        // (la plus forte de ses familles, comme le moteur de reprise).
        $cat = aeromigration_price_category($level);
        $rule = 0.0;
        foreach (array($o->CL_No1, $o->CL_No2, $o->CL_No3, $o->CL_No4) as $cl) {
            $cl = (int) $cl;
            if ($cl > 0 && isset($familyRules[$cat][$cl]) && $familyRules[$cat][$cl] > $rule) {
                $rule = $familyRules[$cat][$cl];
            }
        }
        $targetHt = ($rule > 0) ? round((float) $o->base_ht * (1 - $rule / 100), 8) : (float) $o->base_ht;
        printf("   niveau %d %-18s %10s HT  (+%s %%, prix du %s) → %s HT%s\n", $level, $labels[$level] ?? '',
            price2num($o->lvl_ht, 'MU'), $pct, dol_print_date($db->jdate($o->date_price), 'day'), price2num($targetHt, 'MU'),
            $rule > 0 ? ' (règle de famille −'.$rule.' %)' : '');

        if ($confirm) {
            if ($ruleSupported) {
                $object->context['aerotb_price_rule'] = array('pct' => $rule, 'follow' => true);
            }
            // $ignore_autogen = 1 : ne pas régénérer les autres niveaux depuis le premier.
            $r = $object->updatePrice($targetHt, 'HT', $user, (float) $o->tva_tx, 0, $level, 0, 0, 1);
            unset($object->context['aerotb_price_rule']);
            if ($r <= 0) {
                $action = 'ÉCHEC : '.($object->error ?: implode(', ', $object->errors));
                echo "   ".$action."\n";
                $failed++;
            } else {
                $done++;
            }
        } else {
            $done++;
        }
        if ($fh) {
            fputcsv($fh, array($o->ref, $o->label, $level, $labels[$level] ?? '', price2num($o->base_ht, 'MU'), price2num($o->base_ttc, 'MU'),
                price2num($o->lvl_ht, 'MU'), price2num($o->lvl_ttc, 'MU'), $pct, dol_print_date($db->jdate($o->date_price), 'day'),
                price2num($targetHt, 'MU'), $rule > 0 ? $rule : '', $action), ';');
        }
    }
}
if ($fh) {
    fclose($fh);
    echo "\nFichier : ".$csvPath."\n";
}

echo "\n";
if ($confirm) {
    printf("%d niveau(x) corrigé(s), %d échec(s)\n", $done, $failed);
} else {
    printf("%d niveau(x) à corriger. Simulation : rien n'a été écrit. Relancez avec --confirm.\n", $done);
}
exit($failed ? 1 : 0);
