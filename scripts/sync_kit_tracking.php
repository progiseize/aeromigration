<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/sync_kit_tracking.php
 * \ingroup aeromigration
 * \brief   Aligne le suivi des produits composés sur celui de leurs composants.
 *
 * Le suivi d'un lot n'est plus saisi : il se déduit de ses composants, selon la priorité
 * « Arrêt à épuisement du stock » > « Non suivi » > « Suivi ». La règle s'applique désormais à
 * chaque enregistrement, mais **elle ne touche que les fiches qu'on rouvre** : les lots déjà en base
 * gardent le suivi qu'ils portaient. Ce script les reprend d'un coup.
 *
 * Il fait deux choses. **Le suivi** est repris des composants, selon la priorité ci-dessus. **La
 * disponibilité** est confrontée au stock : un lot qu'on ne peut plus assembler bascule vers la
 * disponibilité de rupture prévue par la configuration, et retrouve la précédente dès qu'un
 * composant revient. Cette seconde passe rattrape ce qu'aucun mouvement de stock n'a déclenché — un
 * lot inassemblable depuis toujours n'a jamais été examiné par personne.
 *
 * Il est rejouable sans risque : un lot déjà conforme n'est pas réécrit, et l'exécution est
 * idempotente.
 *
 * ## Ce qui n'est jamais touché
 *
 * **La disponibilité**, qui reste le choix de l'utilisateur — c'est toute la règle : on décide
 * qu'un lot n'est plus à vendre, on ne décide pas qu'il est en stock.
 *
 * **Les lots dont le couple cible n'existe pas** dans la configuration. Seize d'entre eux sont en
 * « Commercialisation arrêtée » avec des composants suivis : ce couple n'est pas défini, et un
 * article qu'on a cessé de vendre n'a pas à redevenir suivi parce que ses composants le sont. Le
 * script les compte et les nomme, sans rien y changer.
 *
 * L'état n'est pas poussé vers la boutique : sur plusieurs dizaines de lots, autant d'allers-retours
 * HTTP tiendraient l'écran de longues minutes. La réconciliation planifiée d'aeropresta rattrape
 * l'ensemble à sa passe suivante.
 *
 * Usage :
 *   php sync_kit_tracking.php               simule et détaille, sans rien écrire
 *   php sync_kit_tracking.php --confirm     applique
 *   php sync_kit_tracking.php --user=LOGIN  utilisateur au nom duquel tracer les corrections
 *   php sync_kit_tracking.php --quiet       n'affiche que le compte rendu final
 *   php sync_kit_tracking.php --tracking-only  n'aligne que le suivi, laisse la disponibilité
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
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/aerotoolbox/lib/aerotoolbox.lib.php');
dol_include_once('/aerotoolbox/lib/aerokit.lib.php');

$langs->loadLangs(array('admin', 'products'));


/*
 * Arguments
 */

$confirm      = false;
$quiet        = false;
$trackingOnly = false;
$userLogin    = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--tracking-only') {
        $trackingOnly = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--user=LOGIN] [--quiet]\n";
        exit(1);
    }
}

if (!isModEnabled('aerotoolbox')) {
    echo "Le module aerotoolbox n'est pas activé : le suivi des produits composés n'existe pas.\n";
    exit(1);
}


/*
 * Utilisateur, pour tracer les corrections dans l'agenda des produits
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
    $user->fetch($obj->rowid);
}
$user->getrights();


/*
 * Relevé : un passage sur tous les lots, sans rien écrire
 */

echo "Alignement du suivi des produits composés\n";
echo "Mode            : ".($confirm ? "APPLICATION" : "SIMULATION (aucune écriture)")."\n";
echo str_repeat('-', 78)."\n";

$sql  = 'SELECT DISTINCT pa.fk_product_pere AS id FROM '.MAIN_DB_PREFIX.'product_association AS pa';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = pa.fk_product_pere';
$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
$sql .= ' ORDER BY pa.fk_product_pere ASC';

$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des produits composés impossible : ".$db->lasterror()."\n";
    exit(1);
}
$kitIds = array();
while ($o = $db->fetch_object($resql)) {
    $kitIds[] = (int) $o->id;
}
$db->free($resql);

// Libellés des suivis, pour un compte rendu lisible.
$labels = array();
$resql  = $db->query('SELECT rowid, label FROM '.MAIN_DB_PREFIX.'c_aerotoolbox_tracking'
    .' WHERE entity IN ('.getEntity('c_aerotoolbox_tracking').')');
