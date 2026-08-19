<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/renumber_invoices.php
 * \ingroup aeromigration
 * \brief   Renumérote le parc de factures selon la règle arrêtée avec le client.
 *
 * ------------------------------------------------------------------------------
 * CE SCRIPT RÉÉCRIT LA RÉFÉRENCE DE ~183 000 FACTURES — LISEZ TOUT AVANT
 * ------------------------------------------------------------------------------
 *
 * ## La règle
 *
 * **Coupure au 1er octobre 2023** — le début de l'exercice fiscal 2023-2024 (les exercices
 * vont d'octobre à septembre).
 *
 * **Avant la coupure**, la facture reprend son numéro d'émission d'origine, celui d'ADD :
 * `FA<millésime>-<chiffres du numéro source>` — le millésime est fait des deux derniers
 * chiffres des deux années de l'exercice de la facture (octobre 2015 → septembre 2016 donne
 * « 1516 »), et la partie chiffrée du numéro source est reprise TELLE QUELLE, lettres
 * retirées, zéros conservés (FAC216762 → FA1516-216762 ; F990030360 → FA1920-990030360).
 * L'unicité de (millésime, chiffres, type) a été vérifiée : zéro collision. Les avoirs
 * prennent la série `AV<millésime>-`.
 *
 * **Après la coupure**, la numérotation repart à 000001 par exercice fiscal, toutes
 * provenances confondues (reprise ADD comme boutique), en ordre chronologique — compteur à
 * six chiffres, celui que `mod_facture_aero` (aerotoolbox) continuera d'incrémenter. Les
 * avoirs ont leur propre série `AV<millésime>-000001`.
 *
 * Les brouillons `(PROV…)` sont ignorés. Les abandonnées sont renumérotées comme les autres :
 * elles gardent leur place dans la séquence.
 *
 * ## Les documents n'existent plus — et le script s'en assure
 *
 * Décision du 19/08/2026 : sur les instances de test, tous les PDF de factures ont été
 * supprimés (répertoires, `llx_ecm_files`, `last_main_doc`) — les seuls jamais « transmis »
 * l'avaient été à la boutique de test. Les PDF se régénèrent à la demande, APRÈS
 * renumérotation, avec le numéro définitif. Le script REFUSE de tourner si des documents de
 * factures subsistent : renuméroter sous des fichiers nommés à l'ancienne référence les
 * rendrait orphelins.
 *
 * ## Écriture directe assumée
 *
 * La référence est réécrite par UPDATE direct : le cœur n'offre aucune API pour renommer une
 * facture validée (c'est un garde-fou de gestion courante, pas de reprise), et l'unique
 * contrainte `uk_facture_ref` protège l'opération. C'est une exception à la règle du module,
 * du même ordre que le `ref_ext` de l'adoption — documentée ici.
 *
 * ## Idempotence et sécurité
 *
 * Les cibles sont calculées depuis des données stables (numéro source, date, type, rowid) :
 * deux exécutions produisent exactement les mêmes références. Une facture déjà à sa cible est
 * ignorée — le script est rejouable, et `--limit` permet un lot d'essai. Avant toute écriture,
 * TOUTES les cibles sont vérifiées uniques entre elles et sans collision avec les références
 * laissées en place (brouillons). Aucun chevauchement possible avec les références actuelles :
 * les `FAyymm-` du quotidien portent un mois (≤ 12) là où les millésimes cibles portent une
 * seconde paire ≥ 16.
 *
 * Usage :
 *   php renumber_invoices.php                 simulation : ventilation, échantillons, contrôles
 *   php renumber_invoices.php --confirm       applique
 *   php renumber_invoices.php --limit=100 --confirm    lot d'essai
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

/** Coupure : début de l'exercice fiscal 2023-2024. */
const CUTOFF = '2023-10-01';

/** Longueur du compteur après coupure — celle de mod_facture_aero. */
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
 * Garde-fou : aucun document de facture ne doit subsister.
 */

$problems = array();

$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."ecm_files WHERE filepath = 'facture' OR filepath LIKE 'facture/%'");
if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->n > 0) {
    $problems[] = $obj->n." entrée(s) llx_ecm_files sous facture/";
}
$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."facture WHERE last_main_doc IS NOT NULL AND last_main_doc <> ''");
if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->n > 0) {
    $problems[] = $obj->n." facture(s) avec last_main_doc";
}
if (!empty($conf->facture->dir_output) && is_dir($conf->facture->dir_output)) {
    $handle = opendir($conf->facture->dir_output);
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $problems[] = 'le répertoire '.$conf->facture->dir_output.' n\'est pas vide ('.$entry.'…)';
        break;
    }
    closedir($handle);
}

