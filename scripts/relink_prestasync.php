<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/relink_prestasync.php
 * \ingroup aeromigration
 * \brief   Rétablit les liaisons PrestaShop <-> Dolibarr après une reprise sur base neuve.
 *
 * ## Le problème
 *
 * Prestasync tient ses correspondances par **rowid Dolibarr** (`llx_prestasync_customer.fk_soc_doli`,
 * `llx_prestasync_supplier.fk_soc`). Sur une base neuve, la reprise recrée les tiers avec d'autres
 * rowid : sans ces liaisons, Prestasync ne reconnaît personne et **recrée un tiers à chaque
 * commande** — c'est exactement ce qui est arrivé aux fournisseurs de l'instance de test, tous
 * dédoublés faute de précaution sur `llx_prestasync_supplier`.
 *
 * ## La solution
 *
 * Les liaisons de l'instance actuelle ont été exportées sous forme de **clés stables** — le code
 * tiers ADD, que la reprise repose à l'identique dans `ref_ext` (`SAGE:<CT_Num>`) :
 *
 *   - `data/liaison_presta_tiers_20260819.csv`         155 673 clients  (id presta ; CT_Num ; email ; nom)
 *   - `data/liaison_presta_fournisseurs_20260819.csv`      317 fournisseurs (id presta ; CT_Num ; nom ; observation)
 *
 * Ce script les lit, résout chaque clé vers le rowid de la base courante, et insère les lignes de
 * liaison. Il s'exécute **après `migrate.php thirdparty` et AVANT le premier démarrage de
 * Prestasync** — voir MISE_EN_PRODUCTION.md.
 *
 * Résolution, dans l'ordre : `ref_ext = SAGE:<ct_num_add>` ; à défaut (clients nés dans la
 * boutique, 2 cas connus) l'e-mail. Jamais le code client : généré par Dolibarr à l'import,
 * il diffère d'une base à l'autre et pourrait même désigner un autre tiers sur la base
 * neuve. Les fournisseurs sans jumeau ADD
 * (25 nés dans la boutique) et les ambigus (4, signalés dans le CSV) restent non rattachés :
 * Prestasync les recréera, ou ils se traitent à la main — le rapport les liste.
 *
 * ## Écriture directe assumée
 *
 * Les tables `llx_prestasync_*` appartiennent au module de liaison, qui n'offre aucune API pour
 * les peupler : l'INSERT direct est le seul chemin, comme le fait Prestasync lui-même. Aucune
 * table du coeur Dolibarr n'est touchée.
 *
 * Rejouable : une liaison déjà présente (même id presta, ou même tiers déjà lié) est ignorée.
 *
 * Usage :
 *   php relink_prestasync.php                          simulation, rapport complet
 *   php relink_prestasync.php --confirm                applique
 *   php relink_prestasync.php --customers=FICHIER      CSV clients (défaut : le plus récent de data/)
 *   php relink_prestasync.php --suppliers=FICHIER      CSV fournisseurs (idem)
 *   php relink_prestasync.php --only=customers|suppliers
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

/** Boutique par défaut : l'instance n'en connaît qu'une. */
const DEFAULT_FK_PRESTA = 1;


/*
 * Arguments
 */

$confirm       = false;
$only          = '';
$customersFile = '';
$suppliersFile = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--customers=(.+)$/', $arg, $m)) {
        $customersFile = $m[1];
    } elseif (preg_match('/^--suppliers=(.+)$/', $arg, $m)) {
        $suppliersFile = $m[1];
    } elseif (preg_match('/^--only=(customers|suppliers)$/', $arg, $m)) {
        $only = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--customers=CSV] [--suppliers=CSV] [--only=customers|suppliers]\n";
        exit(1);
    }
}

/**
 * Retourne le fichier de liaison le plus récent du dossier data/.
 *
 * @param  string $pattern Motif glob
 * @return string          Chemin, ou chaîne vide
 */
function latestFile($pattern)
{
    $files = glob(__DIR__.'/../data/'.$pattern);
    if (empty($files)) {
        return '';
    }
    sort($files);
    return end($files);
}

if ($customersFile === '') {
    $customersFile = latestFile('liaison_presta_tiers_*.csv');
}
if ($suppliersFile === '') {
    $suppliersFile = latestFile('liaison_presta_fournisseurs_*.csv');
}

echo "Script      : rétablissement des liaisons PrestaShop <-> Dolibarr\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Clients     : ".($customersFile !== '' ? $customersFile : '(aucun fichier trouvé)')."\n";
echo "Fournisseurs: ".($suppliersFile !== '' ? $suppliersFile : '(aucun fichier trouvé)')."\n\n";


/*
 * Index de résolution : les clés stables vers les rowid de la base courante.
 */

$byRefExt = array();   // SAGE:<ct_num> -> rowid
$byEmail  = array();   // email -> rowid (première occurrence)

$resql = $db->query('SELECT rowid, ref_ext, email FROM '.MAIN_DB_PREFIX.'societe'
    .' WHERE entity IN ('.getEntity('societe').')');
if (!$resql) {
    echo "Lecture des tiers impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    if (!empty($obj->ref_ext)) {
        $byRefExt[strtoupper($obj->ref_ext)] = (int) $obj->rowid;
    }
    $email = strtolower(trim((string) $obj->email));
    if ($email !== '' && !isset($byEmail[$email])) {
        $byEmail[$email] = (int) $obj->rowid;
    }
}
$db->free($resql);
echo "Tiers en base : ".count($byRefExt)." avec clé de reprise\n\n";

