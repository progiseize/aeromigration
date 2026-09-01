<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/merge_price_levels.php
 * \ingroup aeromigration
 * \brief   Bascule la grille tarifaire de huit à sept niveaux : fusion comptoir/site.
 *
 * ------------------------------------------------------------------------------
 * LA BASCULE, EN UNE FOIS — LES REPRISES FONT LE RESTE
 * ------------------------------------------------------------------------------
 *
 * Décision client (demande du 21/08/2026, chiffrage remis le 30/08) : le prix comptoir
 * n'existe plus — la catégorie 1 (comptoir) fusionne dans le tarif du site, et les six
 * catégories suivantes descendent d'un cran. La caisse vendait DÉJÀ au tarif par défaut
 * (client générique TakePOS sans niveau, repli niveau 1) et la boutique publie
 * `llx_product.price` : la fusion entérine l'usage. Les 5 005 dérogations comptoir
 * abandonnées sont chiffrées dans `rapports/derogations_comptoir_20260830.csv` — voir
 * T9 dans ANOMALIES.md.
 *
 * Ce script fait UNIQUEMENT ce que les scripts de reprise ne peuvent pas faire :
 *
 *   1. les constantes : `PRODUIT_MULTIPRICES_LIMIT` 8 → 7, libellés décalés d'un cran
 *      (LABEL2 reçoit « Aéro-Clubs »…), LABEL8 supprimé. Tous les écrans lisent ces
 *      constantes dynamiquement : aucun code d'affichage à toucher ;
 *   2. les tiers HORS reprise (nés dans la boutique, sans ligne dans `f_comptet`) : leur
 *      niveau descend d'un cran — `migrate.php pricelevel` ne visite que les `SAGE:%` ;
 *   3. les lignes de prix du niveau 8, devenues illisibles (`Product::fetch()` ne lit
 *      jamais au-delà de la limite) : supprimées, avec leurs prix par quantité éventuels.
 *
 * Le reste est porté par la correspondance partagée (aeromigration_price_level()) et se
 * rejoue par les scripts ordinaires, D'AFFILÉE et dans cet ordre :
 *
 *   php scripts/merge_price_levels.php --confirm       la présente bascule
 *   php scripts/migrate.php pricelevel --confirm       les tiers repris (comptoir → 1…)
 *   php scripts/migrate.php customerprice --confirm    la grille des prix (niveaux 2 à 7)
 *
 * ## Pourquoi il n'y a PAS de purge des prix
 *
 * Le niveau 1 ne change pas d'un centime : `customerprice` est idempotent par comparaison
 * de valeurs et réécrit les seuls niveaux 2 à 7, en place. `llx_product.price` — le prix
 * que publie Prestasync — ne bouge donc jamais : pas de fenêtre à zéro euro pour la
 * boutique. La seule fenêtre sensible est celle où les clients à catégorie et la grille ne
 * se correspondent pas encore, entre `pricelevel` et la fin de `customerprice` : enchaîner
 * les trois scripts, hors heures d'activité de la caisse.
 *
 * ## SQL direct, et c'est assumé
 *
 * La suppression des lignes du niveau 8 est en SQL direct : le coeur n'offre aucune
 * méthode pour retirer un niveau entier, et ces lignes ne sont plus lisibles par personne
 * dès que la limite vaut 7. C'est une remise en cohérence d'environnement — même statut
 * que la purge de `customerprice`. Périmètre : les seuls produits repris (`SAGE:%`) ; les
 * lignes de niveau 8 de produits nés dans la boutique, s'il en existe, sont dénombrées et
 * laissées — leur prix ne nous appartient pas.
 *
 * ## Rejouable, mais pas re-décalable
 *
 * Le décalage des libellés et des tiers hors reprise n'a de sens qu'UNE fois : il n'est
 * exécuté que si la limite vaut encore 8. Une fois la bascule faite (limite à 7), un
 * nouveau passage ne décale plus rien — il se borne à vérifier et nettoyer d'éventuelles
 * lignes de niveau 8 restantes.
 *
 * Usage :
 *   php merge_price_levels.php             simulation : état des lieux, rien d'écrit
 *   php merge_price_levels.php --confirm   applique
 *   php merge_price_levels.php --user=LOGIN
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('admin'));

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** La grille avant et après la bascule. */
const OLD_LIMIT = 8;
const NEW_LIMIT = 7;


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
 * État des lieux
 */

