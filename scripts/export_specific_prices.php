<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/export_specific_prices.php
 * \ingroup aeromigration
 * \brief   Sauvegarde complète de la table ps_specific_price de la boutique, par le webservice.
 *
 * Quand on n'a pas la main sur la base PrestaShop, c'est la copie de sûreté avant un alignement
 * ou une purge : toutes les lignes (groupes, clients, paniers, promotions), tous les champs,
 * dans un CSV rejouable. Sert aussi de photo « après » pour comparer.
 *
 * Usage :
 *   php export_specific_prices.php --csv=~/ps_specific_price_20260920_avant.csv
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

$csv = '';
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--csv=(.+)$/', $argv[$i], $m)) {
        $csv = $m[1];
        if (strpos($csv, '~') === 0) {
            $csv = getenv('HOME').substr($csv, 1);
        }
    } else {
        echo "Argument non reconnu : ".$argv[$i]."\nUsage: php ".basename(__FILE__)." --csv=FICHIER\n";
        exit(1);
    }
}
if ($csv === '') {
    echo "Usage: php ".basename(__FILE__)." --csv=FICHIER\n";
    exit(1);
}

$shop = aeropresta_ps_shop($db);
if (!$shop) {
    echo "Boutique absente.\n";
    exit(1);
}
echo "Boutique : ".$shop->shop_url."\n";

$fields = array('id', 'id_shop_group', 'id_shop', 'id_cart', 'id_product', 'id_product_attribute', 'id_currency', 'id_country', 'id_group', 'id_customer',
    'id_specific_price_rule', 'price', 'from_quantity', 'reduction', 'reduction_tax', 'reduction_type', 'from', 'to');

$fh = fopen($csv, 'w');
if (!$fh) {
    echo "Impossible d'écrire ".$csv."\n";
    exit(1);
}
fputcsv($fh, $fields, ';');

$page = 500;
$total = 0;
$byGroup = array();
for ($offset = 0; $offset < 1000000; $offset += $page) {
    $q = 'specific_prices?output_format=JSON&display=['.implode(',', $fields).']&sort=[id_ASC]&limit='.$offset.','.$page;
    list($code, $body) = aeropresta_ps_ws($shop, 'GET', $q);
    if ($code === 404) {
        break;   // plus rien
    }
    if ($code !== 200) {
        echo "Lecture specific_prices : HTTP ".$code.' '.aeropresta_ps_ws_error($body)."\n";
        fclose($fh);
        exit(1);
    }
    $json = json_decode($body, true);
    $rows = $json['specific_prices'] ?? array();
    if (!$rows) {
        break;
    }
    foreach ($rows as $r) {
        $line = array();
        foreach ($fields as $f) {
            $line[] = isset($r[$f]) ? $r[$f] : '';
        }
        fputcsv($fh, $line, ';');
        $total++;
        $k = ((int) $r['id_customer'] > 0 || (int) $r['id_cart'] > 0) ? 'client/panier' : 'groupe '.((int) $r['id_group']);
        $byGroup[$k] = ($byGroup[$k] ?? 0) + 1;
    }
    echo "  ".$total." lignes lues\r";
    if (count($rows) < $page) {
        break;
    }
}
fclose($fh);

echo "\n".$total." lignes écrites dans ".$csv."\n";
ksort($byGroup);
foreach ($byGroup as $k => $n) {
    printf("  %-14s %6d\n", $k, $n);
}
