<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/invoice_web_orders.php
 * \ingroup aeromigration
 * \brief   Facture et règle les commandes du site que la facturation automatique n'a pas couvertes.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * Prestasync facture une commande à son import (PRESTASYNC_INVOICE_GENERATE) et y pose les
 * règlements lus dans PrestaShop (PRESTASYNC_INVOICE_CHECK_FOR_PAIEMENTS). Autour de la
 * bascule, cette chaîne n'a pas joué pour toutes les commandes : celles reprises d'ADD
 * (1er → 5 septembre, `SAGE:CI…`, rattachées au site après coup) n'ont jamais été facturées
 * ici, et les premières commandes synchronisées ont parfois leur facture sans son règlement.
 * Résultat : des factures manquantes et des factures validées « impayées » alors que la carte
 * a été débitée.
 *
 * ## Ce que fait le script
 *
 * Pour chaque commande Dolibarr rattachée à une commande du site (`llx_prestasync_order`),
 * datée du 1er septembre 2026 ou après (`--from=`), il rejoue ce que Prestasync aurait fait,
 * avec ses propres classes (API PrestaShop, mapping des modes de paiement) :
 *
 * 1. **La facture**, si la commande n'en a aucune : `Facture::createFromOrder()`, compte
 *    bancaire de la commande ou du mapping (repli PRESTASYNC_INVOICE_BANK_ACCOUNT), validation
 *    (entrepôt MAIN_DEFAULT_WAREHOUSE), commande classée facturée, PDF (FACTURE_ADDON_PDF).
 *    Pas d'e-mail au client. Datée du jour, comme toute facture validée après coup.
 * 2. **Les règlements**, si la facture (existante ou créée) n'est pas soldée : un règlement
 *    Dolibarr par paiement PrestaShop (`order_payments`) pas encore importé — repéré par la
 *    note « ID : <id PrestaShop> » que Prestasync écrit sur ses règlements, ou par le numéro de
 *    transaction —, à la date PrestaShop, mode et compte du mapping, écriture en banque,
 *    facture classée payée si soldée. Un paiement plus grand que le reste à payer est plafonné
 *    et signalé.
 *
 * ## Ce qu'il ne fait pas, et dit
 *
 * - Commande Dolibarr brouillon ou annulée : rien.
 * - Commande PrestaShop annulée, remboursée (même partiellement) ou en erreur de paiement :
 *   rien — ce sont des cas à traiter à la main (avoir, remboursement).
 * - Mode de paiement dont le mapping ne crée pas de règlement (virement) : la facture est
 *   faite, le règlement attendu est listé, à pointer à la main comme d'habitude.
 * - Facture existante en brouillon, ou plusieurs factures sur la commande : rien, listé.
 *
 * Rejouable : une commande complète ne produit rien. Une transaction par commande.
 *
 * Usage :
 *   php invoice_web_orders.php                     simulation (l'API PrestaShop est interrogée, rien n'est écrit)
 *   php invoice_web_orders.php --confirm           applique
 *   php invoice_web_orders.php --from=2026-09-01   date de commande minimale (défaut)
 *   php invoice_web_orders.php --order=CO2526-007709   une seule commande
 *   php invoice_web_orders.php --limit=5 --confirm lot d'essai
 *   php invoice_web_orders.php --csv=/chemin/liste.csv  écrit aussi le détail par commande
 *   php invoice_web_orders.php --user=LOGIN
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
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/prestasync/class/presta.class.php');
dol_include_once('/prestasync/class/prestaOrder.class.php');
dol_include_once('/prestasync/class/mappingpayment.class.php');

$langs->loadLangs(array('admin', 'bills', 'orders', 'prestasync@prestasync'));

if (!isModEnabled('prestasync')) {
    echo "Le module prestasync doit être actif : il porte l'accès à l'API PrestaShop.\n";
    exit(1);
}

/** Statuts PrestaShop qui interdisent de facturer : annulé, remboursé, erreur de paiement, partiellement remboursé. */
const PS_STATES_NO_INVOICE = array(6, 7, 8, 15);

/** Tolérance sur les montants. */
const EPSILON = 0.005;

/** Cas connu, listé au rapport : la commande est passée sans rien écrire. */
class SkipOrderException extends Exception
{
}

/**
 * Montant « à la française », indépendant de l'objet langue global — que la génération des PDF
 * retouche en cours de passage (les montants du rapport changeaient de format après la première).
 *
 * @param  float $v Montant
 * @return string
 */
