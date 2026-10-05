<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/init_pmp.php
 * \ingroup aeromigration
 * \brief   Amorce le PMP des articles qui n'en ont jamais eu, depuis leur prix de revient.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * Message du client, 03/09/2026 : « Je propose de mettre la même donnée (prix de revient 2026)
 * pour le prix de revient Dolibarr et pour le PMP. Le PMP évoluera au fil du temps avec cette
 * référence pour donnée de référence. »
 *
 * La première moitié a été faite — `import_prix_revient.php` pose `cost_price` depuis
 * `migrationdata/prix_revient_migration.csv`. La seconde ne l'a jamais été : **aucun script n'a
 * initialisé le PMP**. D'où 603 articles vendus en 2026 qui portent un prix de revient juste et un
 * PMP à zéro, et une marge incalculable sur 7,6 % du chiffre d'affaires.
 *
 * Ce n'est pas un effet de bord d'un traitement : les entrées en stock reprises de l'ancien ERP ne
 * portent aucun prix, et le cœur ne construit le PMP que sur une entrée valorisée
 * (`MouvementStock::_create`, `if ($price > 0 …)`). Sans amorçage, le PMP de ces articles
 * n'apparaîtra qu'à leur prochaine réception — une par une, pendant des mois.
 *
 * À l'inverse, **la correction de stock du module n'y est pour rien** : une entrée à prix nul laisse
 * le PMP inchangé (`$newpmp = $oldpmp`), le cœur s'en protège lui-même. Vérifié sur #00591, dont les
 * quatre entrées sont toutes à prix zéro.
 *
 * ------------------------------------------------------------------------------
 * CE QUI EST TRAITÉ, ET CE QUI NE L'EST PAS
 * ------------------------------------------------------------------------------
 *
 * Sont amorcés les articles qui réunissent les trois conditions :
 *
 * - un **PMP nul ou absent** — un PMP déjà construit n'est jamais touché, il vaut mieux que toute
 *   valeur de référence puisqu'il vient d'achats réels ;
 * - un **prix de revient strictement positif** — c'est la donnée du client, et elle seule ;
 * - **aucune entrée valorisée** dans tout l'historique des mouvements. Si l'article a déjà été
 *   acheté à un prix connu, son PMP a une raison d'être ce qu'il est : on n'y touche pas ;
 * - **aucune modification manuelle du PMP**. La Vue 360° du module laisse éditer ce champ et
 *   trace chaque changement en agenda (`AeroTbFptActPmp`). Le client s'en sert pour déprécier :
 *   on relève onze mises à zéro délibérées, du type « 67,42 € → 0,00 € ». Un article dont
 *   quelqu'un a fixé le PMP à la main porte une décision, pas une lacune — le script s'en
 *   écarte, quel que soit son prix de revient.
 *
 * Sont donc écartés d'office, sans que ce soit un écart :
 *
 * - les articles dont **les deux valeurs sont à zéro** : ce sont les 2 828 zéros assumés du fichier
 *   client (arbitrage du 06/09), du stock déprécié ou des articles sans valorisation ;
 * - les **services** : le PMP ne veut rien dire sur une prestation ;
 * - les **produits composés** : le client ne les a pas valorisés, leur coût découle des composants.
 *
 * ------------------------------------------------------------------------------
 * ÉCRITURE
 * ------------------------------------------------------------------------------
 *
 * UPDATE ciblé sur `llx_product.pmp`, par paquets. Ni `Product::update()` ni `setValueFrom()` : le
 * premier déclenche PRODUCT_MODIFY donc la synchronisation boutique — quinze mille écritures
 * webservice pour une colonne que la boutique ignore —, le second recharge l'objet à chaque ligne.
 *
 * Les articles touchés reçoivent l'horodatage de la passe dans `import_key`, colonne libre sur
 * `llx_product` (aucun produit n'en porte aujourd'hui). La passe est donc réversible :
 *
 *     UPDATE llx_product SET pmp = 0 WHERE import_key = '<clé affichée en fin de passe>';
 *
 * Rejouable : un article déjà amorcé a un PMP non nul, il sort du périmètre de lui-même.
 *
 * Usage :
 *   php init_pmp.php                           simulation : ventilation complète, rien d'écrit
 *   php init_pmp.php --csv=trace.csv           simulation + trace article par article
 *   php init_pmp.php --confirm --csv=trace.csv applique
 *   php init_pmp.php --limit=50 --confirm      lot d'essai
 *   php init_pmp.php --stock-only --confirm    seulement les articles qui ont du stock
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

$langs->loadLangs(array('admin'));

