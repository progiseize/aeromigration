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
        // rattachent. Lit f_depotempl, la table des emplacements de rangement.
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
        // Dépend des tiers, des articles ET des commandes clients : c'est par la commande que
        // se reconnaissent les factures déjà produites par Prestasync, et que se pose le lien
        // d'origine des autres. Reprend aussi les règlements.
        array(
            'code'  => 'invoice',
            'label' => 'AeroMigScriptInvoice',
            'class' => 'MigrationInvoice',
            'file'  => '/aeromigration/class/migrationinvoice.class.php',
        ),
        // Dépend des tiers, des articles, des entrepôts ET des commandes fournisseur : les
        // lignes s'adossent aux lignes de commande. Ne mouvemente pas le stock, et refuse de
        // démarrer si la configuration l'y conduirait.
        array(
            'code'  => 'reception',
            'label' => 'AeroMigScriptReception',
            'class' => 'MigrationReception',
            'file'  => '/aeromigration/class/migrationreception.class.php',
        ),
        // Dépend des tiers. Ne crée rien : réaligne la catégorie tarifaire des fiches
        // existantes sur aeromigration_price_level(). À passer avec « customerprice », les
        // clients et les tarifs ne se correspondant pas entre les deux.
        array(
            'code'  => 'pricelevel',
            'label' => 'AeroMigScriptPriceLevel',
            'class' => 'MigrationPriceLevel',
            'file'  => '/aeromigration/class/migrationpricelevel.class.php',
        ),
        // Dépend des articles. Écrit les huit niveaux de prix de vente, dont le premier :
        // c'est lui, et non « product », qui fait autorité sur la tarification client.
        array(
            'code'  => 'customerprice',
            'label' => 'AeroMigScriptCustomerPrice',
            'class' => 'MigrationCustomerPrice',
            'file'  => '/aeromigration/class/migrationcustomerprice.class.php',
        ),
    );
}

/**
 * Convertit une catégorie tarifaire de l'ancien ERP en niveau de prix Dolibarr.
 *
 * ------------------------------------------------------------------------------
 * LES CATÉGORIES 1 ET 2 SONT INVERSÉES, ET CE N'EST PAS UN DÉTAIL.
 * ------------------------------------------------------------------------------
 *
 * Dans l'ancien ERP, la catégorie 1 est le comptoir et la catégorie 2 le site. En cible,
 * les deux sont permutées : le tarif du site devient le **niveau 1**.
 *
 * La raison tient à une chaîne de dépendances qu'il faut avoir en tête avant de toucher
 * à cette table :
 *
 * - le trigger `PRODUCT_PRICE_MODIFY` d'aerotoolbox recopie le prix du niveau 1 dans
 *   `llx_product.price` — le coeur, lui, y laisse le dernier niveau écrit ;
 * - Prestasync publie `llx_product.price` vers la boutique ;
 * - donc **le niveau 1 est le prix que voit le client sur PrestaShop**.
 *
 * Le tarif du site étant celui de 146 388 clients sur 157 189, il est aussi le tarif par
 * défaut : `Product::getSellPrice()` retombe sur `llx_product.price` pour un tiers sans
 * catégorie. Les deux raisons désignent le même niveau.
 *
 * Cette fonction est **la seule autorité** sur la correspondance. `MigrationThirdparty` et
 * `MigrationPriceLevel` l'appellent tous deux : les faire diverger reviendrait à facturer
 * une partie du fichier client au mauvais tarif, sans que rien ne le signale.
 *
 * @param int $catTarif Catégorie tarifaire source (N_CatTarif de f_comptet)
 * @return int          Niveau de prix Dolibarr, 0 si la catégorie est absente ou inconnue
 */
function aeromigration_price_level($catTarif)
{
    $map = array(
        2 => 1,   // site               → Défaut / Site
        1 => 2,   // Comptoir           → Comptoir
        3 => 3,   // Aéro-Clubs
        4 => 4,   // Revendeur
        5 => 5,   // Airbus
        6 => 6,   // Ecole de pilotage
        7 => 7,   // Marche Enac
        8 => 8,   // FFA
    );

    $catTarif = (int) $catTarif;

    return isset($map[$catTarif]) ? $map[$catTarif] : 0;
}

/**
 * Catégorie source correspondant à un niveau de prix Dolibarr.
 *
 * Réciproque de aeromigration_price_level(), dont elle est déduite pour qu'aucune des deux
 * ne puisse être modifiée seule. Sert à `MigrationCustomerPrice`, qui raisonne par niveau
 * cible et doit retrouver la catégorie où lire le tarif.
 *
 * @param int $level Niveau de prix Dolibarr (1 à 8)
 * @return int       Catégorie tarifaire source, 0 si le niveau n'en a pas
 */
function aeromigration_price_category($level)
{
    static $reverse = null;

    if ($reverse === null) {
        $reverse = array();
        for ($cat = 1; $cat <= 8; $cat++) {
            $mapped = aeromigration_price_level($cat);
            if ($mapped > 0) {
                $reverse[$mapped] = $cat;
            }
        }
    }

    $level = (int) $level;

    return isset($reverse[$level]) ? $reverse[$level] : 0;
}