while ($resql && ($o = $db->fetch_object($resql))) {
    $labels[(int) $o->rowid] = $o->label;
}
if ($resql) {
    $db->free($resql);
}
$lbl = function ($id) use ($labels) {
    $id = (int) $id;
    return isset($labels[$id]) ? $labels[$id] : ($id > 0 ? '#'.$id : 'aucun');
};

$conformes = 0;
$aCorriger = array();
$sansCible = array();
$sansCouple = array();

foreach ($kitIds as $kitId) {
    $product = new Product($db);
    if ($product->fetch($kitId) <= 0) {
        continue;
    }
    $product->fetch_optionals();

    $avail = (int) ($product->array_options['options_aerotb_availability'] ?? 0);
    $track = (int) ($product->array_options['options_aerotb_tracking'] ?? 0);

    // Sans disponibilité, il n'y a pas de couple à former : le lot n'a jamais été qualifié.
    if ($avail <= 0) {
        $sansCouple[] = $product->ref;
        continue;
    }

    $derive = aerotb_kit_tracking($db, $kitId, true);
    if ($derive <= 0) {
        $sansCouple[] = $product->ref;
        continue;
    }
    if ($derive === $track) {
        $conformes++;
        continue;
    }
    if (!aerotb_kit_couple_exists($db, $avail, $derive)) {
        $sansCible[] = array('ref' => $product->ref, 'de' => $track, 'vers' => $derive);
        continue;
    }

    $aCorriger[] = array('id' => $kitId, 'ref' => $product->ref, 'avail' => $avail,
        'de' => $track, 'vers' => $derive);
}

/*
 * Compte rendu du relevé
 */

if (!$quiet && $aCorriger) {
    echo "\nÀ corriger (".count($aCorriger)."), suivi actuel → suivi des composants :\n";
    foreach ($aCorriger as $k) {
        printf("  %-14s %-32s → %s\n", $k['ref'], $lbl($k['de']), $lbl($k['vers']));
    }
}

if (!$quiet && $sansCible) {
    echo "\nLaissés en l'état (".count($sansCible).") : le couple n'existe pas dans la configuration.\n";
    echo "  Leur disponibilité, choisie à la main, l'emporte sur le suivi des composants.\n";
    foreach ($sansCible as $k) {
        printf("  %-14s %-32s ⇢ %s (couple absent)\n", $k['ref'], $lbl($k['de']), $lbl($k['vers']));
    }
}

if (!$quiet && $sansCouple) {
    echo "\nHors périmètre (".count($sansCouple).") : aucune disponibilité posée, ou aucun composant qualifié.\n";
    echo '  '.implode(', ', array_slice($sansCouple, 0, 20)).(count($sansCouple) > 20 ? ', …' : '')."\n";
}


/*
 * Application
 */

$corriges = 0;
$erreurs  = array();

if ($confirm && $aCorriger) {
    echo "\n".str_repeat('-', 78)."\n";
    foreach ($aCorriger as $k) {
        // La disponibilité est redonnée telle quelle — elle ne bouge jamais. Le suivi passé ici
        // n'a pas d'importance : la porte d'écriture le remplace par celui des composants. L'état
        // n'est pas poussé vers la boutique, et la cascade est coupée : le script parcourt déjà
        // tous les lots, et il est rejouable si l'un d'eux est lui-même composant d'un autre.
        $res = aerotb_status_write($db, $k['id'], $k['avail'], $k['vers'], $user, false, $info, false);
        if ($res < 0) {
            $erreurs[] = $k['ref'];
            continue;
        }
        $corriges++;
        if (!$quiet) {
            printf("  corrigé  %-14s → %s\n", $k['ref'], $lbl($k['vers']));
        }
    }
}


/*
 * Seconde passe : la disponibilité, confrontée au stock des composants
 */

$dispoGone = array();
$dispoBack = array();

// Libellés des disponibilités, pour un compte rendu lisible.
$availLabels = array();
$resql = $db->query('SELECT rowid, label FROM '.MAIN_DB_PREFIX.'c_aerotoolbox_availability'
    .' WHERE entity IN ('.getEntity('c_aerotoolbox_availability').')');
