<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/align_sellbuy.php
 * \ingroup aeromigration
 * \brief   Aligne « En vente / En achat » sur le couple dispo/suivi de chaque produit.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * La configuration aerotoolbox associe à chaque couple (disponibilité, suivi) les statuts
 * Dolibarr voulus (`c_aerotoolbox_combination.dolibarr_sell` / `dolibarr_buy`) — un
 * « Commercialisation arrêtée / Non suivi » ne doit être ni en vente ni en achat. Cette
 * mécanique n'est rejouée que par l'UI du couple et par l'autostop : les scripts de
 * reprise (product, import_disposuivi, ventilate avant 0.35.1) posaient les extrafields
 * par updateExtraField() sans jamais toucher tosell/tobuy. Ce script solde l'écart, pour
 * tout le catalogue, en une passe ensembliste.
 *
 * ## Périmètre et règles
 *
 * - Seuls les produits portant un couple COMPLET (dispo > 0 ET suivi > 0) sont traités ;
 *   un couple absent du dictionnaire (ou inactif) ne force rien — mêmes gardes que
 *   aerotb_apply_sellbuy_from_combination().
 * - Écriture en SQL direct (tosell/tobuy), comme le fait la fonction d'aerotoolbox :
 *   AUCUN déclencheur — donc pas de tempête de synchronisation vers la boutique ; l'état
 *   boutique se réalignera par le moteur « état produit » d'aeropresta.
 * - Idempotent : seuls les désalignés sont réécrits.
 *
 * Usage :
 *   php align_sellbuy.php                  simulation : ventilation par couple, rien d'écrit
 *   php align_sellbuy.php --confirm        applique
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

$confirm = in_array('--confirm', $argv, true);
foreach ($argv as $i => $arg) {
    if ($i > 0 && $arg !== '--confirm') {
        echo "Argument non reconnu : ".$arg."\nUsage: php ".$script_file." [--confirm]\n";
        exit(1);
    }
}

echo "Script      : alignement En vente / En achat sur le couple dispo/suivi\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo str_repeat('-', 76)."\n";

// Le désalignement, ventilé par couple : ce que dit le dictionnaire contre ce que porte
// le produit. La jointure est celle d'aerotb_apply_sellbuy_from_combination().
$sql = "SELECT da.label dispo, dt.label suivi,"
    ." IF(cc.dolibarr_sell, 1, 0) sell_cible, IF(cc.dolibarr_buy, 1, 0) buy_cible,"
    ." COUNT(*) n, SUBSTRING_INDEX(GROUP_CONCAT(p.ref ORDER BY p.ref SEPARATOR ', '), ', ', 5) exemples"
    ." FROM ".MAIN_DB_PREFIX."product p"
    ." JOIN ".MAIN_DB_PREFIX."product_extrafields e ON e.fk_object = p.rowid"
    ." JOIN ".MAIN_DB_PREFIX."c_aerotoolbox_combination cc"
    ."   ON cc.fk_availability = e.aerotb_availability AND cc.fk_tracking = e.aerotb_tracking"
    ."   AND cc.active = 1 AND cc.entity IN (".getEntity('c_aerotoolbox_combination').")"
    ." LEFT JOIN ".MAIN_DB_PREFIX."c_aerotoolbox_availability da ON da.rowid = e.aerotb_availability"
    ." LEFT JOIN ".MAIN_DB_PREFIX."c_aerotoolbox_tracking dt ON dt.rowid = e.aerotb_tracking"
    ." WHERE p.entity IN (".getEntity('product').")"
    ." AND e.aerotb_availability > 0 AND e.aerotb_tracking > 0"
    ." AND (p.tosell <> IF(cc.dolibarr_sell, 1, 0) OR p.tobuy <> IF(cc.dolibarr_buy, 1, 0))"
    ." GROUP BY e.aerotb_availability, e.aerotb_tracking, cc.dolibarr_sell, cc.dolibarr_buy"
    ." ORDER BY n DESC";

$total = 0;
$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}
while ($o = $db->fetch_object($resql)) {
    $total += (int) $o->n;
    printf("  %6d  « %s / %s »  →  vente=%d achat=%d   (ex. %s)\n",
        $o->n, $o->dispo, $o->suivi, $o->sell_cible, $o->buy_cible, $o->exemples);
}
$db->free($resql);

// Produits à couple complet mais SANS combinaison active au dictionnaire : jamais forcés,
// simplement signalés — même règle que l'UI.
$resql = $db->query("SELECT COUNT(*) n FROM ".MAIN_DB_PREFIX."product p"
    ." JOIN ".MAIN_DB_PREFIX."product_extrafields e ON e.fk_object = p.rowid"
    ." LEFT JOIN ".MAIN_DB_PREFIX."c_aerotoolbox_combination cc"
    ."   ON cc.fk_availability = e.aerotb_availability AND cc.fk_tracking = e.aerotb_tracking"
    ."   AND cc.active = 1 AND cc.entity IN (".getEntity('c_aerotoolbox_combination').")"
    ." WHERE p.entity IN (".getEntity('product').")"
    ." AND e.aerotb_availability > 0 AND e.aerotb_tracking > 0 AND cc.rowid IS NULL");
$horsDico = ($resql && ($o = $db->fetch_object($resql))) ? (int) $o->n : 0;

echo str_repeat('-', 76)."\n";
printf("Produits à réaligner       : %d\n", $total);
if ($horsDico > 0) {
    printf("Couples hors dictionnaire  : %d  (combinaison absente ou inactive — jamais forcés)\n", $horsDico);
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}

$up = "UPDATE ".MAIN_DB_PREFIX."product p"
    ." JOIN ".MAIN_DB_PREFIX."product_extrafields e ON e.fk_object = p.rowid"
    ." JOIN ".MAIN_DB_PREFIX."c_aerotoolbox_combination cc"
    ."   ON cc.fk_availability = e.aerotb_availability AND cc.fk_tracking = e.aerotb_tracking"
    ."   AND cc.active = 1 AND cc.entity IN (".getEntity('c_aerotoolbox_combination').")"
    ." SET p.tosell = IF(cc.dolibarr_sell, 1, 0), p.tobuy = IF(cc.dolibarr_buy, 1, 0)"
    ." WHERE p.entity IN (".getEntity('product').")"
    ." AND e.aerotb_availability > 0 AND e.aerotb_tracking > 0"
    ." AND (p.tosell <> IF(cc.dolibarr_sell, 1, 0) OR p.tobuy <> IF(cc.dolibarr_buy, 1, 0))";

$resup = $db->query($up);
if (!$resup) {
    echo "\nÉCHEC de l'UPDATE : ".$db->lasterror()."\n";
    exit(1);
}
printf("\n%d produit(s) réaligné(s).\n", $db->affected_rows($resup));

$db->close();
exit(0);
