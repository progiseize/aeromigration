<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/lib/aeromigration.lib.php
 * \ingroup aeromigration
 * \brief   Fonctions partagées du module de reprise de données.
 */

/**
 * Onglets des pages d'administration du module.
 *
 * @return array<int,array{0:string,1:string,2:string}> Tableau d'onglets pour dol_get_fiche_head()
 */
function aeromigrationAdminPrepareHead()
{
    global $conf, $langs;

    $langs->load('aeromigration@aeromigration');

    $h    = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/aeromigration/admin/setup.php', 1);
    $head[$h][1] = $langs->trans('AeroMigSetup');
    $head[$h][2] = 'setup';
    $h++;

    $head[$h][0] = dol_buildpath('/aeromigration/admin/about.php', 1);
    $head[$h][1] = $langs->trans('About');
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'aeromigration@aeromigration', 'remove');

    return $head;
}

/**
 * Liste des scripts de reprise déclarés dans le module.
 *
 * Chaque script est une classe de class/ qui pilote la création d'objets Dolibarr à
 * partir des tables sources. Ce registre alimente à la fois la page de configuration et
 * le lanceur en ligne de commande : y ajouter une entrée suffit à rendre un nouveau
 * script visible et exécutable.
 *
 * Format de chaque entrée :
 *   'code'  => identifiant court, utilisé en ligne de commande
 *   'label' => clé de traduction du libellé
 *   'class' => nom de la classe de reprise, dans class/
 *   'file'  => chemin du fichier de la classe, relatif au module
 *
 * @return array<int,array{code:string,label:string,class:string,file:string}>
 */
function aeromigrationGetScripts()
{
    return array(
        array(
            'code'  => 'thirdparty',
            'label' => 'AeroMigScriptThirdparty',
            'class' => 'MigrationThirdparty',
            'file'  => '/aeromigration/class/migrationthirdparty.class.php',
        ),
        // Dépend de la reprise des tiers : le rattachement se fait via leur ref_ext.
        array(
            'code'  => 'contact',
            'label' => 'AeroMigScriptContact',
            'class' => 'MigrationContact',
            'file'  => '/aeromigration/class/migrationcontact.class.php',
        ),
    );
}
