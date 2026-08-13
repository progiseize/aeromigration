<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/fix_invoice_signs.php
 * \ingroup aeromigration
 * \brief   Répare les lignes de facture dont la quantité ET le prix étaient négatifs.
 *
 * ------------------------------------------------------------------------------
 * SCRIPT PONCTUEL, À NE LANCER QU'UNE FOIS
 * ------------------------------------------------------------------------------
 *
 * `Facture::addline()` écrit un total de ligne NÉGATIF là où deux facteurs négatifs donnent un
 * produit positif. Isolé sur une facture jetable, les trois combinaisons :
 *
 *     qty<0 et prix<0 ... attendu +96, obtenu -96   ← le seul cas faux
 *     qty>0 et prix<0 ... attendu -96, obtenu -96
 *     qty<0 et prix>0 ... attendu -96, obtenu -96
 *
 * Le calcul du coeur est pourtant juste : `calcul_price_total(-1, -96)` renvoie bien +96. Le
 * signe se perd entre cet appel et l'écriture de la ligne.
 *
 * `MigrationInvoice` inverse désormais les deux facteurs avant d'appeler `addline()`, ce qui
 * laisse leur produit inchangé et donne le bon total. Ce script répare les factures reprises
 * AVANT cette correction — au-delà, il n'a plus rien à faire et le dit.
 *
 * Ce que la source y met : 22 lignes sur l'ensemble du gisement, toutes des avoirs imputés à
 * une facture. « Avoir de 96,00 euros », quantité -1, prix -96 — retirer un avoir revient à
 * ajouter 96 € au montant dû. La source elle-même laisse `DL_MontantHT` à zéro sur ces lignes.
 *
 * ## Pourquoi corriger en place plutôt que supprimer et recréer
 *
 * `is_erasable()` refuse de supprimer une facture qui n'est pas la dernière de sa séquence
 * (commoninvoice.class.php:871). Sur seize documents au milieu de soixante mille, la
 * suppression est donc impossible sans emporter tout ce qui suit.
 *
 * La correction en place passe par le retour au brouillon, seul état où `updateline()` accepte
 * de travailler. **La référence y survit** : `setDraft()` ne touche que `fk_statut`, et
 * `validate()` ne renumérote que si la référence commence par « PROV » (facture.class.php:3581).
 * Vérifié de bout en bout avant d'écrire ce script.
 *
 * L'état payé est relevé avant, restauré après : une facture close doit être rouverte pour
 * repasser en brouillon, et rien ne doit rester ouvert qui ne l'était pas.
 *
 * Usage :
 *   php fix_invoice_signs.php              dénombre, sans rien modifier
 *   php fix_invoice_signs.php --confirm    applique
 *   php fix_invoice_signs.php --user=LOGIN utilisateur au nom duquel écrire
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
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--user=LOGIN]\n";
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
 * Repérage
 *
 * Une ligne fautive se reconnaît en cible, sans interroger la source : quantité négative,
 * prix unitaire négatif, et un total de ligne négatif alors qu'il devrait être positif.
 * Le total est justement le symptôme — une ligne déjà corrigée porte une quantité positive
 * et ne remonte donc pas.
 */

$sql  = 'SELECT DISTINCT f.rowid, f.ref, f.total_ht, f.fk_statut, f.paye';
$sql .= ' FROM '.MAIN_DB_PREFIX.'facture as f';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facturedet as fd ON fd.fk_facture = f.rowid';
$sql .= ' WHERE f.entity IN ('.getEntity('facture').')';
$sql .= "   AND f.ref_ext LIKE 'SAGE:%'";
$sql .= '   AND fd.qty < 0 AND fd.subprice < 0';
$sql .= ' ORDER BY f.rowid';

$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

$targets = array();
while ($obj = $db->fetch_object($resql)) {
    $targets[] = $obj;
}
$db->free($resql);

echo "Script    : correction des lignes à quantité ET prix négatifs\n";
echo "Utilisateur : ".$user->login."\n";
echo "Concernées : ".count($targets)." facture(s)\n\n";