while ($resql && ($o = $db->fetch_object($resql))) {
    $availLabels[(int) $o->rowid] = $o->label;
}
if ($resql) {
    $db->free($resql);
}
$albl = function ($id) use ($availLabels) {
    $id = (int) $id;
    return isset($availLabels[$id]) ? $availLabels[$id] : ($id > 0 ? '#'.$id : 'aucune');
};

if (!$trackingOnly) {
    foreach ($kitIds as $kitId) {
        $product = new Product($db);
        if ($product->fetch($kitId) <= 0) {
            continue;
        }
        $product->fetch_optionals();

        $avail   = (int) ($product->array_options['options_aerotb_availability'] ?? 0);
        $track   = (int) ($product->array_options['options_aerotb_tracking'] ?? 0);
        $restore = (int) ($product->array_options['options_aerotb_avail_restore'] ?? 0);
        if ($avail <= 0 || $track <= 0) {
            continue;
        }

        $build = aerotb_kit_buildable($db, $kitId, true);
        if ($build === null) {
            continue;
        }

        // Même règle que le traitement au fil de l'eau : l'état des composants d'abord, le stock
        // assemblable ensuite.
        $cible = aerotb_kit_forced_availability($db, $kitId, true);
        if ($cible <= 0 && $build <= 0) {
            $cible = aerotb_kit_rupture_availability($db, ($restore > 0 ? $restore : $avail), $track);
        }

        // La règle ne fait que durcir : un lot déjà plus contraint que ses composants ne l'exigent
        // garde son état, qui vient d'une décision.
        if ($cible > 0 && aerotb_kit_availability_rank($db, $avail) >= aerotb_kit_availability_rank($db, $cible)) {
            continue;
        }

        if ($cible > 0 && $cible !== $avail) {
            $dispoGone[] = array('id' => $kitId, 'ref' => $product->ref, 'de' => $avail, 'vers' => $cible);
        } elseif ($cible <= 0 && $restore > 0 && aerotb_kit_availability_is_forced($db, $avail)) {
            $dispoBack[] = array('id' => $kitId, 'ref' => $product->ref, 'de' => $avail, 'vers' => $restore);
        }
    }

    if (!$quiet && $dispoGone) {
        echo "\nPlus assemblables (".count($dispoGone).") : aucun lot ne peut être constitué.\n";
        foreach ($dispoGone as $k) {
            printf("  %-14s %-32s → %s\n", $k['ref'], $albl($k['de']), $albl($k['vers']));
        }
    }
    if (!$quiet && $dispoBack) {
        echo "\nDe nouveau assemblables (".count($dispoBack).") : disponibilité à restaurer.\n";
        foreach ($dispoBack as $k) {
            printf("  %-14s %-32s → %s\n", $k['ref'], $albl($k['de']), $albl($k['vers']));
        }
    }

    if ($confirm && ($dispoGone || $dispoBack)) {
        echo "\n".str_repeat('-', 78)."\n";
        foreach (array_merge($dispoGone, $dispoBack) as $k) {
            $res = aerotb_kit_availability_refresh($db, $k['id'], $user);
            if ($res < 0) {
                $erreurs[] = $k['ref'];
                continue;
            }
            if (!$quiet && $res > 0) {
                printf("  %-9s %-14s → %s\n", ($res === 2 ? 'restauré' : 'basculé'), $k['ref'], $albl($k['vers']));
            }
        }
    }
}


/*
 * Bilan
 */

echo "\n".str_repeat('-', 78)."\n";
printf("Produits composés   : %d\n", count($kitIds));
printf("Déjà conformes      : %d\n", $conformes);
printf("À corriger          : %d\n", count($aCorriger));
if ($confirm) {
    printf("Corrigés            : %d\n", $corriges);
    if ($erreurs) {
        printf("En erreur           : %d  (%s)\n", count($erreurs), implode(', ', $erreurs));
    }
}
printf("Laissés en l'état   : %d  (couple absent de la configuration)\n", count($sansCible));
printf("Hors périmètre      : %d\n", count($sansCouple));
if (!$trackingOnly) {
    printf("Plus assemblables   : %d\n", count($dispoGone));
    printf("Redevenus assembl.  : %d\n", count($dispoBack));
}

if (!$confirm && ($aCorriger || $dispoGone || $dispoBack)) {
    echo "\nRelancez avec --confirm pour appliquer.\n";
}

$db->close();
