<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/classify_paid_invoices.php
 * \ingroup aeromigration
 * \brief   Aligne le statut de règlement des factures reprises sur celui de l'ancien ERP.
 *
 * ------------------------------------------------------------------------------
 * LA TABLE DES RÈGLEMENTS EST INCOMPLÈTE — LE DRAPEAU « RÉGLÉE » FAIT FOI
 * ------------------------------------------------------------------------------
 *
 * La reprise des factures pose « payée » quand les règlements de `z_docregl_global`
 * couvrent la pièce. Or cette table ne commence qu'en novembre 2019, et même ensuite elle
 * ne voit pas tout : sur 2020-2025, 6 739 factures marquées RÉGLÉES par l'ancien ERP n'y
 * ont aucune ligne. Avant fin 2019, rien du tout — les 71 585 factures de l'ère Sage
 * native sont restées « impayées » dans Dolibarr alors qu'elles ont été encaissées.
 *
 * L'entête de document porte le drapeau qui fait foi : `f_docentete_global.DR_Regle`.
 * Calibré sur 2020-2025 contre les règlements connus : 88 400 concordances, 12
 * contradictions. C'est l'état de l'ancien système, et la règle du projet est « ADD fait
 * foi ». Voir D3 dans ANOMALIES.md.
 *
 * ## Les deux passes (arbitrage client du 31/08/2026)
 *
 *   1. Facture impayée chez nous, RÉGLÉE dans ADD (`DR_Regle = 1`)
 *      → classée PAYÉE, sans réserve : l'encaissement a eu lieu, seul son détail manque.
 *   2. Facture impayée des deux côtés (`DR_Regle = 0`), datée avant la borne
 *      (défaut : 01/01/2019) → classée ABANDONNÉE, note « Prescription ».
 *
 * Ce qui reste impayé après les deux passes est la VRAIE liste de recouvrement — environ
 * deux mille pièces récentes, créances aéroclubs comprises — que le script dénombre par
 * exercice sans y toucher.
 *
 * ## Neutralisation en mémoire (même mécanique que P20)
 *
 * `Facture::setPaid()` déclenche BILL_PAYED, et l'agenda est configuré pour créer un
 * événement par facture classée (`MAIN_AGENDA_ACTIONAUTO_BILL_PAYED`) : un passage
 * complet créerait ~78 000 événements sans valeur. La constante est retirée DE LA MÉMOIRE
 * du seul processus du script — rien n'est écrit en configuration, l'application n'est
 * pas touchée. Aucun autre déclencheur n'écoute BILL_PAYED sur cette instance (vérifié :
 * ni Prestasync, ni aerotoolbox).
 *
 * ## Idempotent, et à rejouer au jour J
 *
 * Une facture déjà classée n'est pas revisitée (le parcours ne lit que les impayées).
 * Le rejeu de production recréera les factures impayées : ce script se repasse APRÈS
 * `invoice`, comme `close_delivered_orders` — voir MISE_EN_PRODUCTION.md.
 *
 * Usage :
 *   php classify_paid_invoices.php                    simulation : ventilation, rien d'écrit
 *   php classify_paid_invoices.php --confirm          applique
 *   php classify_paid_invoices.php --limit=50 --confirm    lot d'essai
 *   php classify_paid_invoices.php --before=2019-01-01     borne de prescription (défaut)
 *   php classify_paid_invoices.php --source-db=NOM    base des tables ADD (défaut : AEROMIG_SOURCE_DB)
 *   php classify_paid_invoices.php --user=LOGIN
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
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