/**
 * Lit un CSV de liaison (séparateur « ; », entête en première ligne).
 *
 * @param  string $file Chemin
 * @return array<int,array<string,string>> Lignes indexées par nom de colonne
 */
function readCsv($file)
{
    $fh = fopen($file, 'r');
    if ($fh === false) {
        return array();
    }
    $head = fgetcsv($fh, 0, ';', '"', '\\');
    $rows = array();
    while (($r = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
        $line = array();
        foreach ($head as $i => $col) {
            $line[$col] = isset($r[$i]) ? (string) $r[$i] : '';
        }
        $rows[] = $line;
    }
    fclose($fh);
    return $rows;
}

/**
 * Traite un fichier de liaison vers une table prestasync.
 *
 * @param string $file      CSV source
 * @param string $table     Table de liaison (sans préfixe)
 * @param string $colPresta Colonne de l'identifiant presta dans la table
 * @param string $colSoc    Colonne du tiers dans la table
 * @param string $csvId     Colonne de l'identifiant presta dans le CSV
 * @param bool   $fallbacks Autoriser l'e-mail en repli (clients seulement)
 * @param bool   $confirm   Écrire, ou simuler
 * @param array  $extraCols Colonnes NOT NULL propres à la table (nom => valeur entière)
 * @return void
 */
function relink($file, $table, $colPresta, $colSoc, $csvId, $fallbacks, $confirm, $extraCols = array())
{
    global $db, $byRefExt, $byEmail;

    $rows = readCsv($file);
    if (empty($rows)) {
        echo "  Fichier vide ou illisible : ".$file."\n";
        return;
    }

    // Liaisons déjà en place : par id presta et par tiers, pour l'idempotence.
    $havePresta = array();
    $haveSoc    = array();
    $resql = $db->query('SELECT '.$colPresta.' AS p, '.$colSoc.' AS s FROM '.MAIN_DB_PREFIX.$table);
    if ($resql === false) {
        echo "  Table absente : ".MAIN_DB_PREFIX.$table." — le module de liaison n'est pas installé ?\n";
        return;
    }
    while ($obj = $db->fetch_object($resql)) {
        $havePresta[(int) $obj->p] = true;
        $haveSoc[(int) $obj->s] = true;
    }
    $db->free($resql);

    $inserted = 0;
    $already  = 0;
    $unsolved = array();
    $now      = "'".$db->idate(dol_now())."'";

    foreach ($rows as $r) {
        $idPresta = (int) $r[$csvId];
        if ($idPresta <= 0) {
            continue;
        }
        if (isset($havePresta[$idPresta])) {
            $already++;
            continue;
        }

        // Résolution par clé stable.
        $socid = 0;
        $ct = strtoupper(trim($r['ct_num_add']));
        if ($ct !== '' && isset($byRefExt['SAGE:'.$ct])) {
            $socid = $byRefExt['SAGE:'.$ct];
        } elseif ($fallbacks) {
            // Repli e-mail seulement. Jamais le code client : généré par Dolibarr à
            // l'import, il diffère d'une base à l'autre et pourrait désigner un autre tiers.
            $email = strtolower(trim($r['email']));
            if ($email !== '' && isset($byEmail[$email])) {
                $socid = $byEmail[$email];
            }
        }

        if ($socid <= 0) {
            $unsolved[] = $idPresta.' ('.trim($r['nom']).')';
            continue;
        }
        if (isset($haveSoc[$socid])) {
            // Le tiers est déjà lié à un autre identifiant presta : on ne le dispute pas.
            $already++;
            continue;
        }

        if ($confirm) {
            $fkPresta = ((int) $r['fk_presta'] > 0) ? (int) $r['fk_presta'] : DEFAULT_FK_PRESTA;
            $cols = 'fk_presta, '.$colPresta.', '.$colSoc.', date_creation';
            $vals = $fkPresta.', '.$idPresta.', '.$socid.', '.$now;
            foreach ($extraCols as $col => $val) {
                $cols .= ', '.$col;
                $vals .= ', '.((int) $val);
            }
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.$table.' ('.$cols.') VALUES ('.$vals.')';
            if (!$db->query($sql)) {
                echo "  ÉCHEC id presta ".$idPresta." : ".$db->lasterror()."\n";
                continue;
            }
        }
        $havePresta[$idPresta] = true;
        $haveSoc[$socid] = true;
        $inserted++;
    }

    printf("  %s : %s liaison(s) %s, %s déjà en place, %s non résolue(s)\n",
        $table, number_format($inserted, 0, ',', ' '), $confirm ? 'écrites' : 'à écrire',
        number_format($already, 0, ',', ' '), count($unsolved));
    if ($unsolved) {
        echo "  Non résolues (20 premières) :\n";
        foreach (array_slice($unsolved, 0, 20) as $u) {
            echo "    ".$u."\n";
        }
    }
}

if (($only === '' || $only === 'customers') && $customersFile !== '') {
    echo "=== Clients ===\n";
    relink($customersFile, 'prestasync_customer', 'fk_customer_presta', 'fk_soc_doli',
        'id_customer_presta', true, $confirm);
    echo "\n";
}
if (($only === '' || $only === 'suppliers') && $suppliersFile !== '') {
    echo "=== Fournisseurs ===\n";
    // fk_user_creat et status sont NOT NULL sans défaut ; status = 1 : liaison active,
    // même valeur que celles que Prestasync pose lui-même.
    relink($suppliersFile, 'prestasync_supplier', 'fk_supplier_presta', 'fk_soc',
        'id_supplier_presta', false, $confirm, array('fk_user_creat' => 1, 'status' => 1));
    echo "\n";
}

if (!$confirm) {
    echo "Simulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit(0);
