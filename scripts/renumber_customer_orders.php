<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/renumber_customer_orders.php
 * \ingroup aeromigration
 * \brief   Renumérote les commandes clients selon la règle arrêtée avec le client.
 *
 * ------------------------------------------------------------------------------
 * CE SCRIPT RÉÉCRIT LA RÉFÉRENCE DE ~61 000 COMMANDES — LISEZ TOUT AVANT
 * ------------------------------------------------------------------------------
 *
 * ## La règle — celle des factures, périmètre complet confirmé le 20/08/2026
 *
 * **Coupure au 1er octobre 2023** (exercices d'octobre à septembre). Avant, la pièce reprend
 * son numéro ADD : `CO<millésime>-<chiffres du numéro source>` — chiffres tels quels, lettres
 * retirées, zéros conservés (`CI-235850` → `CO2223-235850` selon son exercice, `C990004341` →
 * `CO…-990004341`). Après, séquence chronologique à `000001` par exercice, TOUTES provenances
 * confondues — reprise ADD comme boutique —, compteur six chiffres, celui que le modèle `CO`
 * d'aerotoolbox 1.16.0 continuera. La boutique garde sa propre référence côté PrestaShop pour
 * l'affichage client ; côté Dolibarr, `ref_client` conserve déjà « <id presta> / <référence> ».
 *
 * Le numéro source vient du `ref_ext` (`SAGE:<pièce>`), jamais de la référence actuelle.
 * Les brouillons `(PROV…)` sont ignorés.
 *
 * ## Prestasync ne perd rien — vérifié dans son code le 20/08/2026
 *
 * Le flux vivant retrouve chaque commande par la table de liaison `llx_prestasync_order`
 * (id presta → rowid), insensible au renommage. Le contrôle par référence
 * (prestaOrder.class.php:622) n'intervient qu'à la CRÉATION d'une commande sans liaison :
 * c'est un filet anti-doublon, pas le lien. D'où la seule contrainte d'ordre, déjà celle du
 * document de mise en production : au jour J, renuméroter APRÈS le rattrapage borné et la
 * pose des liaisons.
 *
 * ## Documents : suppression totale décidée le 20/08/2026
 *
 * Les ~28 700 PDF de commandes (rattrapage boutique) sont supprimés avant la passe — le
 * script REFUSE de tourner s'il en subsiste. Ils ne seront PAS régénérés en masse : la
 * boutique les obtiendra par l'endpoint aeropresta (lot 2), en génération à la demande, avec
 * la référence définitive — décision client, même principe que les factures.
 *
 * ## Écriture directe assumée, idempotence, contrôles
 *
 * Mêmes principes que `renumber_invoices.php` : UPDATE direct, cibles calculées de données
 * stables donc rejouables, unicité totale vérifiée avant toute écriture, pré-phase de
 * déplacement des références occupant déjà une cible, `--limit` pour un lot d'essai. Aucun
 * chevauchement possible entre les références quotidiennes `COyymm-` (mm ≤ 12) et les
 * millésimes cibles (seconde paire = première + 1, donc ≥ 20).
 *
 * Usage :
 *   php renumber_customer_orders.php                 simulation
 *   php renumber_customer_orders.php --confirm       applique
 *   php renumber_customer_orders.php --limit=100 --confirm    lot d'essai
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

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** Préfixe de la série cible — nomenclature aerotoolbox 1.16.0. */
const SERIE_PREFIX = 'CO';

/** Coupure : début de l'exercice fiscal 2023-2024. */
const CUTOFF = '2023-10-01';

/** Longueur du compteur après coupure. */
const COUNTER_LENGTH = 6;


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N]\n";
        exit(1);
    }
}

/**
 * Millésime de l'exercice fiscal (octobre → septembre) d'une date.
 *
 * @param  string $date Date SQL (Y-m-d…)
 * @return string       « 1516 » pour octobre 2015 → septembre 2016
 */
function fiscalCode($date)
{
    $y = (int) substr($date, 0, 4);
    $m = (int) substr($date, 5, 2);
    $start = ($m >= 10) ? $y : $y - 1;

    return substr((string) $start, -2).substr((string) ($start + 1), -2);
}


/*
 * Garde-fou : aucun document de commande ne doit subsister.
 */

$problems = array();

$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."ecm_files"
    ." WHERE filepath = 'commande' OR filepath LIKE 'commande/%'");
if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->n > 0) {
    $problems[] = $obj->n." entrée(s) llx_ecm_files sous commande/";
}
$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."commande"
    ." WHERE last_main_doc IS NOT NULL AND last_main_doc <> ''");