$langs->loadLangs(array('admin', 'bills'));

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** Nombre d'exemples listés par catégorie. */
const SAMPLES = 8;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$sourceDb  = null;
$before    = '2019-01-01';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
    } elseif (preg_match('/^--before=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $before = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--before=AAAA-MM-JJ] [--source-db=NOM] [--user=LOGIN]\n";
        exit(1);
    }
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
    $sql   = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1';
    $sql  .= ' AND entity IN (0, '.((int) $conf->entity).') ORDER BY rowid ASC LIMIT 1';
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
 * Base des tables source — même résolution que les scripts de reprise.
 */

if ($sourceDb === null) {
    $sourceDb = trim(getDolGlobalString('AEROMIG_SOURCE_DB', 'aeroprod'));
}
if ($sourceDb !== '' && $sourceDb === $db->database_name) {
    $sourceDb = '';
}

/**
 * Qualifie une table source.
 *
 * @param  string $table Nom de la table
 * @return string        Nom qualifié si la source est dans une autre base
 */
function src($table)
{
    global $sourceDb;

    return ($sourceDb === '') ? $table : '`'.$sourceDb.'`.'.$table;
}

if (!$db->query('SELECT 1 FROM '.src('f_docentete_global').' LIMIT 1', 1)) {
    echo "Table source inaccessible : ".src('f_docentete_global')."\n";
    echo "Précisez --source-db=NOM, ou --source-db= si les tables f_* sont dans la base de Dolibarr.\n";
    exit(1);
}

/**
 * Exercice fiscal d'une date (coupure au 1er octobre), pour la ventilation du rapport.
 *
 * @param  string $date Date SQL (AAAA-MM-JJ…)
 * @return string       « 2018-2019 »
 */
function exercice($date)
{
    $y = (int) substr($date, 0, 4);
    $m = (int) substr($date, 5, 2);

    return ($m >= 10) ? $y.'-'.($y + 1) : ($y - 1).'-'.$y;
}


/*
 * Neutralisation en mémoire : pas un événement d'agenda par facture classée.
 * Rien n'est écrit en base — seule la mémoire de ce processus est concernée.
 */

unset($conf->global->MAIN_AGENDA_ACTIONAUTO_BILL_PAYED);


/*
 * 1. L'état de règlement de chaque pièce dans l'ancien ERP.
 */

$regle = array();
$resql = $db->query('SELECT DO_Piece, DR_Regle FROM '.src('f_docentete_global')
    .' WHERE DO_Domaine = 0 AND DO_Type IN (6, 7)');
if (!$resql) {
    echo "Lecture des entêtes impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $regle[trim((string) $obj->DO_Piece)] = (int) $obj->DR_Regle;
}
$db->free($resql);


/*
 * 2. Les factures reprises encore impayées.
 */

$invoices = array();
$resql = $db->query('SELECT rowid, ref, ref_ext, type, datef, total_ttc FROM '.MAIN_DB_PREFIX.'facture'
    ." WHERE entity IN (".getEntity('invoice').")"
    .' AND fk_statut = '.Facture::STATUS_VALIDATED.' AND paye = 0'
    ." AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'"
    .' ORDER BY datef ASC, rowid ASC');
if (!$resql) {
    echo "Lecture des factures impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $invoices[] = $obj;
}
$db->free($resql);

echo "Script      : alignement du statut de règlement sur l'ancien ERP (DR_Regle fait foi)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Source      : ".($sourceDb !== '' ? $sourceDb : $db->database_name)."\n";
echo "Prescription: factures non réglées datées avant le ".$before."\n";
echo "Impayées SAGE en base : ".number_format(count($invoices), 0, ',', ' ')."\n";
echo str_repeat('-', 60)."\n";


/*
 * 3. Les deux passes.
 */

$stats = array(
    'payee'       => array(),  // exercice => [nb, ttc]
    'prescrite'   => array(),
    'recouvrement' => array(), // laissées impayées, DR_Regle = 0 après la borne
    'sans_entete' => 0,
    'avoirs_payes' => 0,
    'erreur'      => 0,
    'ecrits'      => 0,
);
$samples = array('sans_entete' => array(), 'erreur' => array(), 'recouvrement' => array());

$facture = new Facture($db);

foreach ($invoices as $inv) {
    $piece = substr((string) $inv->ref_ext, strlen(REF_EXT_PREFIX));

    if (!isset($regle[$piece])) {
        // Pièce absente des entêtes ADD : rien ne permet de trancher, on ne touche pas.
        $stats['sans_entete']++;
        if (count($samples['sans_entete']) < SAMPLES) {
            $samples['sans_entete'][] = $inv->ref;
        }
        continue;
    }

    $exe = exercice($inv->datef);

    if ($regle[$piece] === 1) {
        $pass = 'payee';
        $closeCode = '';
        $closeNote = '';
    } elseif ($inv->datef < $before) {
        $pass = 'prescrite';
        $closeCode = 'abandon';
        $closeNote = 'Prescription';
    } else {
        // Impayée des deux côtés, trop récente pour la prescription : la vraie liste
        // de recouvrement. Dénombrée, jamais touchée.
        if (!isset($stats['recouvrement'][$exe])) {
            $stats['recouvrement'][$exe] = array(0, 0.0);
        }
        $stats['recouvrement'][$exe][0]++;
        $stats['recouvrement'][$exe][1] += (float) $inv->total_ttc;
        if (count($samples['recouvrement']) < SAMPLES) {
            $samples['recouvrement'][] = $inv->ref.' ('.price2num($inv->total_ttc).' €)';
        }
        continue;
    }

    if ($confirm) {
        // setPaid() n'a besoin que de l'identifiant ; l'objet reçoit aussi l'état courant
        // pour que sa garde interne (déjà payée ?) raisonne juste. Chaque classement est
        // sa propre transaction dans le coeur : une interruption se reprend sans dégât.
        $facture->id     = (int) $inv->rowid;
        $facture->ref    = $inv->ref;
        $facture->paye   = 0;
        $facture->status = Facture::STATUS_VALIDATED;

        if ($facture->setPaid($user, $closeCode, $closeNote) <= 0) {
            $stats['erreur']++;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $inv->ref.' : '.($facture->error !== '' ? $facture->error : 'refus');
            }
            continue;
        }
    }

    if (!isset($stats[$pass][$exe])) {
        $stats[$pass][$exe] = array(0, 0.0);
    }
    $stats[$pass][$exe][0]++;
    $stats[$pass][$exe][1] += (float) $inv->total_ttc;
    if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE) {
        $stats['avoirs_payes']++;
    }

    $stats['ecrits']++;
    if ($stats['ecrits'] % 2000 === 0) {
        printf("  %s traitées…\n", number_format($stats['ecrits'], 0, ',', ' '));
    }
    if ($limit > 0 && $stats['ecrits'] >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}