/** Articles écrits par requête : au-delà, l'UPDATE devient illisible dans le journal. */
const CHUNK = 500;

/** Nombre d'exemples listés au rapport. */
const SAMPLES = 10;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$stockOnly = false;
$csv       = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--stock-only') {
        $stockOnly = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--csv=(.+)$/', $arg, $m)) {
        $csv = $m[1];
        if (strpos($csv, '~') === 0) {
            $csv = getenv('HOME').substr($csv, 1);
        }
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--stock-only] [--csv=FICHIER]\n";
        exit(1);
    }
}

$importKey = dol_print_date(dol_now(), '%Y%m%d%H%M%S');

echo "Amorçage du PMP depuis le prix de revient\n";
echo "Mode     : ".($confirm ? 'ÉCRITURE' : 'SIMULATION')."\n";
if ($stockOnly) {
    echo "Portée   : les seuls articles qui ont du stock\n";
}
if ($limit > 0) {
    echo "Limite   : ".$limit." article(s)\n";
}
echo str_repeat('-', 64)."\n";


/*
 * Le périmètre
 */

$sql  = 'SELECT p.rowid, p.ref, p.label, p.cost_price, p.stock';
$sql .= ' FROM '.MAIN_DB_PREFIX.'product AS p';
$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
$sql .= ' AND p.fk_product_type = 0';
$sql .= ' AND COALESCE(p.pmp, 0) = 0';
$sql .= ' AND p.cost_price > 0';
// Un article déjà acheté à un prix connu a un PMP qui veut dire quelque chose, même s'il est
// retombé à zéro depuis : on ne lui impose pas une valeur de référence.
$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'stock_mouvement AS sm';
$sql .= ' WHERE sm.fk_product = p.rowid AND sm.type_mouvement IN (0, 3) AND sm.price > 0)';
// Les produits composés : le client ne les a pas valorisés, leur coût découle des composants.
$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'product_association AS pa';
$sql .= ' WHERE pa.fk_product_pere = p.rowid)';
// Le PMP posé à la main depuis la Vue 360° : une décision, pas une lacune. Les deux libellés
// sont cherchés — l'événement porte celui de la langue en vigueur au moment du changement.
$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX."actioncomm AS ac";
$sql .= " WHERE ac.elementtype = 'product' AND ac.fk_element = p.rowid";
$sql .= " AND (ac.label LIKE 'PMP modifi%' OR ac.label LIKE 'Average cost price modified%'))";
if ($stockOnly) {
    $sql .= ' AND COALESCE(p.stock, 0) > 0';
}
$sql .= ' ORDER BY COALESCE(p.stock, 0) DESC, p.rowid';
if ($limit > 0) {
    $sql .= ' LIMIT '.((int) $limit);
}

$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des articles impossible : ".$db->lasterror()."\n";
    exit(1);
}

$rows  = array();
$avecStock = 0;
$valeur    = 0.0;
while ($o = $db->fetch_object($resql)) {
    $rows[(int) $o->rowid] = array(
        'ref'   => (string) $o->ref,
        'label' => (string) $o->label,
        'cost'  => (float) $o->cost_price,
        'stock' => (float) $o->stock,
    );
    if ((float) $o->stock > 0) {
        $avecStock++;
        $valeur += (float) $o->stock * (float) $o->cost_price;
    }
}
$db->free($resql);

printf("%d article(s) à amorcer\n", count($rows));
printf("  %d avec du stock, soit %s de valorisation rendue au stock\n",
    $avecStock, price($valeur, 0, $langs, 1, -1, 2));
printf("  %d sans stock : la valeur servira au prochain mouvement\n", count($rows) - $avecStock);


/*
 * Ce qui est écarté, et pourquoi — un écart tu : chaque exclusion se compte.
 */

function compter($db, $where)
{
    $r = $db->query('SELECT COUNT(*) AS n FROM '.MAIN_DB_PREFIX.'product AS p'
        .' WHERE p.entity IN ('.getEntity('product').') AND '.$where);

    return $r ? (int) $db->fetch_object($r)->n : -1;
}

echo "\nÉcartés (comportement voulu) :\n";
printf("  %6d article(s) : les deux valeurs à zéro — zéros assumés du fichier client\n",
    compter($db, 'p.fk_product_type = 0 AND COALESCE(p.pmp,0) = 0 AND COALESCE(p.cost_price,0) = 0'));
printf("  %6d article(s) : PMP déjà construit, jamais touché\n",
    compter($db, 'p.fk_product_type = 0 AND p.pmp > 0'));