if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->n > 0) {
    $problems[] = $obj->n." commande(s) avec last_main_doc";
}
$dirOutput = !empty($conf->commande->dir_output) ? $conf->commande->dir_output : '';
if ($dirOutput !== '' && is_dir($dirOutput)) {
    $handle = opendir($dirOutput);
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $problems[] = 'le répertoire '.$dirOutput.' n\'est pas vide ('.$entry.'…)';
        break;
    }
    closedir($handle);
}

if ($problems) {
    echo "REFUS : des documents de commandes subsistent — renuméroter les rendrait orphelins.\n";
    foreach ($problems as $p) {
        echo "  - ".$p."\n";
    }
    echo "Supprimez d'abord les répertoires documents/commande/*, les lignes llx_ecm_files\n";
    echo "(filepath 'commande/%') et remettez last_main_doc à NULL. Ils ne se régénèrent pas\n";
    echo "en masse : la boutique les obtiendra à la demande via aeropresta (décision client).\n";
    exit(1);
}


/*
 * Chargement du parc et calcul des cibles.
 */

$resql = $db->query('SELECT rowid, ref, ref_ext,'
    .' COALESCE(date_commande, date_creation) as d'
    .' FROM '.MAIN_DB_PREFIX.'commande'
    .' WHERE entity IN ('.getEntity('commande').')'
    .' ORDER BY COALESCE(date_commande, date_creation), rowid');
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

$orders = array();
$drafts = 0;
while ($obj = $db->fetch_object($resql)) {
    if (strpos((string) $obj->ref, '(PROV') === 0) {
        $drafts++;
        continue;
    }
    $orders[] = $obj;
}
$db->free($resql);

$targets   = array();   // rowid -> ref cible
$already   = 0;
$sequences = array();   // millésime -> dernier numéro attribué (après coupure)
$anomalies = array();
$perSeries = array();   // série -> [n, premier, dernier]

foreach ($orders as $ord) {
    $code = fiscalCode($ord->d);

    if (substr($ord->d, 0, 10) < CUTOFF) {
        if (strpos((string) $ord->ref_ext, REF_EXT_PREFIX) !== 0) {
            $anomalies[] = $ord->ref.' ('.substr($ord->d, 0, 10).') : pas de numéro source';
            continue;
        }
        $piece  = substr($ord->ref_ext, strlen(REF_EXT_PREFIX));
        $digits = preg_replace('/\D+/', '', $piece);
        if ($digits === '') {
            $anomalies[] = $ord->ref.' : numéro source sans le moindre chiffre ('.$ord->ref_ext.')';
            continue;
        }
        // Les ordres de réparation (carnet atelier « OR ») gardent leur marqueur : leurs
        // chiffres recoupent ceux des commandes « C » du même exercice (OR990000001 vs
        // C990000001, deux collisions constatées) — et le marqueur signale leur nature à part.
        if (strncasecmp($piece, 'OR', 2) === 0) {
            $digits = 'OR'.$digits;
        }
        $target = SERIE_PREFIX.$code.'-'.$digits;
    } else {
        $sequences[$code] = isset($sequences[$code]) ? $sequences[$code] + 1 : 1;
        $target = SERIE_PREFIX.$code.'-'.str_pad((string) $sequences[$code], COUNTER_LENGTH, '0', STR_PAD_LEFT);
    }

    $serie = SERIE_PREFIX.$code;
    if (!isset($perSeries[$serie])) {
        $perSeries[$serie] = array('n' => 0, 'first' => $target, 'last' => $target);
    }
    $perSeries[$serie]['n']++;
    $perSeries[$serie]['last'] = $target;

    if ($ord->ref === $target) {
        $already++;
        continue;
    }
    $targets[(int) $ord->rowid] = $target;
}

/*
 * Contrôles avant écriture : unicité totale, aucune collision avec ce qui reste en place.
 */

$seen       = array();
$collisions = array();
foreach ($orders as $ord) {
    $rowid = (int) $ord->rowid;
    $t = isset($targets[$rowid]) ? $targets[$rowid] : $ord->ref;
    if (isset($seen[$t])) {
        $collisions[] = $t;
    }
    $seen[$t] = true;
}
$resql = $db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'commande'
    ." WHERE entity IN (".getEntity('commande').") AND ref LIKE '(PROV%'");
while ($resql && ($obj = $db->fetch_object($resql))) {
    if (isset($seen[$obj->ref])) {
        $collisions[] = $obj->ref.' (brouillon)';
    }
}

/*
 * Pré-phase : une référence actuelle qui occupe déjà une cible est déplacée en temporaire
 * avant la passe principale — cas des commandes récentes déjà numérotées au format définitif
 * par le modèle CO, et des relances partielles.
 */
