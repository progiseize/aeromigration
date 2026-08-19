<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/fix_invoice_zero_amounts.php
 * \ingroup aeromigration
 * \brief   Répare les factures reprises à 0,00 € alors que la source porte un montant.
 *
 * ## Ce qui s'est passé
 *
 * **Toute l'ère Sage native (numéros `FAC`, oct. 2015 → mars 2019) ne porte aucun prix
 * unitaire HT** : `DL_PrixUnitaire` est NULL sur ses 148 114 lignes, seul `DL_PUTTC` est
 * rempli. La reprise construisait les lignes depuis le HT : les 62 716 factures de l'ère —
 * 6,79 M€ TTC dans ADD — sont donc toutes entrées à 0,00 €, sans une erreur, une ligne à
 * zéro étant parfaitement légale. Découvert le 19/08/2026 ; voir ANOMALIES D1.
 *
 * Depuis la 0.16.1, `mapLine()` reconstruit le prix du TTC et de la TVA de ligne — vérifié
 * au centime contre `DL_MontantHT`. Ce script répare l'existant : il **supprime** les
 * factures reprises dont le total Dolibarr est nul alors que la somme des lignes ADD ne
 * l'est pas, pour que le passage suivant de `migrate.php invoice` les recrée au bon montant.
 *
 * Le périmètre est recalculé à chaque exécution, jamais figé : une facture légitimement à
 * zéro des deux côtés (échange, garantie, lignes qui s'annulent) n'est pas touchée.
 *
 * La suppression passe par le chemin éprouvé d'`align_invoices_add.php` : règlements
 * retirés s'il y en a (il ne devrait pas y en avoir sur des factures à zéro), retour au
 * brouillon, référence `(PROVDEL<rowid>)`, `delete()`, répertoire de documents. Les
 * références Dolibarr changent à la recréation — sans conséquence : la renumérotation
 * finale reposera les numéros définitifs.
 *
 * Usage :
 *   php fix_invoice_zero_amounts.php                 dénombre et détaille, sans rien écrire
 *   php fix_invoice_zero_amounts.php --confirm       supprime ; relancer ensuite migrate.php invoice
 *   php fix_invoice_zero_amounts.php --limit=100 --confirm
 *   php fix_invoice_zero_amounts.php --source-db=NOM
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

const REF_EXT_PREFIX = 'SAGE:';

/** En deçà, un total est tenu pour nul ; au-delà, la source est tenue pour valorisée. */
const ZERO = 0.02;


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';
$limit     = 0;
$sourceDb  = null;

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
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--user=LOGIN] [--source-db=NOM]\n";
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
 * Base source
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

if (!$db->query('SELECT 1 FROM '.src('f_docligne_global').' LIMIT 1', 1)) {
    echo "Table source inaccessible : ".src('f_docligne_global')."\n";
    echo "Précisez --source-db=NOM, ou --source-db= si les tables f_* sont dans la base de Dolibarr.\n";
    exit(1);
}


/*
 * Repérage : factures reprises à zéro dont la source est valorisée.
 */

echo "Lecture de la source...\n";
// Par pièce : la valeur des lignes, et la signature du bug — au moins une ligne dont le
// prix HT est NULL alors que le TTC est rempli. Sans cette signature, un zéro Dolibarr
// est légitime : les ventes magasin réglées par avoir portent une ligne « #AVOIR »
// négative (montant dans le seul PU) qui ramène le net à zéro des deux côtés.
$addValue = array();   // DO_Piece -> array(somme DL_MontantTTC, lignes à PU HT NULL)
$resql = $db->query('SELECT DO_Piece, SUM(DL_MontantTTC) AS s,'
    .' SUM(CASE WHEN DL_PrixUnitaire IS NULL AND ABS(COALESCE(DL_PUTTC, 0)) > 0.005 THEN 1 ELSE 0 END) AS nullpu'
    .' FROM '.src('f_docligne_global')
    .' WHERE DO_Type IN (6, 7) GROUP BY DO_Piece');
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $addValue[$obj->DO_Piece] = array((float) $obj->s, (int) $obj->nullpu);
}
$db->free($resql);

$targets = array();
$value   = 0.0;
$resql = $db->query('SELECT rowid, ref, ref_ext, datef, fk_statut FROM '.MAIN_DB_PREFIX.'facture'
    .' WHERE entity IN ('.getEntity('facture').')'
    ." AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'"
    .' AND ABS(total_ttc) < '.ZERO
    .' ORDER BY rowid');
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $piece = substr($obj->ref_ext, strlen(REF_EXT_PREFIX));
    if (!isset($addValue[$piece]) || abs($addValue[$piece][0]) < ZERO || $addValue[$piece][1] === 0) {
        continue;   // à zéro des deux côtés, sans ligne, ou sans la signature du bug : légitime
    }
    $obj->addTtc = $addValue[$piece][0];
    $targets[] = $obj;
    $value += $addValue[$piece][0];
}
$db->free($resql);