$limit = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');

echo "Script : fusion des niveaux de prix 1 et 2 — grille de ".OLD_LIMIT." à ".NEW_LIMIT." niveaux\n";
echo "Mode   : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo str_repeat('-', 60)."\n";

if ($limit !== OLD_LIMIT && $limit !== NEW_LIMIT) {
    echo "PRODUIT_MULTIPRICES_LIMIT vaut ".$limit." : ni ".OLD_LIMIT." (avant bascule) ni ".NEW_LIMIT." (après).\n";
    echo "État inattendu — rien n'est fait. Vérifiez la configuration du multi-prix.\n";
    exit(1);
}

$alreadyDone = ($limit === NEW_LIMIT);
if ($alreadyDone) {
    echo "PRODUIT_MULTIPRICES_LIMIT vaut déjà ".NEW_LIMIT." : la bascule est appliquée.\n";
    echo "Libellés et tiers laissés en l'état — seul le nettoyage du niveau ".OLD_LIMIT." est vérifié.\n\n";
}


/*
 * 1. Les constantes : limite et libellés.
 */

$labels = array();
if (!$alreadyDone) {
    for ($i = 1; $i <= OLD_LIMIT; $i++) {
        $labels[$i] = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$i);
    }

    echo "1. Constantes\n";
    printf("   PRODUIT_MULTIPRICES_LIMIT : %d -> %d\n", OLD_LIMIT, NEW_LIMIT);
    printf("   niveau 1 : « %s », inchangé — libellé à faire arbitrer au client\n", $labels[1]);
    for ($lvl = 2; $lvl <= NEW_LIMIT; $lvl++) {
        printf("   niveau %d : « %s » -> « %s »\n", $lvl, $labels[$lvl], $labels[$lvl + 1]);
    }
    printf("   niveau %d : « %s » -> supprimé\n\n", OLD_LIMIT, $labels[OLD_LIMIT]);
}


/*
 * 2. Les tiers hors reprise : nés dans la boutique, invisibles de « pricelevel ».
 */

$tierRows = array();
$moves    = array();
if (!$alreadyDone) {
    $sql  = 'SELECT rowid, price_level FROM '.MAIN_DB_PREFIX.'societe';
    $sql .= ' WHERE entity IN ('.getEntity('societe').')';
    $sql .= " AND (ref_ext IS NULL OR ref_ext NOT LIKE '".$db->escape(REF_EXT_PREFIX)."%')";
    $sql .= ' AND price_level >= 2';

    $resql = $db->query($sql);
    if (!$resql) {
        echo "Lecture des tiers impossible : ".$db->lasterror()."\n";
        exit(1);
    }
    while ($obj = $db->fetch_object($resql)) {
        $tierRows[] = $obj;
        $move = (int) $obj->price_level.' -> '.((int) $obj->price_level - 1);
        $moves[$move] = (isset($moves[$move]) ? $moves[$move] : 0) + 1;
    }
    $db->free($resql);

    echo "2. Tiers hors reprise à décaler d'un cran : ".count($tierRows)."\n";
    if (empty($tierRows)) {
        echo "   aucun\n";
    } else {
        ksort($moves);
        foreach ($moves as $move => $nb) {
            printf("   niveau %s : %d tiers\n", $move, $nb);
        }
    }
    echo "\n";
}


/*
 * 3. Les lignes de prix au-delà de la nouvelle limite.
 */

$scope = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product'
    .' WHERE entity IN ('.getEntity('product').')'
    ." AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'";

$sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product_price';
$sql  .= ' WHERE price_level > '.NEW_LIMIT.' AND fk_product IN ('.$scope.')';
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des lignes de prix impossible : ".$db->lasterror()."\n";
    exit(1);
}
$obj = $db->fetch_object($resql);
$db->free($resql);
$linesToDelete = (int) $obj->nb;

$sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product_price';
$sql  .= ' WHERE price_level > '.NEW_LIMIT.' AND fk_product NOT IN ('.$scope.')';
$resql = $db->query($sql);
$obj   = $resql ? $db->fetch_object($resql) : null;
$linesOutside = $obj ? (int) $obj->nb : 0;
if ($resql) {
    $db->free($resql);
}