function fmt_amount($v)
{
    return number_format((float) $v, 2, ',', ' ').' €';
}


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$from      = '2026-09-01';
$onlyOrder = '';
$csvPath   = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $from = $m[1];
    } elseif (preg_match('/^--order=(.+)$/', $arg, $m)) {
        $onlyOrder = trim($m[1]);
    } elseif (preg_match('/^--csv=(.+)$/', $arg, $m)) {
        $csvPath = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--from=AAAA-MM-JJ] [--order=REF] [--limit=N] [--csv=FICHIER] [--user=LOGIN]\n";
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
 * Les commandes du périmètre : rattachées au site, datées de --from ou après.
 */

$sql  = 'SELECT c.rowid, c.ref, c.ref_client, c.fk_statut, c.total_ttc, c.date_commande, c.fk_soc,';
$sql .= ' po.fk_presta, po.fk_order_presta';
$sql .= ' FROM '.MAIN_DB_PREFIX.'commande AS c';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'prestasync_order AS po ON po.fk_commande_doli = c.rowid';
$sql .= ' WHERE c.entity IN ('.getEntity('commande').')';
$sql .= " AND c.date_commande >= '".$db->escape($from)."'";
if ($onlyOrder !== '') {
    $sql .= " AND c.ref = '".$db->escape($onlyOrder)."'";
}
$sql .= ' ORDER BY c.date_commande ASC, c.rowid ASC';

$orders = array();
$resql = $db->query($sql);
while ($resql && ($o = $db->fetch_object($resql))) {
    $orders[] = $o;
}
if ($resql) {
    $db->free($resql);
}
if (empty($orders)) {
    echo "Aucune commande du site datée du ".$from." ou après.\n";
    exit(0);
}

// Une connexion PrestaShop par fk_presta (une seule en pratique), chargée à la demande.
$prestas = array();
/**
 * @param  int $id fk_presta
 * @return Presta|null
 */
function presta_get($id)
{
    global $db, $prestas;
    $id = (int) $id;
    if (!isset($prestas[$id])) {
        $p = new Presta($db);
        $prestas[$id] = ($p->fetch($id) > 0) ? $p : null;
    }

    return $prestas[$id];
}

/**
 * Les factures liées à la commande (hors avoirs).
 *
 * @param  DoliDB $db      Base
 * @param  int    $orderId Commande
 * Les avoirs et les factures négatives (remboursements repris d'ADD) ne comptent pas : une
 * commande « facture + remboursement » est une commande facturée.
 *
 * @return array<int,object> rowid, ref, fk_statut, paye, type, total_ttc
 */
function order_invoices($db, $orderId)
{
    $out = array();
    $sql = 'SELECT f.rowid, f.ref, f.fk_statut, f.paye, f.type, f.total_ttc FROM '.MAIN_DB_PREFIX.'element_element AS ee'
        .' INNER JOIN '.MAIN_DB_PREFIX.'facture AS f ON f.rowid = ee.fk_target'
        ." WHERE ee.fk_source = ".((int) $orderId)." AND ee.sourcetype = 'commande' AND ee.targettype = 'facture'"
        .' AND f.type <> '.Facture::TYPE_CREDIT_NOTE.' AND f.total_ttc >= 0 ORDER BY f.rowid';
    $resql = $db->query($sql);
    while ($resql && ($o = $db->fetch_object($resql))) {
        $out[] = $o;
    }

    return $out;
}

/**
 * Les règlements déjà posés sur une facture : montant total et marqueurs d'origine PrestaShop
 * (id de paiement lu dans la note, numéro de transaction).
 *
 * @param  DoliDB $db        Base
 * @param  int    $invoiceId Facture
 * @return array{total:float,psids:int[],nums:string[]}
 */
function invoice_payments($db, $invoiceId)
{
    $out = array('total' => 0.0, 'psids' => array(), 'nums' => array());
    $sql = 'SELECT p.rowid, p.num_paiement, p.note, pf.amount FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf'
        .' INNER JOIN '.MAIN_DB_PREFIX.'paiement AS p ON p.rowid = pf.fk_paiement'
        .' WHERE pf.fk_facture = '.((int) $invoiceId);
    $resql = $db->query($sql);
    while ($resql && ($o = $db->fetch_object($resql))) {
        $out['total'] += (float) $o->amount;
        if (preg_match('/^ID : (\d+)/m', (string) $o->note, $m)) {
            $out['psids'][] = (int) $m[1];
        }
        if ((string) $o->num_paiement !== '') {
            $out['nums'][] = (string) $o->num_paiement;
        }
    }

    return $out;
}


