<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/delete_invoices_cancelled_orders.php
 * \ingroup aeromigration
 * \brief   Supprime les factures produites à tort sur des commandes annulées dans l'ancien ERP.
 *
 * ------------------------------------------------------------------------------
 * SCRIPT PONCTUEL — IL SUPPRIME DES FACTURES, LISEZ LE PÉRIMÈTRE AVANT
 *
 * REMPLACÉ depuis la 0.15.0 par `align_invoices_add.php`, qui applique la règle
 * « ADD fait foi » arrêtée par le client le 18/08/2026 — périmètre plus large
 * (les encaissées partent aussi) et recalculé à chaque exécution. Ce script est
 * conservé comme trace de la suppression des 311 ; ne plus l'exécuter.
 * ------------------------------------------------------------------------------
 *
 * ## Ce qui s'est passé
 *
 * En juin 2026, la synchronisation de la boutique a rattrapé tout l'historique PrestaShop et
 * créé 28 474 factures d'un coup, datées de 2023 à 2026. Parmi les commandes ainsi facturées,
 * certaines étaient **annulées dans ADD depuis des mois** — mais l'annulation n'avait jamais
 * été poussée vers PrestaShop, où elles figuraient toujours comme validées. La
 * synchronisation a donc fait son travail sur une donnée fausse en amont : ce n'est pas un
 * défaut du module de liaison.
 *
 * Résultat : des factures qui n'ont aucune contrepartie dans ADD, qui n'ont jamais bougé de
 * stock, jamais reçu de règlement, jamais été envoyées — et qui pèsent pourtant dans le
 * chiffre d'affaires.
 *
 * ## Le périmètre, et pourquoi chaque condition y est
 *
 * Une facture n'est supprimée que si les six conditions sont réunies :
 *
 *   1. elle est rattachée à une commande reprise d'ADD ;
 *   2. cette commande y est marquée annulée (`Z_Annule = 'A'`) ;
 *   3. elle n'a pas de `ref_ext` de reprise — elle est née dans Dolibarr, et n'a donc
 *      aucune contrepartie dans ADD ;
 *   4. elle est validée et **non payée** ;
 *   5. elle ne porte **aucun règlement**, même partiel ;
 *   6. la commande n'a **aucune préparation de livraison** dans ADD (`DO_Type = 2`).
 *
 * La sixième est la plus importante et la moins évidente. Une préparation de livraison
 * signifie que la marchandise a bougé : la vente a probablement eu lieu, et l'annulation dans
 * ADD est alors une écriture administrative. Ces cas sont **épargnés** et laissés à
 * l'arbitrage — une seule facture est dans cette situation, pour 310 €.
 *
 * Les factures encaissées sont épargnées par la condition 4, y compris les quinze qui sont à
 * la fois préparées et payées : celles-là sont des ventes réelles.
 *
 * ## Pourquoi supprimer, et non abandonner
 *
 * Une facture abandonnée conserve son numéro et reste dans le parc. Ici, les pièces n'ont
 * jamais existé pour personne : aucun mouvement de stock (`STOCK_CALCULATE_ON_BILL` vaut 0),
 * aucun règlement, aucun envoi — la base ne compte aucun événement lié à une facture. Les
 * garder reviendrait à traîner des fantômes dans un parc que l'on s'apprête à renuméroter.
 * Supprimer **avant** la renumérotation donne au contraire une série continue.
 *
 * ## Comment la suppression passe, alors que Dolibarr la refuse
 *
 * `is_erasable()` (commoninvoice.class.php:819) n'accepte de supprimer une facture validée que
 * si elle est **la dernière de sa séquence** — sinon elle renvoie -2 pour ne pas trouer la
 * numérotation. Sur des factures de 2023 au milieu de 182 000, c'est rédhibitoire.
 *
 * Mais la même méthode retourne 1 sans autre contrôle quand la facture est un brouillon dont
 * la référence commence par « PROV ». Le script suit donc ce chemin, qui est celui du coeur :
 *
 *   1. `setDraft()` — ne touche que `fk_statut`, la référence y survit ;
 *   2. la référence devient `(PROVDEL<rowid>)`, préfixe distinct des trois `(PROV…)` déjà
 *      présents en base pour ne pas heurter la contrainte unique `uk_facture_ref` ;
 *   3. `delete()` — `is_erasable()` répond 1, la pièce et ses liens partent.
 *
 * Le répertoire de documents est retiré **après**, à partir de la référence d'origine :
 * `delete()` le chercherait sous le nom temporaire et laisserait l'ancien orphelin.
 *
 * Rien ne remonte vers PrestaShop : le trigger `aeropresta` n'écoute que catégories, produits
 * et stock, et celui de `prestasync` que des événements de commande. Vérifié avant d'écrire.
 *
 * Usage :
 *   php delete_invoices_cancelled_orders.php                 dénombre, sans rien supprimer
 *   php delete_invoices_cancelled_orders.php --confirm       applique
 *   php delete_invoices_cancelled_orders.php --limit=20 --confirm    par petits lots
 *   php delete_invoices_cancelled_orders.php --user=LOGIN
 *   php delete_invoices_cancelled_orders.php --source-db=NOM
 *
 * `--source-db=` sans valeur ramène la lecture dans la base de Dolibarr : c'est le cas des
 * hébergements à base unique, où les tables `f_*` de l'ancien ERP cohabitent avec les `llx_*`.
 * Sans l'option, la constante `AEROMIG_SOURCE_DB` fait foi, comme pour les scripts de reprise.
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));