echo "3. Lignes de prix au-delà du niveau ".NEW_LIMIT." (illisibles sous ".NEW_LIMIT." niveaux)\n";
echo "   produits repris   : ".number_format($linesToDelete, 0, ',', ' ')." ligne(s) à supprimer\n";
if ($linesOutside > 0) {
    echo "   produits boutique : ".number_format($linesOutside, 0, ',', ' ')." ligne(s), laissées — leur prix ne nous appartient pas\n";
}
echo "\n";


/*
 * Application
 */

if (!$confirm) {
    echo "SIMULATION terminée, rien n'a été écrit. Relancer avec --confirm pour appliquer.\n";
    exit(0);
}

$db->begin();
$errors  = array();
$shifted = 0;

if (!$alreadyDone) {
    // ── Constantes ─────────────────────────────────────────────────────────
    if (dolibarr_set_const($db, 'PRODUIT_MULTIPRICES_LIMIT', NEW_LIMIT, 'chaine', 0, '', $conf->entity) <= 0) {
        $errors[] = 'PRODUIT_MULTIPRICES_LIMIT : écriture refusée';
    }
    for ($lvl = 2; $lvl <= NEW_LIMIT; $lvl++) {
        if (dolibarr_set_const($db, 'PRODUIT_MULTIPRICES_LABEL'.$lvl, $labels[$lvl + 1], 'chaine', 0, '', $conf->entity) <= 0) {
            $errors[] = 'PRODUIT_MULTIPRICES_LABEL'.$lvl.' : écriture refusée';
        }
    }
    if (dolibarr_del_const($db, 'PRODUIT_MULTIPRICES_LABEL'.OLD_LIMIT, $conf->entity) <= 0) {
        $errors[] = 'PRODUIT_MULTIPRICES_LABEL'.OLD_LIMIT.' : suppression refusée';
    }

    // ── Tiers hors reprise ─────────────────────────────────────────────────
    // setPriceLevel() n'a besoin que de l'id, et journalise le mouvement dans
    // llx_societe_prices — même voie que « pricelevel » pour les tiers repris.
    $societe = new Societe($db);
    foreach ($tierRows as $t) {
        $societe->id = (int) $t->rowid;
        if ($societe->setPriceLevel((int) $t->price_level - 1, $user) < 0) {
            $errors[] = 'tiers rowid '.$t->rowid.' : échec du positionnement du niveau';
            continue;
        }
        $shifted++;
    }
}

// ── Lignes au-delà de la nouvelle limite ──────────────────────────────────
$deletedLines = 0;
if ($linesToDelete > 0 && empty($errors)) {
    // Les prix par quantité pendent aux lignes d'historique : les laisser produirait
    // des orphelins que plus rien ne rattache — même précaution que la purge.
    $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'product_price_by_qty WHERE fk_product_price IN ('
        .'SELECT rowid FROM '.MAIN_DB_PREFIX.'product_price'
        .' WHERE price_level > '.NEW_LIMIT.' AND fk_product IN ('.$scope.'))';
    if (!$db->query($sql)) {
        $errors[] = 'prix par quantité du niveau '.OLD_LIMIT.' : '.$db->lasterror();
    } else {
        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'product_price'
            .' WHERE price_level > '.NEW_LIMIT.' AND fk_product IN ('.$scope.')';
        if (!$db->query($sql)) {
            $errors[] = 'lignes de prix du niveau '.OLD_LIMIT.' : '.$db->lasterror();
        } else {
            $deletedLines = $linesToDelete;
        }
    }
}

if (!empty($errors)) {
    $db->rollback();
    echo "ÉCHEC — tout est annulé (transaction) :\n";
    foreach ($errors as $e) {
        echo "  - ".$e."\n";
    }
    exit(1);
}

$db->commit();

echo "Bascule appliquée.\n";
if (!$alreadyDone) {
    echo "  constantes posées (limite ".NEW_LIMIT.", libellés décalés, LABEL".OLD_LIMIT." supprimé)\n";
    echo "  tiers hors reprise décalés : ".$shifted."\n";
}
echo "  lignes de niveau > ".NEW_LIMIT." supprimées : ".number_format($deletedLines, 0, ',', ' ')."\n";
if (!$alreadyDone) {
    echo "\nÉtapes suivantes, D'AFFILÉE — la grille et les clients doivent se recorrespondre :\n";
    echo "  php scripts/migrate.php pricelevel --confirm\n";
    echo "  php scripts/migrate.php customerprice --confirm\n";
}
