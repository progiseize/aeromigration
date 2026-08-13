<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/admin/setup.php
 * \ingroup aeromigration
 * \brief   Page de configuration du module de reprise de données.
 *
 * Point d'entrée du module : elle recense les scripts de reprise disponibles et l'état
 * d'avancement de chacun, mesuré sur les objets réellement repris en base.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = include "../../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

// Translations
$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));

// Access control : administrateurs uniquement. La reprise de données modifie des
// données métier en masse, elle ne s'ouvre pas au-delà.
if (!$user->admin) {
    accessforbidden();
}


/*
 * Actions
 */

$action = GETPOST('action', 'aZ09');

if ($action === 'setsourcedb') {
    $sourceDb = trim(GETPOST('AEROMIG_SOURCE_DB', 'alphanohtml'));

    if ($sourceDb !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $sourceDb)) {
        setEventMessages($langs->trans('AeroMigSourceDbInvalid'), null, 'errors');
    } else {
        // ── Le champ vide ne peut PAS être stocké tel quel ──────────────────
        //
        // `dolibarr_set_const()` supprime la constante au lieu de l'enregistrer quand la
        // valeur est vide : `if (strcmp($value, ''))` encadre le seul INSERT
        // (admin.lib.php:722). Une constante « posée à vide » est donc impossible à obtenir
        // par cette voie — et son absence signifie « jamais réglé », auquel cas chaque script
        // retombe sur la base qu'il déclare en dur.
        //
        // Le champ vide est donc enregistré comme le NOM de la base de Dolibarr, que
        // `resolveSourceDb()` ramène de toute façon à « pas de préfixe ». Le réglage survit,
        // et il se relit mieux six mois plus tard qu'une ligne vide.
        if ($sourceDb === '') {
            $sourceDb = $db->database_name;
        }

        dolibarr_set_const($db, 'AEROMIG_SOURCE_DB', $sourceDb, 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}


/*
 * View
 */

$title = $langs->trans('Module399998Name');
llxHeader('', $title);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'fa-database');

$head = aeromigrationAdminPrepareHead();
print dol_get_fiche_head($head, 'setup', '', -1, 'fa-database');

print '<span class="opacitymedium">'.$langs->trans('AeroMigSetupPageHelp').'</span>';
print '<br><br>';

// ── Base où lire les tables de l'ancien ERP ────────────────────────────────
//
// Le réglage vit ici et non dans le code : la même version du module sert en développement,
// où l'export est chargé dans une base à part, et en ligne, où l'hébergement n'autorise
// souvent qu'une seule base — Plesk en donne une par site, avec son propre phpMyAdmin.

$sourceDbSet = isset($conf->global->AEROMIG_SOURCE_DB);
$sourceDb    = $sourceDbSet ? trim(getDolGlobalString('AEROMIG_SOURCE_DB')) : '';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setsourcedb">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3">'.$langs->trans('AeroMigSourceDbTitle').'</td></tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('AeroMigSourceDb').'</td>';
print '<td><input type="text" name="AEROMIG_SOURCE_DB" value="'.dol_escape_htmltag($sourceDb).'"';
print ' placeholder="'.dol_escape_htmltag($db->database_name).'" size="30"></td>';
print '<td class="right"><input type="submit" class="button" value="'.$langs->trans('Save').'"></td>';
print '</tr>';
print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">';
print $langs->trans('AeroMigSourceDbHelp', $db->database_name);
print '</span></td></tr>';
print '</table>';
print '</form>';
print '<br>';

$scripts = aeromigrationGetScripts();

// ── L'état ne se calcule que sur demande ───────────────────────────────────
//
// Mesurer l'avancement interroge la base seize fois, et certaines de ces requêtes portent sur
// des centaines de milliers de lignes. Tant que la source et la cible partagent un serveur,
// cela se compte en secondes ; dès qu'elles sont séparées — deux bases, deux collations —,
// en minutes. La page devenait alors inutilisable, et le réglage qu'on venait y chercher
// inaccessible : précisément celui qui aurait corrigé la situation.
//
// Elle s'affiche donc toujours, et le calcul se demande.

$showStatus = (GETPOSTINT('status') === 1);

if (!$showStatus) {
    print '<div class="center"><a class="button" href="'.$_SERVER['PHP_SELF'].'?status=1">';
    print $langs->trans('AeroMigComputeStatus').'</a></div>';
    print '<br>';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('AeroMigScript').'</td>';
print '<td>'.$langs->trans('AeroMigScriptCode').'</td>';
print '<td class="center">'.$langs->trans('AeroMigScriptStatus').'</td>';
print '</tr>';

if (empty($scripts)) {
    print '<tr class="oddeven"><td colspan="3" class="opacitymedium center">';
    print $langs->trans('AeroMigNoScript');
    print '</td></tr>';
} else {
    foreach ($scripts as $script) {
        // L'état se mesure sur la base, pas sur une trace d'exécution : un script est
        // « lancé » si ce qu'il produit est là. C'est la seule mesure qui reste juste
        // quel que soit l'environnement, et après une purge.
        $done        = -1;
        $sourceIssue = '';
        if ($showStatus) {
            dol_include_once($script['file']);
            if (class_exists($script['class'])) {
                $runner = new $script['class']($db, $user);
                $done   = $runner->countMigrated();
                // Une source injoignable ne se voyait qu'à un état « Indéterminé », qui pouvait
                // aussi bien signifier une dépendance absente. Le dire explicitement évite de
                // chercher du côté de la cible un défaut qui est du côté de la source.
                $sourceIssue = $runner->sourceError();
            }
        }

        if (!$showStatus) {
            $status = '<span class="opacitymedium">—</span>';
        } elseif ($sourceIssue !== '') {
            $status = '<span class="badge badge-status8 badge-status" title="'
                .dol_escape_htmltag($sourceIssue).'">'.$langs->trans('AeroMigStatusNoSource').'</span>';
        } elseif ($done > 0) {
            // Le volume repris n'est pas affiché : cette colonne répond à une seule
            // question, celle de savoir où l'on en est dans l'ordre des reprises.
            $status = '<span class="badge badge-status4 badge-status">'
                .$langs->trans('AeroMigStatusDone').'</span>';
        } elseif ($done === 0) {
            $status = '<span class="opacitymedium">'.$langs->trans('AeroMigStatusPending').'</span>';
        } else {
            // Comptage impossible : table cible absente, ou dépendance non installée.
            $status = '<span class="opacitymedium">'.$langs->trans('AeroMigStatusUnknown').'</span>';
        }

        print '<tr class="oddeven">';
        print '<td>'.$langs->trans($script['label']).'</td>';
        print '<td><span class="opacitymedium">'.dol_escape_htmltag($script['code']).'</span></td>';
        print '<td class="center">'.$status.'</td>';
        print '</tr>';
    }
}

print '</table>';

if (!empty($scripts)) {
    print '<br>';
    print '<span class="opacitymedium">'.$langs->trans('AeroMigCliHelp', $scripts[0]['code']).'</span>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