/** Préfixe des ref_ext posés par la reprise — même valeur que AeroMigrationRunner. */
const REF_EXT_PREFIX = 'SAGE:';

/** Type de document ADD valant préparation de livraison : la marchandise a bougé. */
const SRC_TYPE_DELIVERY = 2;


/*
 * Arguments
 */

$confirm    = false;
$userLogin  = '';
$limit      = 0;
$sourceDb   = null;   // null = non précisé en ligne de commande

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
 * Base des tables source
 *
 * Même résolution que les scripts de reprise, pour qu'une instance configurée d'un côté ne
 * demande pas d'être reconfigurée de l'autre. Qualifier une table de la base courante ne sert
 * à rien et casse dès que la base est renommée : ce cas est ramené à « pas de préfixe ».
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

// Contrôle d'accès explicite : sans lui, l'erreur ne viendrait qu'au milieu du repérage, avec
// un message SQL que personne ne relie à un problème de configuration.
if (!$db->query('SELECT 1 FROM '.src('f_docentete_global').' LIMIT 1', 1)) {
    echo "Table source inaccessible : ".src('f_docentete_global')."\n";
    echo "Précisez --source-db=NOM, ou --source-db= si les tables f_* sont dans la base de Dolibarr.\n";
    exit(1);
}


/*
 * Repérage
 */

$prefixLen = strlen(REF_EXT_PREFIX) + 1;

$sql  = 'SELECT f.rowid, f.ref, f.datef, f.total_ttc, SUBSTRING(c.ref_ext, '.$prefixLen.') AS src_order';
$sql .= ' FROM '.MAIN_DB_PREFIX.'facture as f';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'element_element as ee';
$sql .= "       ON ee.fk_target = f.rowid AND ee.sourcetype = 'commande' AND ee.targettype = 'facture'";
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commande as c ON c.rowid = ee.fk_source';
$sql .= ' INNER JOIN '.src('f_docentete_global').' as d';
$sql .= '       ON d.DO_Piece = SUBSTRING(c.ref_ext, '.$prefixLen.')';
$sql .= ' WHERE f.entity IN ('.getEntity('facture').')';
// 2. la commande est annulée dans ADD
$sql .= "   AND TRIM(COALESCE(d.Z_Annule, '')) = 'A'";
// 1. et elle vient bien de la reprise
$sql .= "   AND c.ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'";
// 3. la facture, elle, est née dans Dolibarr : aucune contrepartie dans ADD
$sql .= "   AND (f.ref_ext IS NULL OR f.ref_ext NOT LIKE '".$db->escape(REF_EXT_PREFIX)."%')";
// 4. validée et non payée
$sql .= '   AND f.fk_statut = '.Facture::STATUS_VALIDATED;
$sql .= '   AND f.paye = 0';
// 5. aucun règlement, même partiel
$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'paiement_facture as pf';
$sql .= '                 WHERE pf.fk_facture = f.rowid)';
// 6. aucune préparation de livraison dans ADD : la marchandise n'a pas bougé
$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.src('f_docligne_global').' as l';
$sql .= '                 WHERE l.DL_PieceBC = d.DO_Piece AND l.DO_Type = '.SRC_TYPE_DELIVERY.')';
$sql .= ' GROUP BY f.rowid';
$sql .= ' ORDER BY f.datef, f.rowid';

$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

$targets = array();
$amount  = 0.0;
while ($obj = $db->fetch_object($resql)) {
    $targets[] = $obj;
    $amount   += (float) $obj->total_ttc;
}
$db->free($resql);

