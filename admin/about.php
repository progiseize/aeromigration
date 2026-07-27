<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/admin/about.php
 * \ingroup aeromigration
 * \brief   Page « À propos » du module de reprise de données.
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

// Access control
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
print dol_get_fiche_head($head, 'about', '', -1, 'fa-database');

print '<p>'.$langs->trans('AeroMigAboutText').'</p>';

print dol_get_fiche_end();

llxFooter();
$db->close();