/*
 * 4. Rapport.
 */

$ventile = function ($byExe) {
    ksort($byExe);
    $totalN = 0;
    $totalM = 0.0;
    foreach ($byExe as $exe => $x) {
        printf("  %s : %7s pièce(s), %14s € TTC\n", $exe,
            number_format($x[0], 0, ',', ' '), number_format($x[1], 2, ',', ' '));
        $totalN += $x[0];
        $totalM += $x[1];
    }
    printf("  %-9s %7s pièce(s), %14s € TTC\n", 'TOTAL', number_format($totalN, 0, ',', ' '),
        number_format($totalM, 2, ',', ' '));
};

echo "\nClassées PAYÉES (réglées dans l'ancien ERP)".($confirm ? '' : ' — simulation')." :\n";
if (empty($stats['payee'])) {
    echo "  aucune\n";
} else {
    $ventile($stats['payee']);
}

echo "\nClassées ABANDONNÉES « Prescription » (non réglées, avant le ".$before.") :\n";
if (empty($stats['prescrite'])) {
    echo "  aucune\n";
} else {
    $ventile($stats['prescrite']);
}

echo "\nLaissées impayées — la liste de recouvrement réelle :\n";
if (empty($stats['recouvrement'])) {
    echo "  aucune\n";
} else {
    $ventile($stats['recouvrement']);
    echo "  ex. : ".implode(', ', $samples['recouvrement'])."\n";
}

if ($stats['avoirs_payes'] > 0) {
    echo "\nDont avoirs classés : ".number_format($stats['avoirs_payes'], 0, ',', ' ')."\n";
}
if ($stats['sans_entete'] > 0) {
    echo "\nPièces sans entête dans ADD, laissées en l'état : ".$stats['sans_entete']."\n";
    echo "  ex. : ".implode(', ', $samples['sans_entete'])."\n";
}
if ($stats['erreur'] > 0) {
    echo "\nÉCHECS : ".$stats['erreur']."\n";
    echo "  ex. : ".implode(' | ', $samples['erreur'])."\n";
}

if ($confirm) {
    // État final, tel que la liste des factures l'affichera.
    $resql = $db->query('SELECT fk_statut, paye, COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'facture'
        .' WHERE entity IN ('.getEntity('invoice').') GROUP BY fk_statut, paye ORDER BY fk_statut, paye');
    if ($resql) {
        echo "\nRépartition finale des factures (statut / payé) :\n";
        while ($obj = $db->fetch_object($resql)) {
            printf("  statut %s, paye %s : %s\n", $obj->fk_statut, $obj->paye,
                number_format($obj->nb, 0, ',', ' '));
        }
        $db->free($resql);
    }
} else {
    echo "\nSIMULATION terminée, rien n'a été écrit. Relancer avec --confirm pour appliquer.\n";
}