if (empty($targets)) {
    echo "Rien à corriger.\n";
    $db->close();
    exit(0);
}

if (!$confirm) {
    echo "Aperçu (20 premières) :\n";
    foreach (array_slice($targets, 0, 20) as $t) {
        printf("  %-18s total actuel %12s\n", $t->ref, price($t->total_ht));
    }
    echo "\nSimulation : aucune modification effectuée.\n";
    echo "Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}


/*
 * Correction
 */

$fixed      = 0;
$failed     = 0;
$linesFixed = 0;
$errors     = array();

foreach ($targets as $t) {
    $invoice = new Facture($db);
    if ($invoice->fetch((int) $t->rowid) <= 0) {
        $failed++;
        $errors[] = $t->ref.' : chargement impossible';
        continue;
    }

    $wasPaid   = !empty($invoice->paye);
    $wasClosed = ((int) $invoice->statut === Facture::STATUS_CLOSED);
    $refBefore = $invoice->ref;

    $db->begin();

    try {
        // Une facture close se rouvre avant tout : le retour au brouillon la laisserait
        // sinon dans un état que Dolibarr n'attend pas.
        if ($wasClosed || $wasPaid) {
            $invoice->setUnpaid($user);
            $invoice->fetch((int) $t->rowid);
        }

        if ((int) $invoice->statut !== Facture::STATUS_DRAFT) {
            if ($invoice->setDraft($user) < 0) {
                throw new Exception('retour au brouillon refusé — '.$invoice->error);
            }
            $invoice->fetch((int) $t->rowid);
        }

        $touched = 0;
        foreach ($invoice->lines as $line) {
            if ($line->qty >= 0 || $line->subprice >= 0) {
                continue;
            }

            // Inverser les DEUX facteurs laisse leur produit inchangé, et Dolibarr calcule
            // alors juste. La ligne gagne au passage en lisibilité.
            $result = $invoice->updateline(
                $line->id,
                $line->desc,
                -$line->subprice,
                -$line->qty,
                $line->remise_percent,
                $line->date_start,
                $line->date_end,
                $line->tva_tx,
                $line->localtax1_tx,
                $line->localtax2_tx,
                'HT',
                $line->info_bits,
                $line->product_type
            );

            if ($result <= 0) {
                throw new Exception('ligne '.$line->id.' refusée — '.$invoice->error);
            }
            $touched++;
        }

        if ($touched === 0) {
            throw new Exception('aucune ligne à corriger, alors qu\'elle était signalée');
        }

        $invoice->fetch((int) $t->rowid);

        // La référence survit à la revalidation : validate() ne renumérote que si elle
        // commence par « PROV ». Contrôlé après coup, par acquit de conscience.
        if ($invoice->validate($user) <= 0) {
            throw new Exception('revalidation refusée — '.$invoice->error);
        }
        $invoice->fetch((int) $t->rowid);

        if ($invoice->ref !== $refBefore) {
            throw new Exception('référence modifiée : '.$refBefore.' devenue '.$invoice->ref);
        }

        // L'état payé est restauré tel qu'il était : rien ne doit rester ouvert qui ne
        // l'était pas avant le passage.
        if ($wasPaid || $wasClosed) {
            $invoice->setPaid($user);
        }

        $db->commit();
        $fixed++;
        $linesFixed += $touched;

        if ($fixed % 20 === 0) {
            echo "  ".$fixed."/".count($targets)." corrigée(s)\n";
        }
    } catch (Exception $e) {
        $db->rollback();
        $failed++;
        $errors[] = $t->ref.' : '.$e->getMessage();
    }
}

echo "\nCorrigées : ".$fixed." facture(s), ".$linesFixed." ligne(s)\n";
echo "En échec  : ".$failed."\n";

if (!empty($errors)) {
    echo "\nDétail (20 premiers) :\n";
    foreach (array_slice($errors, 0, 20) as $err) {
        echo "  ".$err."\n";
    }
}

$db->close();
exit($failed > 0 ? 1 : 0);
