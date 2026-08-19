<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/align_invoices_add.php
 * \ingroup aeromigration
 * \brief   Aligne le parc de factures Dolibarr sur la règle « ADD fait foi ».
 *
 * ------------------------------------------------------------------------------
 * CE SCRIPT SUPPRIME ET DÉCLASSE DES FACTURES — LISEZ LE PÉRIMÈTRE AVANT
 * ------------------------------------------------------------------------------
 *
 * ## La règle, posée par le client les 17 et 18 août 2026
 *
 * **Avant 2026, la base ADD fait foi sur tout.** Une facture qui n'existe pas dans ADD n'a
 * pas lieu d'être dans Dolibarr ; une facture qui y existe à l'état annulé doit exister ici
 * aussi — au statut « abandonnée », comme la reprise le fait déjà à la création. À partir de
 * 2026, une vente absente d'ADD peut être une commande pas encore honorée : elle est
 * signalée, jamais touchée. Depuis la bascule du flux web vers la boutique (juin 2026), ADD
 * ne fait plus foi du tout.
 *
 * ## Pourquoi le périmètre est recalculé à chaque exécution
 *
 * L'instance en ligne actuelle n'est qu'un test : tous les scripts seront rejoués sur la
 * vraie production, et la base ADD de référence est rafraîchie à volonté depuis l'export
 * CSV. Une liste de références figée serait fausse dès le premier rafraîchissement — le
 * script redérive donc ses trois ensembles de la base source au moment où il tourne, et il
 * est rejouable : ce qui est déjà conforme n'est pas retouché.
 *
 * ## Les trois passes
 *
 * **1. Suppression** — factures nées dans Dolibarr (aucun `ref_ext` de reprise), validées ou
 * payées, dont la commande est annulée dans ADD, ou sans aucune facture ADD active pour leur
 * commande (la commande peut même être absente d'ADD : paiement refusé puis recommandé, cas
 * Godineau). Les préparations qu'ADD montre sur ces commandes sont des documents générés par
 * les actions, tous annulés — vérifié pièce par pièce, elles ne protègent rien. Contrairement
 * au script des 311 (`delete_invoices_cancelled_orders.php`, aujourd'hui englobé par
 * celui-ci), les factures **encaissées partent aussi** : leurs règlements sont retirés
 * d'abord, le client ayant tranché que ces encaissements relèvent du prestataire de paiement.
 *
 * **2. Alignement** — factures reprises d'ADD (`ref_ext SAGE:`) encore actives alors que leur
 * pièce source est annulée. Le chemin de création les classe « abandonnée » depuis toujours ;
 * celles-ci sont passées par l'**adoption** (facture déjà créée par la boutique, marquée sans
 * être retouchée) et ont gardé statut et règlements. Or 40 des 45 recensées ont une facture
 * de remplacement active sur la même commande : leur encaissement compte double. Elles sont
 * ramenées au même état que les 666 correctement classées : règlements retirés, statut
 * abandonnée. `adoptInvoice()` fait désormais ce travail à l'adoption — cette passe rattrape
 * l'existant et sert de filet.
 *
 * **3. Signalement** — ventes de 2026 sans facture ADD sur commande non annulée : listées,
 * jamais touchées. Au 18/08/2026 : 3 expédiées jamais facturées (dont 2 716 € de créances
 * aéroclubs à recouvrer) et 5 commandes en attente.
 *
 * ## Comment la suppression passe, alors que Dolibarr la refuse
 *
 * Même chemin que le script des 311 : `is_erasable()` n'accepte de supprimer une facture
 * validée que si elle est la dernière de sa séquence, mais répond 1 sans autre contrôle sur
 * un brouillon dont la référence commence par « PROV ». D'où : règlements retirés, retour au
 * brouillon, référence temporaire `(PROVDEL<rowid>)`, `delete()`, puis le répertoire de
 * documents sous la référence d'origine. Un règlement partagé avec une facture hors périmètre
 * arrête le dossier, qui est signalé — le détruire retirerait un encaissement à une facture
 * légitime.
 *
 * Usage :
 *   php align_invoices_add.php                      dénombre et détaille, sans rien écrire
 *   php align_invoices_add.php --confirm            applique
 *   php align_invoices_add.php --pass=delete        une seule passe (delete | abandon)
 *   php align_invoices_add.php --limit=10 --confirm par petits lots
 *   php align_invoices_add.php --user=LOGIN
 *   php align_invoices_add.php --source-db=NOM      base des tables f_* (voir ci-dessous)
 *   php align_invoices_add.php --before=2026-06-01  début de la bascule boutique
 *   php align_invoices_add.php --active-before=2026-01-01  borne « ADD fait foi » (commandes actives)
 *
 * `--source-db=` sans valeur ramène la lecture dans la base de Dolibarr : c'est le cas des
 * hébergements à base unique, où les tables `f_*` cohabitent avec les `llx_*`. Sans l'option,
 * la constante `AEROMIG_SOURCE_DB` fait foi, comme pour les scripts de reprise.
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
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$langs->loadLangs(array('admin'));

