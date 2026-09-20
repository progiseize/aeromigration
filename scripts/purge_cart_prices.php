<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/purge_cart_prices.php
 * \ingroup aeromigration
 * \brief   Supprime de la boutique les prix spécifiques orphelins « client + panier » (id_customer > 0 et id_cart > 0).
 *
 * Ce sont des prix forcés à la main sur des paniers saisis en back-office (2019 pour l'essentiel),
 * dont les paniers ont été purgés depuis : PrestaShop ne les applique qu'au panier d'origine,
 * disparu, ils n'ont donc aucun effet — sauf à encombrer la table. Suppression par le webservice,
 * ligne par ligne, avec trace CSV et arrêt après 20 échecs consécutifs (boutique injoignable).
 * Rejouable : il repart de ce qui reste.
 *
 * Usage :
 *   php purge_cart_prices.php                         simulation : compte et ventilation, rien d'écrit
 *   php purge_cart_prices.php --confirm --csv=FICHIER supprime, trace chaque ligne dans le CSV
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
dol_include_once('/aeropresta/lib/aeropresta_state.lib.php');

$confirm = false;
$csv = '';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--csv=(.+)$/', $argv[$i], $m)) {
        $csv = $m[1];
        if (strpos($csv, '~') === 0) {
            $csv = getenv('HOME').substr($csv, 1);
        }
    } else {
        echo "Argument non reconnu : ".$argv[$i]."\nUsage: php ".basename(__FILE__)." [--confirm] [--csv=FICHIER]\n";
        exit(1);
    }
}

$shop = aeropresta_ps_shop($db);
if (!$shop) {
    echo "Boutique absente.\n";
    exit(1);
}
echo "Boutique : ".$shop->shop_url."\n";
echo "Mode     : ".($confirm ? 'SUPPRESSION' : 'SIMULATION')."\n";
echo str_repeat('-', 60)."\n";

// Lecture : toutes les lignes portant un client ET un panier.
$fields = array('id', 'id_cart', 'id_product', 'id_product_attribute', 'id_group', 'id_customer', 'price', 'reduction', 'reduction_type', 'from', 'to');
$rows = array();
$page = 500;
for ($offset = 0; $offset < 1000000; $offset += $page) {
    $q = 'specific_prices?output_format=JSON&display=['.implode(',', $fields).']&filter[id_customer]=>[0]&sort=[id_ASC]&limit='.$offset.','.$page;
    list($code, $body) = aeropresta_ps_ws($shop, 'GET', $q);
    if ($code === 404) {
        break;
    }
    if ($code !== 200) {
        echo "Lecture specific_prices : HTTP ".$code.' '.aeropresta_ps_ws_error($body)."\n";
        exit(1);
    }
    $json = json_decode($body, true);
    $got = $json['specific_prices'] ?? array();
    if (!$got) {
        break;
    }
    foreach ($got as $r) {
        if ((int) $r['id_customer'] > 0 && (int) $r['id_cart'] > 0) {
            $rows[] = $r;
        } elseif ((int) $r['id_customer'] > 0) {
            echo "  ! ligne ".$r['id']." : client ".$r['id_customer']." sans panier — laissée (vrai tarif nominatif ?)\n";
        }
    }
    if (count($got) < $page) {
        break;
    }
}

$byCustomer = array();
foreach ($rows as $r) {
    $byCustomer[(int) $r['id_customer']] = ($byCustomer[(int) $r['id_customer']] ?? 0) + 1;
}
arsort($byCustomer);
echo count($rows)." ligne(s) client + panier à supprimer, ".count($byCustomer)." client(s)\n";
$k = 0;
foreach ($byCustomer as $cid => $n) {
    if ($k++ >= 5) {
        echo "  …\n";
        break;
    }
    echo "  client ".$cid." : ".$n."\n";
}
if (!$confirm || !$rows) {
    if (!$confirm && $rows) {
        echo "\nSimulation : rien n'a été supprimé. Relancer avec --confirm --csv=FICHIER.\n";
    }
    exit(0);
}

$fh = null;
if ($csv !== '') {
    $fh = fopen($csv, 'w');
    if (!$fh) {
        echo "Impossible d'écrire ".$csv."\n";
        exit(1);
    }
    fputcsv($fh, array_merge($fields, array('resultat')), ';');
}

$done = 0;
$failed = 0;
$streak = 0;
foreach ($rows as $i => $r) {
    list($code, $resp) = aeropresta_ps_ws($shop, 'DELETE', 'specific_prices/'.((int) $r['id']));
    $ok = ($code === 200 || $code === 404);   // 404 : déjà partie
    if ($ok) {
        $done++;
        $streak = 0;
    } else {
        $failed++;
        $streak++;
        echo "  échec DELETE specific_prices/".$r['id']." : HTTP ".$code.' '.aeropresta_ps_ws_error($resp)."\n";
    }
    if ($fh) {
        $line = array();
        foreach ($fields as $f) {
            $line[] = isset($r[$f]) ? $r[$f] : '';
        }
        $line[] = $ok ? 'supprimee' : 'echec HTTP '.$code;
        fputcsv($fh, $line, ';');
    }
    if (($i + 1) % 25 === 0) {
        echo "  ".($i + 1)."/".count($rows)." (".$failed." échec(s))\n";
    }
    if ($streak >= 20) {
        echo "\n20 échecs consécutifs : boutique injoignable ? Arrêt. Relancer plus tard, le script repart de ce qui reste.\n";
        break;
    }
}
if ($fh) {
    fclose($fh);
}
echo str_repeat('-', 60)."\n";
echo $done." supprimée(s), ".$failed." échec(s)".($csv !== '' ? ", trace : ".$csv : '')."\n";