$targetSet  = array_flip($targets);
$preRenames = array();
foreach ($orders as $ord) {
    $rowid = (int) $ord->rowid;
    if (!isset($targets[$rowid])) {
        continue;
    }
    if (isset($targetSet[$ord->ref])) {
        $preRenames[$rowid] = '(RN'.$rowid.')';
    }
}

echo "Script      : renumérotation des commandes clients (coupure ".CUTOFF.")\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
printf("Parc        : %s commande(s), %d brouillon(s) ignoré(s)\n",
    number_format(count($orders), 0, ',', ' '), $drafts);
printf("À renommer  : %s — déjà conformes : %s\n\n",
    number_format(count($targets), 0, ',', ' '), number_format($already, 0, ',', ' '));

echo "Ventilation par série :\n";
ksort($perSeries);
foreach ($perSeries as $serie => $s) {
    printf("  %-7s : %6s  de %-20s à %s\n", $serie,
        number_format($s['n'], 0, ',', ' '), $s['first'], $s['last']);
}

if ($anomalies) {
    echo "\nANOMALIES (".count($anomalies).") — RIEN ne sera écrit :\n";
    foreach (array_slice($anomalies, 0, 20) as $a) {
        echo "  ".$a."\n";
    }
    $db->close();
    exit(1);
}

if ($collisions) {
    echo "\nCOLLISIONS (".count($collisions).") — RIEN ne sera écrit :\n";
    foreach (array_slice($collisions, 0, 20) as $c) {
        echo "  ".$c."\n";
    }
    $db->close();
    exit(1);
}
printf("\nContrôles   : cibles toutes uniques, aucune collision — %d référence(s) actuelle(s)\n"
    ."              occupant une cible, déplacée(s) en temporaire avant la passe principale.\n",
    count($preRenames));

echo "\nÉchantillons :\n";
$shown = 0;
foreach ($orders as $ord) {
    if (!isset($targets[(int) $ord->rowid])) {
        continue;
    }
    if ($shown < 4 || (substr($ord->d, 0, 10) >= CUTOFF && $shown < 8)) {
        printf("  %-20s (%s) -> %s\n", $ord->ref, substr($ord->d, 0, 10), $targets[(int) $ord->rowid]);
        $shown++;
    }
    if ($shown >= 8) {
        break;
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}


/*
 * Écriture, par lots transactionnels.
 */

$todo = $targets;
if ($limit > 0 && count($todo) > $limit) {
    $todo = array_slice($todo, 0, $limit, true);
}

$failed = 0;
$batch  = 0;

$freed = 0;
$db->begin();
foreach ($preRenames as $rowid => $tmpRef) {
    $sql = 'UPDATE '.MAIN_DB_PREFIX."commande SET ref = '".$db->escape($tmpRef)."'"
        .' WHERE rowid = '.((int) $rowid);
    if (!$db->query($sql)) {
        $failed++;
        echo "  ÉCHEC pré-phase rowid ".$rowid." -> ".$tmpRef." : ".$db->lasterror()."\n";
        continue;
    }
    $freed++;
    if (++$batch >= 500) {
        $db->commit();
        $db->begin();
        $batch = 0;
    }
}
$db->commit();
if ($freed > 0) {
    printf("  Pré-phase : %s référence(s) déplacée(s) en temporaire.\n", number_format($freed, 0, ',', ' '));
}
$batch = 0;

$done = 0;
$db->begin();
foreach ($todo as $rowid => $target) {
    $sql = 'UPDATE '.MAIN_DB_PREFIX."commande SET ref = '".$db->escape($target)."'"
        .' WHERE rowid = '.((int) $rowid);
    if (!$db->query($sql)) {
        $failed++;
        echo "  ÉCHEC rowid ".$rowid." -> ".$target." : ".$db->lasterror()."\n";
        continue;
    }
    $done++;
    if (++$batch >= 500) {
        $db->commit();
        $db->begin();
        $batch = 0;
    }
}
$db->commit();

printf("\nRenommées   : %s\n", number_format($done, 0, ',', ' '));
printf("En échec    : %d\n", $failed);

// La dernière référence de la série en cours, pour le compteur du modèle CO.
$current = SERIE_PREFIX.fiscalCode(date('Y-m-d'));
$resql   = $db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'commande'
    ." WHERE ref LIKE '".$db->escape($current)."-%' ORDER BY ref DESC LIMIT 1");
if ($resql && ($obj = $db->fetch_object($resql))) {
    echo "Dernière référence de la série en cours : ".$obj->ref
        ." — le modèle CO d'aerotoolbox reprendra après elle.\n";
}

$db->close();

exit($failed > 0 ? 1 : 0);
