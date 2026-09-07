<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_declinaisons.php
 * \ingroup aeromigration
 * \brief   Import de masse des déclinaisons PrestaShop en produits Dolibarr, via Prestasync.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CE SCRIPT
 * ------------------------------------------------------------------------------
 *
 * L'action de masse « startSync » de la liste Prestasync traite les produits cochés — mais
 * cocher par erreur un produit SANS déclinaisons le mettrait à jour depuis la boutique
 * (libellé, prix, description : 2 382 désignations divergentes connues). Ici, un produit
 * sans déclinaisons est ÉCARTÉ d'office : seul le chemin « combinaisons » de
 * PrestaProduct::syncToDolibarr() est atteignable, et celui-ci ne touche jamais la fiche
 * du parent (prestaProduct.class.php:420-433).
 *
 * Pour chaque déclinaison, Prestasync crée le produit Dolibarr avec la référence boutique
 * TELLE QUELLE (les réfs ont été normalisées en #XXXXX-NNN le 07/09) et pose le lien
 * (fk_product_presta, fk_product_presta_attribute) dans llx_prestasync_product. Une
 * déclinaison déjà liée est mise à jour depuis la boutique — sur une première passe,
 * tout est création.
 *
 * La liste des parents vient de la base boutique (phpMyAdmin) :
 *     SELECT GROUP_CONCAT(DISTINCT id_product) FROM ps_product_attribute;
 *
 * Usage :
 *   php import_declinaisons.php --ids=16148,43,9462      simulation : état des lieux, rien d'écrit
 *   php import_declinaisons.php --ids=... --confirm      applique
 *   php import_declinaisons.php --file=ids.txt --confirm  (un id par ligne, ou séparés par des virgules)
 *   php import_declinaisons.php --ids=... --user=LOGIN
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
require_once DOL_DOCUMENT_ROOT.'/custom/prestasync/class/presta.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/prestasync/class/prestaProduct.class.php';

$langs->loadLangs(array('admin'));


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';
$idsArg    = '';
$file      = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--ids=(.+)$/', $arg, $m)) {
        $idsArg = $m[1];
    } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." --ids=1,2,3 | --file=ids.txt [--confirm] [--user=LOGIN]\n";
        exit(1);
    }
}

if ($file !== '') {
    if (!is_readable($file)) {
        echo "Fichier illisible : ".$file."\n";
        exit(1);
    }
    $idsArg = file_get_contents($file);
}
$ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $idsArg)))));
if (empty($ids)) {
    echo "Aucun id produit boutique fourni (--ids= ou --file=).\n";
    exit(1);
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
 * La connexion boutique : la ligne rowid 1, active.
 */

$presta = new Presta($db);
if ($presta->fetch(1) <= 0) {
    echo "Connexion Prestasync rowid 1 introuvable.\n";
    exit(1);
}
if (empty($presta->status)) {
    echo "La connexion Prestasync rowid 1 est désactivée : rien ne peut se synchroniser.\n";
    exit(1);
}

/**
 * Liens de déclinaisons déjà posés pour un produit boutique.
 *
 * @param  DoliDB $db Handler base
 * @param  int    $ps Id produit boutique
 * @return int
 */
function decl_links_count($db, $ps)
{
    $r = $db->query('SELECT COUNT(*) as n FROM '.MAIN_DB_PREFIX.'prestasync_product'
        .' WHERE fk_presta = 1 AND fk_product_presta = '.((int) $ps).' AND fk_product_presta_attribute > 0');
    if ($r && ($o = $db->fetch_object($r))) {
        return (int) $o->n;
    }

    return 0;
}

echo "Script      : import de masse des déclinaisons (Prestasync)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Parents     : ".count($ids)."\n";
echo str_repeat('-', 76)."\n";

$stats = array('parents' => 0, 'sans_combinaisons' => 0, 'introuvables' => 0,
    'combinaisons' => 0, 'deja_liees' => 0, 'refs_vides' => 0, 'crees' => 0, 'erreurs' => 0);
$errs = array();

foreach ($ids as $ps) {
    $prestaProduct = new PrestaProduct($presta);
    if (!$prestaProduct->fetch($ps)) {
        $stats['introuvables']++;
        printf("  %-7d INTROUVABLE sur la boutique\n", $ps);
        continue;
    }

    // Garde-fou : un produit sans déclinaisons passerait dans le chemin « produit simple »
    // de syncToDolibarr, qui met à jour la fiche Dolibarr depuis la boutique. Refusé.
    if (!$prestaProduct->useCombinations()) {
        $stats['sans_combinaisons']++;
        printf("  %-7d ÉCARTÉ : pas de déclinaisons (« %s »)\n", $ps, dol_trunc((string) $prestaProduct->getTradValue($prestaProduct->name), 40));
        continue;
    }

    $stats['parents']++;
    if (!$prestaProduct->fetchCombinations(false)) {
        $stats['erreurs']++;
        $errs[] = $ps.' : lecture des combinaisons impossible — '.implode(' | ', (array) $prestaProduct->getErrors());
        continue;
    }

    $nb     = count($prestaProduct->combinations);
    $before = decl_links_count($db, $ps);
    $vides  = 0;
    foreach ($prestaProduct->combinations as $cb) {
        if (trim((string) $cb->reference) === '') {
            $vides++;
        }
    }
    $stats['combinaisons'] += $nb;
    $stats['deja_liees']   += $before;
    $stats['refs_vides']   += $vides;

    if (!$confirm) {
        printf("  %-7d %-40s %2d déclinaison(s), %2d déjà liée(s)%s\n", $ps,
            dol_trunc((string) $prestaProduct->getTradValue($prestaProduct->name), 40), $nb, $before,
            $vides ? ', '.$vides.' RÉF VIDE (échouera)' : '');
        continue;
    }

    $prestaProduct->clearErrors();
    $ok    = $prestaProduct->syncToDolibarr($user);
    $after = decl_links_count($db, $ps);
    $stats['crees'] += max(0, $after - $before);
    if (!$ok) {
        $stats['erreurs']++;
        $errs[] = $ps.' : '.implode(' | ', (array) $prestaProduct->getErrors());
    }
    printf("  %-7d %-40s %2d déclinaison(s) : %d lien(s) créé(s)%s\n", $ps,
        dol_trunc((string) $prestaProduct->getTradValue($prestaProduct->name), 40), $nb, max(0, $after - $before),
        $ok ? '' : ' — ERREUR (voir fin de rapport)');
}

echo str_repeat('-', 76)."\n";
printf("Parents à déclinaisons     : %d  (écartés sans déclinaisons : %d, introuvables : %d)\n",
    $stats['parents'], $stats['sans_combinaisons'], $stats['introuvables']);
printf("Déclinaisons vues          : %d  (déjà liées avant passage : %d)\n", $stats['combinaisons'], $stats['deja_liees']);
if ($stats['refs_vides'] > 0) {
    printf("Références VIDES           : %d  — à remplir côté boutique avant import\n", $stats['refs_vides']);
}
if ($confirm) {
    printf("Liens créés                : %d\n", $stats['crees']);
    printf("Parents en erreur          : %d\n", $stats['erreurs']);
    foreach ($errs as $e) {
        echo "  - ".$e."\n";
    }
} else {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['erreurs'] > 0 ? 1 : 0);