printf("  %6d article(s) : déjà acheté à un prix connu\n",
    compter($db, 'p.fk_product_type = 0 AND COALESCE(p.pmp,0) = 0 AND p.cost_price > 0'
        .' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'stock_mouvement AS sm'
        .' WHERE sm.fk_product = p.rowid AND sm.type_mouvement IN (0,3) AND sm.price > 0)'));
printf("  %6d produit(s) composé(s) : coût porté par les composants\n",
    compter($db, 'p.fk_product_type = 0 AND COALESCE(p.pmp,0) = 0 AND p.cost_price > 0'
        .' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'product_association AS pa WHERE pa.fk_product_pere = p.rowid)'));
printf("  %6d service(s) : le PMP n'y veut rien dire\n",
    compter($db, 'p.fk_product_type <> 0'));
printf("  %6d article(s) : PMP posé à la main depuis la Vue 360° — décision assumée
",
    compter($db, 'p.fk_product_type = 0 AND COALESCE(p.pmp,0) = 0'
        .' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX."actioncomm AS ac WHERE ac.elementtype = 'product'"
        ." AND ac.fk_element = p.rowid AND (ac.label LIKE 'PMP modifi%' OR ac.label LIKE 'Average cost price modified%'))"));

if (!empty($rows)) {
    echo "\nLes plus gros en stock :\n";
    $n = 0;
    foreach ($rows as $id => $r) {
        if ($r['stock'] <= 0 || $n >= SAMPLES) {
            continue;
        }
        printf("  %-12s %-40s %6s u x %10s\n", $r['ref'], dol_trunc($r['label'], 38),
            price($r['stock'], 0, $langs, 1, 0, 0), price($r['cost'], 0, $langs, 1, -1, 2));
        $n++;
    }
}


/*
 * Écriture
 */

$written = 0;
$failed  = 0;

if ($confirm && !empty($rows)) {
    echo "\n";
    // Un UPDATE par valeur distincte serait plus lisible mais multiplierait les requêtes par dix :
    // on groupe par paquets, avec un CASE qui porte la valeur de chaque article.
    foreach (array_chunk(array_keys($rows), CHUNK, true) as $chunk) {
        $cases = '';
        foreach ($chunk as $id) {
            $cases .= ' WHEN '.((int) $id).' THEN '.((float) $rows[$id]['cost']);
        }
        $sql  = 'UPDATE '.MAIN_DB_PREFIX.'product SET pmp = CASE rowid'.$cases.' END';
        $sql .= ", import_key = '".$db->escape($importKey)."'";
        $sql .= ' WHERE rowid IN ('.implode(',', array_map('intval', $chunk)).')';
        // La garde vaut relecture : une passe concurrente, ou une réception survenue entre la
        // lecture et l'écriture, a pu donner un PMP à l'article. On ne l'écrase pas.
        $sql .= ' AND COALESCE(pmp, 0) = 0';

        $r = $db->query($sql);
        if ($r) {
            $written += (int) $db->affected_rows($r);
        } else {
            $failed += count($chunk);
            dol_syslog('init_pmp : '.$db->lasterror(), LOG_ERR);
            echo "  Écriture impossible sur un paquet : ".$db->lasterror()."\n";
        }
    }

    printf("%d article(s) amorcé(s)", $written);
    if ($written < count($rows)) {
        printf(", %d épargné(s) (PMP apparu entre-temps)", count($rows) - $written - $failed);
    }
    echo "\n";
    if ($failed > 0) {
        printf("%d en échec\n", $failed);
    }
    if ($written > 0) {
        echo "\nClé de reprise : ".$importKey."\n";
        echo "Pour défaire : UPDATE ".MAIN_DB_PREFIX."product SET pmp = 0, import_key = NULL WHERE import_key = '".$importKey."';\n";
    }
}


/*
 * Trace
 */

if ($csv !== '') {
    $fh = fopen($csv, 'w');
    if (!$fh) {
        echo "Trace impossible à écrire : ".$csv."\n";
        exit(1);
    }
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('rowid', 'ref', 'libelle', 'pmp_pose', 'stock', 'valeur_stock'), ';');
    foreach ($rows as $id => $r) {
        fputcsv($fh, array($id, $r['ref'], $r['label'],
            number_format($r['cost'], 2, ',', ''), $r['stock'],
            number_format($r['stock'] * $r['cost'], 2, ',', '')), ';');
    }
    fclose($fh);
    echo 'Trace : '.$csv.' ('.count($rows)." ligne(s))\n";
}

if (!$confirm && !empty($rows)) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm.\n";
}

$db->close();

exit($failed > 0 ? 1 : 0);