if ($problems) {
    echo "REFUS : des documents de factures subsistent — renuméroter les rendrait orphelins.\n";
    foreach ($problems as $p) {
        echo "  - ".$p."\n";
    }
    echo "Supprimez d'abord les répertoires documents/facture/*, les lignes llx_ecm_files\n";
    echo "(filepath 'facture/%') et remettez last_main_doc à NULL — voir MISE_EN_PRODUCTION.md.\n";
    exit(1);
}


/*
 * Chargement du parc et calcul des cibles.
 */

$resql = $db->query('SELECT rowid, ref, ref_ext, datef, type FROM '.MAIN_DB_PREFIX.'facture'
    .' WHERE entity IN ('.getEntity('facture').')'
    .' ORDER BY datef, rowid');
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

$invoices = array();
$drafts   = 0;
while ($obj = $db->fetch_object($resql)) {
    if (strpos((string) $obj->ref, '(PROV') === 0) {
        $drafts++;
        continue;
    }
    $invoices[] = $obj;
}
$db->free($resql);

$targets    = array();   // rowid -> ref cible
$already    = 0;         // déjà à leur cible
$sequences  = array();   // "préfixe+millésime" -> dernier numéro attribué (après coupure)
$anomalies  = array();   // avant coupure sans numéro source exploitable
$perSeries  = array();   // ventilation pour le rapport : série -> [n, premier, dernier]

foreach ($invoices as $inv) {
    $code   = fiscalCode($inv->datef);
    $prefix = ((int) $inv->type === 2) ? 'AV' : (((int) $inv->type === 3) ? 'AC' : 'FA');

    if (substr($inv->datef, 0, 10) < CUTOFF) {
        // Avant la coupure : le numéro d'ADD, partie chiffrée telle quelle.
        if (strpos((string) $inv->ref_ext, REF_EXT_PREFIX) !== 0) {
            $anomalies[] = $inv->ref.' ('.substr($inv->datef, 0, 10).') : pas de numéro source';
            continue;
        }
        $digits = preg_replace('/\D+/', '', substr($inv->ref_ext, strlen(REF_EXT_PREFIX)));
        if ($digits === '') {
            $anomalies[] = $inv->ref.' : numéro source sans le moindre chiffre ('.$inv->ref_ext.')';
            continue;
        }
        $target = $prefix.$code.'-'.$digits;
    } else {
        // Après la coupure : séquence chronologique par exercice et par série.
        $serieKey = $prefix.$code;
        $sequences[$serieKey] = isset($sequences[$serieKey]) ? $sequences[$serieKey] + 1 : 1;
        $target = $prefix.$code.'-'.str_pad((string) $sequences[$serieKey], COUNTER_LENGTH, '0', STR_PAD_LEFT);
    }

    $serie = $prefix.$code;
    if (!isset($perSeries[$serie])) {
        $perSeries[$serie] = array('n' => 0, 'first' => $target, 'last' => $target);
    }
    $perSeries[$serie]['n']++;
    $perSeries[$serie]['last'] = $target;

    if ($inv->ref === $target) {
        $already++;
        continue;
    }
    $targets[(int) $inv->rowid] = $target;
}

/*
 * Contrôles avant écriture : unicité totale des cibles, aucune collision avec l'existant
 * qui reste en place (brouillons et déjà-conformes).
 */

$seen       = array();
$collisions = array();
foreach ($invoices as $inv) {
    $rowid = (int) $inv->rowid;
    $t = isset($targets[$rowid]) ? $targets[$rowid] : $inv->ref;   // cible, ou ref conservée
    if (isset($seen[$t])) {
        $collisions[] = $t;
    }
    $seen[$t] = true;
}
// Les brouillons, ignorés du renommage, occupent pourtant l'espace de noms.
$resql = $db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'facture'
    ." WHERE entity IN (".getEntity('facture').") AND ref LIKE '(PROV%'");
