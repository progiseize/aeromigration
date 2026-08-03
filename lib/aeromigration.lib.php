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
        // Dépend également des tiers : enrichit les fiches existantes, n'en crée aucune.
        array(
            'code'  => 'newsletter',
            'label' => 'AeroMigScriptNewsletter',
            'class' => 'MigrationNewsletter',
            'file'  => '/aeromigration/class/migrationnewsletter.class.php',
        ),
        // À passer avant les articles : ceux-ci s'y rattachent.
        array(
            'code'  => 'category',
            'label' => 'AeroMigScriptCategory',
            'class' => 'MigrationCategory',
            'file'  => '/aeromigration/class/migrationcategory.class.php',
        ),
        // Indépendant des tiers. Nécessite le module aerotoolbox pour ses dictionnaires.
        array(
            'code'  => 'product',
            'label' => 'AeroMigScriptProduct',
            'class' => 'MigrationProduct',
            'file'  => '/aeromigration/class/migrationproduct.class.php',
        ),
        // Dépend des tiers ET des articles : les deux extrémités d'un tarif se retrouvent
        // par leur ref_ext. Les lignes dont l'une manque sont ignorées et signalées.
        array(
            'code'  => 'supplierprice',
            'label' => 'AeroMigScriptSupplierPrice',
            'class' => 'MigrationSupplierPrice',
            'file'  => '/aeromigration/class/migrationsupplierprice.class.php',
        ),
        // Indépendant des autres reprises, mais à passer avant les stocks : ceux-ci s'y
        // rattachent. Lit f_emplacements, seule table du jeu qui ne vienne pas de Sage.
        array(
            'code'  => 'warehouse',
            'label' => 'AeroMigScriptWarehouse',
            'class' => 'MigrationWarehouse',
            'file'  => '/aeromigration/class/migrationwarehouse.class.php',
        ),
        // Alimente le dictionnaire des emplacements d'aerotoolbox. Dépend de l'entrepôt
        // principal, auquel tous les emplacements se rattachent.
        array(
            'code'  => 'location',
            'label' => 'AeroMigScriptLocation',
            'class' => 'MigrationLocation',
            'file'  => '/aeromigration/class/migrationlocation.class.php',
        ),
        // Dépend des articles ET des entrepôts : ce sont les deux extrémités d'un
        // mouvement. S'arrête si l'entrepôt principal est absent.
        array(
            'code'  => 'stock',
            'label' => 'AeroMigScriptStock',
            'class' => 'MigrationStock',
            'file'  => '/aeromigration/class/migrationstock.class.php',
        ),
        // Range les produits dans les emplacements du dictionnaire. Dépend des articles
        // et de « location » ; sans lien avec les quantités, que porte l'entrepôt.
        array(
            'code'  => 'productlocation',
            'label' => 'AeroMigScriptProductLocation',
            'class' => 'MigrationProductLocation',
            'file'  => '/aeromigration/class/migrationproductlocation.class.php',
        ),
        // Dépend des tiers ET des articles. À passer après « stock » sans que ce soit une
        // dépendance : la validation d'une commande fournisseur ne crée aucun mouvement,
        // les constantes STOCK_CALCULATE_ON_SUPPLIER_* n'étant pas posées.
        array(
            'code'  => 'supplierorder',
            'label' => 'AeroMigScriptSupplierOrder',
            'class' => 'MigrationSupplierOrder',
            'file'  => '/aeromigration/class/migrationsupplierorder.class.php',
        ),
        // Dépend des tiers ET des articles. Contrairement aux autres reprises, la cible
        // n'est pas vierge : Prestasync y crée les commandes de la boutique, qui sont
        // adoptées plutôt que recréées.
        array(
            'code'  => 'customerorder',
            'label' => 'AeroMigScriptCustomerOrder',
            'class' => 'MigrationCustomerOrder',
            'file'  => '/aeromigration/class/migrationcustomerorder.class.php',
        ),
    );
}