/*
 * Parcours
 */

$stats = array(
    'commandes'       => count($orders),
    'completes'       => 0,   // rien à faire
    'factures'        => 0,   // factures créées (ou à créer)
    'reglements'      => 0,   // règlements créés (ou à créer)
    'reglements_ttc'  => 0.0,
    'attente_manuel'  => 0,   // règlement à pointer à la main (mapping sans création)
    'ignorees'        => 0,   // brouillon/annulée Dolibarr, statut PS bloquant, facture brouillon, ambiguïté
    'ecarts'          => 0,   // paiement plafonné, montants différents
    'erreurs'         => 0,
);
$rows   = array();   // détail par commande, pour l'écran et le CSV
$errors = array();

echo "Script      : facturation et règlement des commandes du site (rattrapage Prestasync)\n";
echo "Utilisateur : ".$user->login."\n";
echo "Depuis      : ".$from.($onlyOrder !== '' ? ' — commande '.$onlyOrder : '')."\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo str_repeat('-', 60)."\n";

$done = 0;
foreach ($orders as $o) {
    $row = array(
        'commande' => $o->ref, 'ps' => '', 'etat_ps' => '', 'facture' => '', 'action_facture' => '',
        'reglements' => '', 'action_reglement' => '', 'note' => '',
    );
    $rows[] = &$row;

    // ── Garde Dolibarr ─────────────────────────────────────────────────────
    if ((int) $o->fk_statut <= Commande::STATUS_DRAFT) {
        $row['action_facture'] = 'ignorée';
        $row['note'] = ((int) $o->fk_statut === Commande::STATUS_CANCELED) ? 'commande annulée' : 'commande brouillon';
        $stats['ignorees']++;
        unset($row);
        continue;
    }

    // ── Côté PrestaShop : la commande (statut, mode de paiement) et ses paiements ──
    $presta = presta_get($o->fk_presta);
    if ($presta === null) {
        $row['action_facture'] = 'erreur';
        $row['note'] = 'connexion PrestaShop '.$o->fk_presta.' introuvable';
        $stats['erreurs']++;
        $errors[] = $o->ref.' : '.$row['note'];
        unset($row);
        continue;
    }
    $psOrder = new PrestaOrder($presta);
    if (!$psOrder->fetch((int) $o->fk_order_presta, false)) {
        $row['action_facture'] = 'erreur';
        $row['note'] = 'commande PrestaShop '.$o->fk_order_presta.' injoignable : '.implode(' ; ', (array) $psOrder->getErrors());
        $stats['erreurs']++;
        $errors[] = $o->ref.' : '.$row['note'];
        unset($row);
        continue;
    }
    $row['ps'] = $psOrder->reference.' ('.(int) $o->fk_order_presta.')';
    $psState = (int) $psOrder->current_state;
    $row['etat_ps'] = $psOrder->getPrestaOrderStatusBadge($psState, true);
    $row['etat_ps'] = is_string($row['etat_ps']) ? trim(strip_tags($row['etat_ps'])) : (string) $psState;

    if (in_array($psState, PS_STATES_NO_INVOICE, true)) {
        $row['action_facture'] = 'ignorée';
        $row['note'] = 'statut PrestaShop bloquant ('.$row['etat_ps'].') : à traiter à la main';
        $stats['ignorees']++;
        unset($row);
        continue;
    }

    // Mapping du mode de paiement : mode, compte, et « crée-t-on le règlement ? ». Lecture
    // seule (pas d'autoCreateMapping, qui écrirait en simulation).
    $mapping = new MappingPayment($db);
    $hasMapping = (bool) $mapping->searchPaiementMapping($presta, (string) $psOrder->payment, true);
    $createPayment = $hasMapping && !empty($mapping->flag_create_payment);
    // Mode de règlement : celui du mapping, sinon celui de la connexion (comme autoCreateMapping).
    $modeId = $hasMapping && !empty($mapping->fk_mode_reglement_id) ? (int) $mapping->fk_mode_reglement_id : (int) $presta->fk_mode_reglement_id;
    $bankId = $hasMapping && !empty($mapping->fk_bank_account) ? (int) $mapping->fk_bank_account : getDolGlobalInt('PRESTASYNC_INVOICE_BANK_ACCOUNT');

    $psPayments = $psOrder->getPrestaOrderPayments();
    $psPaid = 0.0;
    foreach ($psPayments as $pp) {
        $psPaid += round((float) $pp->amount * (float) ($pp->conversion_rate ?: 1), 3);
    }

    // ── La facture ─────────────────────────────────────────────────────────
    $invoices = order_invoices($db, (int) $o->rowid);
    $invoice  = null;

    if (count($invoices) > 1) {
        $row['facture'] = implode(', ', array_column($invoices, 'ref'));
        $row['action_facture'] = 'ignorée';
        $row['note'] = 'plusieurs factures sur la commande : à vérifier à la main';
        $stats['ignorees']++;
        unset($row);
        continue;
    }

    if ($confirm) {
        $db->begin();
    }
    $failed        = false;
    $orderPayCount = 0;
    $orderPayTtc   = 0.0;

    try {
        if (count($invoices) === 1) {
            $inv = $invoices[0];
            $row['facture'] = $inv->ref;
            if ((int) $inv->fk_statut === Facture::STATUS_DRAFT) {
                $row['action_facture'] = 'ignorée';
                $row['note'] = 'facture en brouillon : à valider à la main';
                $stats['ignorees']++;
                throw new SkipOrderException('skip');
            }
            $row['action_facture'] = 'existante';
            $invoice = new Facture($db);
            if ($invoice->fetch((int) $inv->rowid) <= 0) {
                throw new Exception('facture '.$inv->ref.' illisible');
            }
            $invoice->fetch_thirdparty();
        } else {
            // La facture reprend les lignes de la commande Dolibarr : si son total n'est pas ce que
            // le client a payé sur le site (commande reprise d'ADD au prix public, remise du site
            // absente…), on ne facture pas un montant faux — listé, à corriger sur la commande.
            $psTotal = round((float) $psOrder->total_paid_tax_incl, 2);
            if ($psTotal > 0 && abs($psTotal - (float) $o->total_ttc) > 0.02) {
                $row['action_facture'] = 'ignorée';
                $row['note'] = 'total commande '.fmt_amount((float) $o->total_ttc).' ≠ PrestaShop '.fmt_amount($psTotal).' : à corriger avant de facturer';
                $stats['ignorees']++;
                throw new SkipOrderException('skip');
            }
            $row['action_facture'] = $confirm ? 'créée' : 'à créer';
            $stats['factures']++;
            if ($confirm) {
                $commande = new Commande($db);
                if ($commande->fetch((int) $o->rowid) <= 0) {
                    throw new Exception('commande illisible');
                }
                $commande->fetch_thirdparty();

                $facture = new Facture($db);
                if ($facture->createFromOrder($commande, $user) <= 0) {
                    throw new Exception('création de la facture : '.$facture->error.' '.implode(' ', $facture->errors));
                }
                $account = !empty($commande->fk_account) ? (int) $commande->fk_account : $bankId;
                if ($account > 0) {
                    $facture->setBankAccount($account);
                }
                // Une commande reprise d'ADD n'a pas de mode de règlement : celui du mapping.
                if (empty($commande->mode_reglement_id) && $modeId > 0) {
                    $facture->setPaymentMethods($modeId);
                }
                // Rechargement : l'entité et les lignes manquent après createFromOrder (même
                // précaution que Prestasync).
                $invoice = new Facture($db);
                if ($invoice->fetch((int) $facture->id) <= 0) {
                    throw new Exception('facture créée illisible');
                }
                if ($invoice->validate($user, '', getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE', 0)) <= 0) {
                    throw new Exception('validation de la facture : '.$invoice->error.' '.implode(' ', $invoice->errors));
                }
                $commande->classifyBilled($user);
                // fetch_thirdparty + rechargement après validation : ref définitive, reste à payer.
                $invoice = new Facture($db);
                $invoice->fetch((int) $facture->id);
                $invoice->fetch_thirdparty();
                $row['facture'] = $invoice->ref;
            }
        }

        // ── Les règlements ─────────────────────────────────────────────────
        // En simulation d'une facture à créer, on raisonne sur le montant de la commande.
        $invoiceTtc   = ($invoice !== null) ? (float) $invoice->total_ttc : (float) $o->total_ttc;
        $existing     = ($invoice !== null) ? invoice_payments($db, (int) $invoice->id) : array('total' => 0.0, 'psids' => array(), 'nums' => array());
        $remain       = round($invoiceTtc - $existing['total'], 2);
        $alreadyPaid  = ($invoice !== null && !empty($invoice->paye)) || $remain <= EPSILON;

        if ($alreadyPaid) {
            $row['action_reglement'] = 'soldée';
        } elseif (empty($psPayments)) {
            $row['action_reglement'] = 'aucun paiement PrestaShop';
            $row['note'] = trim($row['note'].' ; règlement attendu '.fmt_amount($remain).' — à pointer à la main', ' ;');
            $stats['attente_manuel']++;
        } elseif (!$createPayment) {
            $row['action_reglement'] = 'à pointer à la main';
            $row['note'] = trim($row['note'].' ; mode « '.$psOrder->payment.' » sans création automatique, PrestaShop a '.fmt_amount($psPaid), ' ;');
            $stats['attente_manuel']++;
        } else {
            $toCreate = array();
            foreach ($psPayments as $pp) {
                if (in_array((int) $pp->id, $existing['psids'], true)
                    || ((string) $pp->transaction_id !== '' && in_array((string) $pp->transaction_id, $existing['nums'], true))) {
                    continue;   // déjà importé par Prestasync
                }
                $toCreate[] = $pp;
            }
            if (empty($toCreate)) {
                $row['action_reglement'] = 'déjà importés';
                $row['note'] = trim($row['note'].' ; paiements PrestaShop déjà en base mais facture non soldée (reste '.fmt_amount($remain).')', ' ;');
                $stats['ecarts']++;
            } else {
                $labels = array();
                foreach ($toCreate as $pp) {
                    if ($remain <= EPSILON) {
                        break;
                    }
                    $amount = round((float) $pp->amount * (float) ($pp->conversion_rate ?: 1), 2);
                    if ($amount > $remain + EPSILON) {
                        $row['note'] = trim($row['note'].' ; paiement PrestaShop '.fmt_amount($amount).' plafonné au reste à payer '.fmt_amount($remain), ' ;');
                        $stats['ecarts']++;
                        $amount = $remain;
                    }
                    $labels[] = fmt_amount($amount).' '.$pp->payment_method.' du '.dol_print_date($pp->date_add, 'day');
                    $stats['reglements']++;
                    $stats['reglements_ttc'] += $amount;
                    $orderPayCount++;
                    $orderPayTtc += $amount;
                    $remain = round($remain - $amount, 2);

                    if (!$confirm) {
                        continue;
                    }
                    $paiement = new Paiement($db);
                    $paiement->fk_account  = ($invoice->fk_account > 0) ? (int) $invoice->fk_account : $bankId;
                    $paiement->datepaye    = $pp->date_add;
                    $paiement->num_payment = (string) $pp->transaction_id;
                    $paiement->paiementid  = $modeId > 0 ? $modeId : (int) $invoice->mode_reglement_id;
                    $code = $db->getRow('SELECT code FROM '.MAIN_DB_PREFIX.'c_paiement WHERE id = '.((int) $paiement->paiementid));
                    if ($code) {
                        $paiement->paiementcode = $code->code;
                    }
                    // La même note que Prestasync : c'est elle qui rend le passage rejouable.
                    $paiement->note_private  = 'ID : '.(int) $pp->id."\n\r";
                    $paiement->note_private .= 'Order : '.$pp->order_reference."\n\r";
                    $paiement->note_private .= 'amount : '.(float) $pp->amount."\n\r";
                    $paiement->note_private .= 'payment_method : '.$pp->payment_method."\n\r";
                    $paiement->note_private .= 'date_add : '.dol_print_date($pp->date_add)."\n\r";
                    $paiement->note_private .= 'rattrapage : invoice_web_orders.php '.dol_print_date(dol_now(), 'dayhour')."\n\r";
                    $paiement->amounts = array($invoice->id => $amount);

                    $paymentId = $paiement->create($user, 1, $invoice->thirdparty);
                    if ($paymentId <= 0) {
                        throw new Exception('règlement : '.$paiement->error.' '.implode(' ', $paiement->errors));
                    }
                    if ($paiement->fk_account > 0
                        && $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $paiement->fk_account, '', '') <= 0) {
                        throw new Exception('écriture en banque : '.$paiement->error.' '.implode(' ', $paiement->errors));
                    }
                }
                $row['reglements'] = implode(' + ', $labels);
                $row['action_reglement'] = $confirm ? 'créés' : 'à créer';
                if ($remain > EPSILON) {
                    $row['note'] = trim($row['note'].' ; reste '.fmt_amount($remain).' après les paiements PrestaShop', ' ;');
                    $stats['ecarts']++;
                }
            }
        }

        // PDF de la facture créée, une fois les règlements posés (le document les mentionne).
        if ($confirm && $row['action_facture'] === 'créée') {
            $invoice = new Facture($db);
            $invoice->fetch((int) $facture->id);
            $invoice->fetch_thirdparty();
            $invoice->generateDocument(getDolGlobalString('FACTURE_ADDON_PDF'), $langs);
        }

        if ($confirm) {
            $db->commit();
        }
    } catch (SkipOrderException $e) {
        // Cas connu, listé : rien à écrire.
        if ($confirm) {
            $db->rollback();
        }
        unset($row);
        continue;
    } catch (Throwable $e) {
        // Exception du cœur ou erreur PHP : la commande est annulée entière, le passage continue.
        if ($confirm) {
            $db->rollback();
        }
        $failed = true;
        // Ce qui avait été compté pour cette commande ne s'est pas produit.
        if ($row['action_facture'] === 'créée') {
            $stats['factures']--;
        }
        $stats['reglements']     -= $orderPayCount;
        $stats['reglements_ttc'] -= $orderPayTtc;
        $row['action_facture'] = ($row['action_facture'] === 'créée') ? 'ÉCHEC' : $row['action_facture'];
        $row['action_reglement'] = 'ÉCHEC';
        $row['note'] = $e->getMessage();
        $stats['erreurs']++;
        $errors[] = $o->ref.' : '.$e->getMessage();
    }

    if (!$failed && $row['action_facture'] === 'existante' && in_array($row['action_reglement'], array('soldée'), true)) {
        $stats['completes']++;
    }
    if (!$failed && (in_array($row['action_facture'], array('créée', 'à créer'), true) || in_array($row['action_reglement'], array('créés', 'à créer'), true))) {
        $done++;
        if ($limit > 0 && $done >= $limit) {
            echo "Limite atteinte (".$limit.").\n";
            unset($row);
            break;
        }
    }
    unset($row);
}