while ($resql && ($obj = $db->fetch_object($resql))) {
    if (isset($seen[$obj->ref])) {
        $collisions[] = $obj->ref.' (brouillon)';
    }
}

/*
 * Pré-phase : certaines références ACTUELLES occupent déjà une cible — les avoirs et
 * factures récents sont numérotés par mod_facture_aero au format définitif (AV2526-000094…).
 * L'état final est sans collision, mais l'ordre des renommages peut passer par un état
 * intermédiaire en conflit : le détenteur actuel d'une cible est donc d'abord déplacé vers
 * une référence temporaire unique, « (RN<rowid>) », que la passe principale remplacera.
 */
$targetSet = array_flip($targets);
$preRenames = array();   // rowid -> ref temporaire
foreach ($invoices as $inv) {
    $rowid = (int) $inv->rowid;
    if (!isset($targets[$rowid])) {
        continue;   // déjà conforme : sa référence EST sa cible, personne ne la lui dispute
    }
    if (isset($targetSet[$inv->ref])) {
        $preRenames[$rowid] = '(RN'.$rowid.')';
    }
}

echo "Script      : renumérotation des factures (coupure ".CUTOFF.")\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
printf("Parc        : %s facture(s), %d brouillon(s) ignoré(s)\n",
    number_format(count($invoices), 0, ',', ' '), $drafts);
printf("À renommer  : %s — déjà conformes : %s\n\n",
    number_format(count($targets), 0, ',', ' '), number_format($already, 0, ',', ' '));

echo "Ventilation par série :\n";
ksort($perSeries);
foreach ($perSeries as $serie => $s) {
    printf("  %-7s : %6s  de %-18s à %s\n", $serie,
        number_format($s['n'], 0, ',', ' '), $s['first'], $s['last']);
}

if ($anomalies) {
    echo "\nANOMALIES (".count($anomalies).") — avant coupure sans numéro source, RIEN ne sera écrit :\n";
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

// Échantillons, pour l'œil.
echo "\nÉchantillons :\n";
$shown = 0;
foreach ($invoices as $inv) {
    if (!isset($targets[(int) $inv->rowid])) {
        continue;
    }
    if ($shown < 4 || (substr($inv->datef, 0, 10) >= CUTOFF && $shown < 8)) {
        printf("  %-16s (%s) -> %s\n", $inv->ref, substr($inv->datef, 0, 10), $targets[(int) $inv->rowid]);
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

$done   = 0;
$failed = 0;
$start  = microtime(true);
$batch  = 0;

// Pré-phase : libérer les cibles occupées. En lot limité (--limit), seuls les détenteurs
// utiles sont déplacés — leur propre renommage définitif viendra d'un passage suivant.
$freed = 0;
$db->begin();
foreach ($preRenames as $rowid => $tmpRef) {
    $sql = 'UPDATE '.MAIN_DB_PREFIX."facture SET ref = '".$db->escape($tmpRef)."'"
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

$db->begin();
foreach ($todo as $rowid => $target) {
    $sql = 'UPDATE '.MAIN_DB_PREFIX."facture SET ref = '".$db->escape($target)."'"
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
        if ($done % 10000 === 0) {
            printf("  %s/%s renommée(s)  (%.0f/s)\n", number_format($done, 0, ',', ' '),
                number_format(count($todo), 0, ',', ' '), $done / max(1, microtime(true) - $start));
        }
    }
}
$db->commit();

printf("\nRenommées : %s  En échec : %d  Durée : %.0f s\n",
    number_format($done, 0, ',', ' '), $failed, microtime(true) - $start);

// Le compteur du quotidien : mod_facture_aero repartira du maximum de la série en cours.
$today = dol_print_date(dol_now(), '%Y-%m-%d');
$serie = 'FA'.fiscalCode($today);
if (isset($perSeries[$serie])) {
    echo "Série en cours ".$serie." : dernière référence ".$perSeries[$serie]['last']
        ." — le compteur du quotidien reprend après elle.\n";
}

$db->close();
exit($failed > 0 ? 1 : 0);