if ($limit > 0 && count($targets) > $limit) {
    $targets = array_slice($targets, 0, $limit);
    $amount  = 0.0;
    foreach ($targets as $t) {
        $amount += (float) $t->total_ttc;
    }
}

echo "Script      : suppression des factures émises sur commandes annulées dans ADD\n";
echo "Utilisateur : ".$user->login."\n";
echo "Source      : ".($sourceDb === '' ? $db->database_name.' (base de Dolibarr)' : $sourceDb)."\n";
echo "Concernées  : ".count($targets)." facture(s), ".price($amount)." TTC\n\n";

if (empty($targets)) {
    echo "Rien à supprimer.\n";
    $db->close();
    exit(0);
}

if (!$confirm) {
    echo "Aperçu (20 premières) :\n";
    foreach (array_slice($targets, 0, 20) as $t) {
        printf("  %-16s %s  %12s  commande %s\n",
            $t->ref, dol_print_date($db->jdate($t->datef), 'day'), price($t->total_ttc), $t->src_order);
    }
    if (count($targets) > 20) {
        echo "  … et ".(count($targets) - 20)." autre(s)\n";
    }
    echo "\nSimulation : aucune suppression effectuée.\n";
    echo "Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}


/*
 * Suppression
 */

$deleted   = 0;
$failed    = 0;
$dirsGone  = 0;
$errors    = array();
$outputDir = isset($conf->facture->dir_output) ? $conf->facture->dir_output : '';

foreach ($targets as $t) {
    $id      = (int) $t->rowid;
    $invoice = new Facture($db);

    if ($invoice->fetch($id) <= 0) {
        $failed++;
        $errors[] = $t->ref.' : chargement impossible';
        continue;
    }

    $refBefore = $invoice->ref;

    $db->begin();

    try {
        // 1. Retour au brouillon. Ne touche que fk_statut : la référence y survit, c'est
        //    pourquoi l'étape suivante est nécessaire.
        if ((int) $invoice->statut !== Facture::STATUS_DRAFT) {
            if ($invoice->setDraft($user) < 0) {
                throw new Exception('retour au brouillon refusé — '.$invoice->error);
            }
            $invoice->fetch($id);
        }

        // 2. Référence temporaire. is_erasable() n'accepte une suppression sans condition que
        //    sur un brouillon dont la référence commence par « PROV » ; le suffixe « DEL » et
        //    le rowid garantissent l'unicité exigée par uk_facture_ref.
        $tmpRef = '(PROVDEL'.$id.')';
        if ($invoice->setValueFrom('ref', $tmpRef, '', null, 'text', '', $user) < 0) {
            throw new Exception('référence temporaire refusée — '.$invoice->error);
        }
        $invoice->fetch($id);

        // 3. Suppression. delete() emporte les lignes, les liens de llx_element_element et les
        //    extrafields ; il ne renvoie 0 que si is_erasable() a dit non, d'où le message.
        if ($invoice->delete($user) <= 0) {
            $why = $invoice->error !== '' ? $invoice->error : 'refusée par is_erasable()';
            throw new Exception('suppression refusée — '.$why);
        }

        $db->commit();
        $deleted++;

        // Le répertoire de documents porte la référence d'ORIGINE : delete() l'a cherché sous
        // le nom temporaire et ne l'a donc pas trouvé. Retiré ici, hors transaction — le
        // système de fichiers ne participe pas au rollback, et un dossier resté en place est
        // sans conséquence.
        if ($outputDir !== '') {
            $dir = $outputDir.'/'.dol_sanitizeFileName($refBefore);
            if (is_dir($dir) && dol_delete_dir_recursive($dir) > 0) {
                $dirsGone++;
            }
        }

        if ($deleted % 25 === 0) {
            echo "  ".$deleted."/".count($targets)." supprimée(s)\n";
        }
    } catch (Exception $e) {
        $db->rollback();
        $failed++;
        $errors[] = $refBefore.' : '.$e->getMessage();
    }
}

echo "\nSupprimées   : ".$deleted." facture(s)\n";
echo "Documents    : ".$dirsGone." répertoire(s) retiré(s)\n";
echo "En échec     : ".$failed."\n";

if (!empty($errors)) {
    echo "\nDétail (20 premiers) :\n";
    foreach (array_slice($errors, 0, 20) as $err) {
        echo "  ".$err."\n";
    }
}

$db->close();
exit($failed > 0 ? 1 : 0);