/*
 * Rapport
 */

printf("%-16s %-22s %-24s %-16s %-10s %-14s %s\n", 'Commande', 'PrestaShop', 'État PS', 'Facture', 'Action', 'Règlement', 'Détail / note');
foreach ($rows as $r) {
    if ($r['action_facture'] === 'existante' && $r['action_reglement'] === 'soldée') {
        continue;   // complète : rien à dire
    }
    printf("%-16s %-22s %-24s %-16s %-10s %-14s %s\n", $r['commande'], $r['ps'], dol_trunc($r['etat_ps'], 22),
        $r['facture'], $r['action_facture'], $r['action_reglement'],
        trim($r['reglements'].($r['note'] !== '' ? ' — '.$r['note'] : '')));
}

echo str_repeat('-', 60)."\n";
printf("Commandes du site examinées   : %d\n", $stats['commandes']);
printf("Déjà complètes (facturées, soldées) : %d\n", $stats['completes']);
printf("Factures %s : %d\n", $confirm ? 'créées        ' : 'à créer       ', $stats['factures']);
printf("Règlements %s : %d (%s)\n", $confirm ? 'créés       ' : 'à créer     ', $stats['reglements'], fmt_amount($stats['reglements_ttc']));
printf("Règlements à pointer à la main : %d\n", $stats['attente_manuel']);
printf("Commandes ignorées (listées)   : %d\n", $stats['ignorees']);
printf("Écarts de montant signalés     : %d\n", $stats['ecarts']);
printf("Erreurs                        : %d\n", $stats['erreurs']);
foreach ($errors as $e) {
    echo "  - ".$e."\n";
}

if ($csvPath !== '') {
    $fh = @fopen($csvPath, 'w');
    if (!$fh) {
        echo "\nFichier inaccessible en écriture : ".$csvPath."\n";
    } else {
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array('Commande', 'PrestaShop', 'État PrestaShop', 'Facture', 'Action facture', 'Règlements', 'Action règlement', 'Note'), ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, array($r['commande'], $r['ps'], $r['etat_ps'], $r['facture'], $r['action_facture'], $r['reglements'], $r['action_reglement'], $r['note']), ';', '"', '\\');
        }
        fclose($fh);
        echo "\nFichier écrit : ".$csvPath."\n";
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit($stats['erreurs'] > 0 ? 1 : 0);
