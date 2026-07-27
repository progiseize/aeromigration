<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/admin/setup.php
 * \ingroup aeromigration
 * \brief   Page de configuration du module de reprise de données.
 *
 * Point d'entrée du module : elle recensera les scripts de reprise disponibles et leur
 * état d'avancement. Aucun script n'est déclaré pour l'instant.
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

$scripts = aeromigrationGetScripts();

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
        print '<tr class="oddeven">';
        print '<td>'.$langs->trans($script['label']).'</td>';
        print '<td><span class="opacitymedium">'.dol_escape_htmltag($script['code']).'</span></td>';
        print '<td class="center">'.$langs->trans('AeroMigStatusPending').'</td>';
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