/** Préfixe des ref_ext posés par la reprise — même valeur que AeroMigrationRunner. */
const REF_EXT_PREFIX = 'SAGE:';


/*
 * Arguments
 */

$confirm      = false;
$userLogin    = '';
$limit        = 0;
$sourceDb     = null;
$passOnly     = '';
$before       = '2026-06-01';   // début de la bascule du flux web vers la boutique
$activeBefore = '2026-01-01';   // « avant 2026, ADD fait foi sur tout »

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
    } elseif (preg_match('/^--pass=(delete|abandon)$/', $arg, $m)) {
        $passOnly = $m[1];
    } elseif (preg_match('/^--before=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $before = $m[1];
    } elseif (preg_match('/^--active-before=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $activeBefore = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--pass=delete|abandon] [--limit=N] [--user=LOGIN]\n";
        echo "       [--source-db=NOM] [--before=AAAA-MM-JJ] [--active-before=AAAA-MM-JJ]\n";
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
 * Ramène toutes les lignes d'une requête.
 *
 * @param  DoliDB $db  Connexion
 * @param  string $sql Requête
 * @return array<int,stdClass>
 */
function fetchAll($db, $sql)
{
    $resql = $db->query($sql);
    if (!$resql) {
        echo "Erreur SQL : ".$db->lasterror()."\n";
        exit(1);
    }
    $rows = array();
    while ($obj = $db->fetch_object($resql)) {
        $rows[] = $obj;
    }
    $db->free($resql);
    return $rows;
}


/*
 * Index ADD : trois lectures, tout le reste se joue en mémoire.
 */

echo "Lecture de la base source (".($sourceDb === '' ? $db->database_name : $sourceDb).")...\n";

// Commandes ADD : existence et annulation.
$orderExists    = array();
$orderCancelled = array();
foreach (fetchAll($db, 'SELECT DO_Piece, Z_Annule FROM '.src('f_docentete_global')." WHERE DO_Type = 1") as $o) {
    $orderExists[$o->DO_Piece] = true;
    if (trim((string) $o->Z_Annule) === 'A') {
        $orderCancelled[$o->DO_Piece] = true;
    }
}

// Factures ADD annulées, par pièce : la passe d'alignement s'y adosse.
$invoiceCancelled = array();
foreach (fetchAll($db, 'SELECT DO_Piece FROM '.src('f_docentete_global')
    ." WHERE DO_Type IN (6, 7) AND TRIM(COALESCE(Z_Annule, '')) = 'A'") as $o) {
    $invoiceCancelled[$o->DO_Piece] = true;
}

// Factures ADD actives rattachées à une commande, par les deux liens que la source connaît.
$activeInvoiceByOrder = array();
foreach (fetchAll($db, 'SELECT DO_NoWeb FROM '.src('f_docentete_global')
    ." WHERE DO_Type IN (6, 7) AND DO_NoWeb <> '' AND TRIM(COALESCE(Z_Annule, '')) <> 'A'") as $o) {
    $activeInvoiceByOrder[$o->DO_NoWeb] = true;
}
foreach (fetchAll($db, 'SELECT DISTINCT dl.DL_PieceBC AS piece FROM '.src('f_docligne_global').' as dl'
    .' INNER JOIN '.src('f_docentete_global').' as e ON e.DO_Piece = dl.DO_Piece AND e.DO_Type = dl.DO_Type'
    ." WHERE dl.DO_Type IN (6, 7) AND dl.DL_PieceBC <> '' AND TRIM(COALESCE(e.Z_Annule, '')) <> 'A'") as $o) {
    $activeInvoiceByOrder[$o->piece] = true;
}

printf("  commandes : %s (dont %s annulées) — factures annulées : %s\n\n",
    number_format(count($orderExists), 0, ',', ' '),
    number_format(count($orderCancelled), 0, ',', ' '),
    number_format(count($invoiceCancelled), 0, ',', ' '));


/*
 * Outils d'écriture partagés par les deux passes
 */

/**
 * Retire les règlements d'une facture, en refusant d'y toucher s'ils sont partagés.
 *
 * @param  Facture $invoice Facture
 * @param  string  &$error  Motif en cas de refus
 * @return int              Nombre de règlements retirés, -1 si l'un d'eux a résisté
 */
function stripPayments($invoice, &$error)
{
    global $db, $user;

    $sql  = 'SELECT pf.fk_paiement, COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'paiement_facture as pf';
    $sql .= ' WHERE pf.fk_paiement IN (SELECT fk_paiement FROM '.MAIN_DB_PREFIX.'paiement_facture';
    $sql .= ' WHERE fk_facture = '.((int) $invoice->id).')';
    $sql .= ' GROUP BY pf.fk_paiement';

    $resql = $db->query($sql);
    if (!$resql) {
        $error = $db->lasterror();
        return -1;
    }
    $payments = array();
    while ($obj = $db->fetch_object($resql)) {
        $payments[(int) $obj->fk_paiement] = (int) $obj->nb;
    }
    $db->free($resql);

    if (empty($payments)) {
        return 0;
    }

    // Une facture close n'abandonne pas ses règlements : Dolibarr répond
    // « ErrorDeletePaymentLinkedToAClosedInvoiceNotPossible ». Rouverte d'abord.
    if ((int) $invoice->statut === Facture::STATUS_CLOSED || !empty($invoice->paye)) {
        $invoice->setUnpaid($user);
        $invoice->fetch((int) $invoice->id);
    }

    $removed = 0;
    foreach ($payments as $paymentId => $nbInvoices) {
        if ($nbInvoices > 1) {
            $error = 'règlement '.$paymentId.' partagé avec une autre facture, dossier laissé en l\'état';
            return -1;
        }
        $payment = new Paiement($db);
        if ($payment->fetch($paymentId) <= 0) {
            continue;
        }
        if ($payment->delete($user) <= 0) {
            $error = 'règlement '.$paymentId.' : '.(!empty($payment->error) ? $payment->error : 'suppression refusée');
            return -1;
        }
        $removed++;
    }

    return $removed;
}

/**
 * Supprime une facture par le chemin du brouillon PROV — voir l'entête du fichier.
 *
 * @param  int    $id     Facture
 * @param  string &$error Motif en cas d'échec
 * @return bool
 */
function dropInvoice($id, &$error)
{
    global $db, $user, $conf;

    $invoice = new Facture($db);
    if ($invoice->fetch($id) <= 0) {
        $error = 'chargement impossible';
        return false;
    }
    $refBefore = $invoice->ref;

    $db->begin();
    try {
        $stripError = '';
        if (stripPayments($invoice, $stripError) < 0) {
            throw new Exception($stripError);
        }

        // Le brouillon est refusé tant que la facture porte un règlement : d'où l'ordre.
        if ((int) $invoice->statut !== Facture::STATUS_DRAFT) {
            if ($invoice->setDraft($user) < 0) {
                throw new Exception('retour au brouillon refusé — '.$invoice->error);
            }
            $invoice->fetch($id);
        }

        $tmpRef = '(PROVDEL'.$id.')';
        if ($invoice->setValueFrom('ref', $tmpRef, '', null, 'text', '', $user) < 0) {
            throw new Exception('référence temporaire refusée — '.$invoice->error);
        }
        $invoice->fetch($id);

        if ($invoice->delete($user) <= 0) {
            throw new Exception('suppression refusée — '.($invoice->error !== '' ? $invoice->error : 'is_erasable()'));
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        $error = $e->getMessage();
        return false;
    }

    // Le répertoire porte la référence d'ORIGINE : delete() l'a cherché sous le nom
    // temporaire. Retiré hors transaction, le système de fichiers n'y participant pas.
    if (!empty($conf->facture->dir_output)) {
        $dir = $conf->facture->dir_output.'/'.dol_sanitizeFileName($refBefore);
        if (is_dir($dir)) {
            dol_delete_dir_recursive($dir);
        }
    }

    return true;
}


/*
 * Passe 1 — suppression : les factures qu'ADD n'a jamais émises
 */

$reported = array();   // passe 3, alimentée pendant le classement

if ($passOnly === '' || $passOnly === 'delete') {
    $rows = fetchAll($db,
        'SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.paye, s.nom AS client,'
        .' c.ref AS cref, c.ref_ext AS cext'
        .' FROM '.MAIN_DB_PREFIX.'facture as f'
        .' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = f.fk_soc'
        .' LEFT JOIN '.MAIN_DB_PREFIX.'element_element as ee'
        ."      ON ee.fk_target = f.rowid AND ee.sourcetype = 'commande' AND ee.targettype = 'facture'"
        .' LEFT JOIN '.MAIN_DB_PREFIX.'commande as c ON c.rowid = ee.fk_source'
        .' WHERE f.entity IN ('.getEntity('facture').')'
        ." AND (f.ref_ext IS NULL OR f.ref_ext NOT LIKE '".$db->escape(REF_EXT_PREFIX)."%')"
        .' AND f.fk_statut IN ('.Facture::STATUS_VALIDATED.', '.Facture::STATUS_CLOSED.')'
        ." AND f.datef < '".$db->escape($before)."'"
        .' ORDER BY f.datef, f.rowid');

    $toDelete = array();
    foreach ($rows as $o) {
        // La pièce de commande côté ADD : le marqueur de reprise d'abord, la référence
        // numérique des commandes du site ensuite. Une référence aléatoire sans marqueur est
        // une commande née dans la boutique : ADD ne l'a jamais connue, elle est hors règle.
        if ($o->cext && strpos($o->cext, REF_EXT_PREFIX) === 0) {
            $piece = substr($o->cext, strlen(REF_EXT_PREFIX));
        } elseif ($o->cref !== null && preg_match('/^\d+$/', (string) $o->cref)) {
            $piece = 'CI-'.$o->cref;
        } else {
            continue;
        }

        if (isset($orderCancelled[$piece])) {
            $motif = 'commande annulée dans ADD';
        } elseif (!isset($activeInvoiceByOrder[$piece])) {
            $motif = isset($orderExists[$piece]) ? 'jamais facturée dans ADD' : 'commande absente d\'ADD';
            if (substr($o->datef, 0, 10) >= $activeBefore) {
                // « À partir de 2026, peut-être pas encore honorée » : signalée, pas touchée.
                $reported[] = array('row' => $o, 'piece' => $piece, 'motif' => $motif);
                continue;
            }
        } else {
            continue;
        }

        $toDelete[] = array('row' => $o, 'piece' => $piece, 'motif' => $motif);
    }

    if ($limit > 0 && count($toDelete) > $limit) {
        $toDelete = array_slice($toDelete, 0, $limit);
    }

    $total = 0.0;
    foreach ($toDelete as $t) {
        $total += (float) $t['row']->total_ttc;
    }

    echo "=== Passe 1 — suppression : ".count($toDelete)." facture(s), ".price($total)." TTC ===\n";
    foreach ($toDelete as $t) {
        printf("  %-16s %s %10s  paye=%s  %-12s %-28s %s\n",
            $t['row']->ref, substr($t['row']->datef, 0, 10), price($t['row']->total_ttc),
            $t['row']->paye, $t['piece'], $t['motif'],
            dol_trunc((string) $t['row']->client, 24));
    }

    if ($confirm && $toDelete) {
        $done = 0;
        $failed = 0;
        foreach ($toDelete as $t) {
            $error = '';
            if (dropInvoice((int) $t['row']->rowid, $error)) {
                $done++;
            } else {
                $failed++;
                echo "  ÉCHEC ".$t['row']->ref." : ".$error."\n";
            }
        }
        echo "  -> supprimées : ".$done.($failed ? "  EN ÉCHEC : ".$failed : '')."\n";
    }
    echo "\n";
}


/*
 * Passe 2 — alignement : les factures actives qu'ADD tient pour annulées
 */

if ($passOnly === '' || $passOnly === 'abandon') {
    $rows = fetchAll($db,
        'SELECT f.rowid, f.ref, f.ref_ext, f.datef, f.total_ttc, f.paye'
        .' FROM '.MAIN_DB_PREFIX.'facture as f'
        .' WHERE f.entity IN ('.getEntity('facture').')'
        ." AND f.ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'"
        .' AND f.fk_statut IN ('.Facture::STATUS_VALIDATED.', '.Facture::STATUS_CLOSED.')'
        .' ORDER BY f.datef, f.rowid');

    $toAbandon = array();
    foreach ($rows as $o) {
        $piece = substr($o->ref_ext, strlen(REF_EXT_PREFIX));
        if (isset($invoiceCancelled[$piece])) {
            $toAbandon[] = array('row' => $o, 'piece' => $piece);
        }
    }

    if ($limit > 0 && count($toAbandon) > $limit) {
        $toAbandon = array_slice($toAbandon, 0, $limit);
    }

    $total = 0.0;
    foreach ($toAbandon as $t) {
        $total += (float) $t['row']->total_ttc;
    }

    echo "=== Passe 2 — alignement en « abandonnée » : ".count($toAbandon)." facture(s), ".price($total)." TTC ===\n";
    foreach ($toAbandon as $t) {
        printf("  %-16s %s %10s  paye=%s  %s (annulée dans ADD)\n",
            $t['row']->ref, substr($t['row']->datef, 0, 10), price($t['row']->total_ttc),
            $t['row']->paye, $t['piece']);
    }

    if ($confirm && $toAbandon) {
        $done = 0;
        $failed = 0;
        foreach ($toAbandon as $t) {
            $invoice = new Facture($db);
            if ($invoice->fetch((int) $t['row']->rowid) <= 0) {
                $failed++;
                echo "  ÉCHEC ".$t['row']->ref." : chargement impossible\n";
                continue;
            }

            $db->begin();
            $error = '';
            // Une annulée n'a pas de règlement : c'est ce que le chemin de création fait
            // depuis toujours (aucune des 666 n'en porte), et 40 de ces factures ont une
            // remplaçante active sur la même commande — l'encaissement compterait double.
            if (stripPayments($invoice, $error) < 0) {
                $db->rollback();
                $failed++;
                echo "  ÉCHEC ".$t['row']->ref." : ".$error."\n";
                continue;
            }
            if ($invoice->setCanceled($user, 'abandon', 'Annulée dans ADD — alignement reprise') <= 0) {
                $db->rollback();
                $failed++;
                echo "  ÉCHEC ".$t['row']->ref." : ".$invoice->error."\n";
                continue;
            }
            $db->commit();
            $done++;
        }
        echo "  -> abandonnées : ".$done.($failed ? "  EN ÉCHEC : ".$failed : '')."\n";
    }
    echo "\n";
}


/*
 * Passe 3 — signalement : les ventes de 2026 que la règle épargne
 */

if ($reported) {
    echo "=== Passe 3 — signalées, jamais touchées (ventes ".$activeBefore." → ".$before.") ===\n";
    foreach ($reported as $t) {
        printf("  %-16s %s %10s  paye=%s  %-12s %-24s %s\n",
            $t['row']->ref, substr($t['row']->datef, 0, 10), price($t['row']->total_ttc),
            $t['row']->paye, $t['piece'], $t['motif'],
            dol_trunc((string) $t['row']->client, 24));
    }
    echo "\n";
}

if (!$confirm) {
    echo "Simulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit(0);