if ($limit > 0 && count($targets) > $limit) {
    $targets = array_slice($targets, 0, $limit);
    $value = 0.0;
    foreach ($targets as $t) {
        $value += $t->addTtc;
    }
}

echo "Script      : réparation des factures reprises à 0,00 € (source valorisée)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
printf("Concernées  : %s facture(s), valeur ADD %s TTC à restituer\n\n",
    number_format(count($targets), 0, ',', ' '), price($value));

if (empty($targets)) {
    echo "Rien à réparer.\n";
    $db->close();
    exit(0);
}

if (!$confirm) {
    echo "Aperçu (15 premières) :\n";
    foreach (array_slice($targets, 0, 15) as $t) {
        printf("  %-16s %s  statut=%s  valeur ADD %10s  %s\n", $t->ref, substr($t->datef, 0, 10),
            $t->fk_statut, price($t->addTtc), substr($t->ref_ext, strlen(REF_EXT_PREFIX)));
    }
    if (count($targets) > 15) {
        echo "  … et ".(count($targets) - 15)." autre(s)\n";
    }
    echo "\nSimulation : aucune suppression effectuée.\n";
    echo "Relancez avec --confirm, puis rejouez « migrate.php invoice » pour recréer au bon montant.\n";
    $db->close();
    exit(0);
}


/*
 * Suppression, par le chemin du brouillon PROV.
 */

$deleted = 0;
$failed  = 0;
$start   = microtime(true);

foreach ($targets as $t) {
    $id = (int) $t->rowid;
    $invoice = new Facture($db);
    if ($invoice->fetch($id) <= 0) {
        $failed++;
        echo "  ÉCHEC ".$t->ref." : chargement impossible\n";
        continue;
    }
    $refBefore = $invoice->ref;

    $db->begin();
    try {
        // Règlement inattendu sur une facture à zéro : retiré s'il n'engage qu'elle.
        $resql = $db->query('SELECT pf.fk_paiement, COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf'
            .' WHERE pf.fk_paiement IN (SELECT fk_paiement FROM '.MAIN_DB_PREFIX.'paiement_facture'
            .' WHERE fk_facture = '.$id.') GROUP BY pf.fk_paiement');
        while ($resql && ($p = $db->fetch_object($resql))) {
            if ((int) $p->nb > 1) {
                throw new Exception('règlement '.$p->fk_paiement.' partagé, dossier laissé en l\'état');
            }
            if ((int) $invoice->statut === Facture::STATUS_CLOSED || !empty($invoice->paye)) {
                $invoice->setUnpaid($user);
                $invoice->fetch($id);
            }
            $payment = new Paiement($db);
            if ($payment->fetch((int) $p->fk_paiement) > 0 && $payment->delete($user) <= 0) {
                throw new Exception('règlement '.$p->fk_paiement.' : suppression refusée');
            }
        }

        if ((int) $invoice->statut !== Facture::STATUS_DRAFT) {
            if ($invoice->setDraft($user) < 0) {
                throw new Exception('retour au brouillon refusé — '.$invoice->error);
            }
            $invoice->fetch($id);
        }
        if ($invoice->setValueFrom('ref', '(PROVDEL'.$id.')', '', null, 'text', '', $user) < 0) {
            throw new Exception('référence temporaire refusée — '.$invoice->error);
        }
        $invoice->fetch($id);
        if ($invoice->delete($user) <= 0) {
            throw new Exception('suppression refusée — '.($invoice->error !== '' ? $invoice->error : 'is_erasable()'));
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        $failed++;
        echo "  ÉCHEC ".$refBefore." : ".$e->getMessage()."\n";
        continue;
    }

    if (!empty($conf->facture->dir_output)) {
        $dir = $conf->facture->dir_output.'/'.dol_sanitizeFileName($refBefore);
        if (is_dir($dir)) {
            dol_delete_dir_recursive($dir);
        }
    }

    $deleted++;
    if ($deleted % 500 === 0) {
        printf("  %s/%s supprimée(s)  (%.0f/min)\n", number_format($deleted, 0, ',', ' '),
            number_format(count($targets), 0, ',', ' '), $deleted / max(1, microtime(true) - $start) * 60);
    }
}

printf("\nSupprimées : %s  En échec : %d  Durée : %.0f s\n",
    number_format($deleted, 0, ',', ' '), $failed, microtime(true) - $start);
echo "Rejouez maintenant « php migrate.php invoice » : les factures seront recréées au bon montant.\n";

$db->close();
exit($failed > 0 ? 1 : 0);
