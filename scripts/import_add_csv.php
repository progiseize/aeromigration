<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_add_csv.php
 * \ingroup aeromigration
 * \brief   Construit la base de travail à partir de l'export CSV intégral d'ADD.
 *
 * L'éditeur d'ADD livre l'ensemble de son dossier sous forme d'un CSV par table,
 * nommés « advance_1_table_<table>.csv ». Ce script en fait une base MySQL
 * interrogeable, qui sert ensuite de source aux scripts de reprise.
 *
 * Il ne touche à aucune table Dolibarr : il ne fait que reconstituer la source.
 *
 * ---------------------------------------------------------------------------
 * Le format de l'export, vérifié sur les fichiers livrés
 * ---------------------------------------------------------------------------
 *   - séparateur virgule, valeurs entre guillemets, fins de ligne LF ;
 *   - guillemets internes doublés (RFC 4180) : Mug ""Air France"" ;
 *   - les valeurs nulles sont écrites NULL sans guillemets — MySQL les lit comme
 *     NULL dès lors que FIELDS ENCLOSED BY n'est pas vide, alors que "NULL" entre
 *     guillemets resterait la chaîne ;
 *   - encodage UTF-8.
 *
 * ---------------------------------------------------------------------------
 * Pourquoi LOAD DATA INFILE et non LOAD DATA LOCAL INFILE
 * ---------------------------------------------------------------------------
 * local_infile est à OFF sur le serveur, et l'activer suppose un SET GLOBAL qui
 * modifie la configuration pour toutes les connexions. La variable secure_file_priv
 * désigne un dossier depuis lequel le serveur accepte de lire : il suffit d'y
 * déposer les CSV pour se passer de LOCAL, sans rien changer au serveur.
 *
 * Le script refuse de démarrer si la source est hors de ce dossier, et le dit.
 *
 * ---------------------------------------------------------------------------
 * D'où vient le schéma de chaque table
 * ---------------------------------------------------------------------------
 * Un CSV ne porte aucun type. Deux cas :
 *
 *   1. La table existe déjà dans la base modèle (--model, « aero » par défaut),
 *      avec exactement les mêmes colonnes : son schéma est repris tel quel, index
 *      compris. C'est le cas des tables f_* importées lors des premiers travaux,
 *      dont les types Sage sont connus et éprouvés.
 *
 *   2. Sinon, les types sont déduits du contenu. Par défaut le fichier est lu en
 *      entier : c'est un passage de lecture supplémentaire, mais il garantit qu'une
 *      valeur longue tapie en fin de fichier ne sera pas tronquée à l'insertion.
 *      --sample=N s'en tient aux N premiers enregistrements quand la vitesse prime.
 *
 * Deux pièges de l'inférence, traités explicitement :
 *   - « 00003 » est un numérique en apparence seulement : un zéro de tête signe une
 *     référence, jamais un nombre. Ces colonnes restent du texte, faute de quoi les
 *     jointures sur AR_Ref échoueraient.
 *   - une colonne entièrement vide ne donne aucune indication : elle devient du
 *     texte court, choix neutre et sans perte.
 *
 * Usage :
 *   php import_add_csv.php --source=C:/wamp64/tmp/add [options]
 *
 * Options :
 *   --source=DOSSIER   Dossier contenant les CSV (obligatoire)
 *   --database=NOM     Base de destination (défaut : aeroprod)
 *   --model=NOM        Base où chercher un schéma déjà connu (défaut : aero)
 *                      --model= (vide) force l'inférence partout
 *   --prefix=CHAINE    Préfixe à retirer du nom de fichier (défaut : advance_1_table_)
 *   --only=a,b,c       Ne traiter que ces tables
 *   --skip=a,b         Écarter ces tables
 *   --max-size=N       Écarter les fichiers de plus de N Mo
 *   --sample=N         Limiter l'inférence aux N premiers enregistrements (0 = tout)
 *   --no-index         Ne pas créer d'index sur les colonnes de jointure usuelles
 *   --keep             Conserver les tables déjà chargées au lieu de les refaire
 *   --dry-run          Afficher le schéma retenu sans rien écrire
 *
 * Exemples :
 *   php import_add_csv.php --source=C:/wamp64/tmp/add --dry-run
 *   php import_add_csv.php --source=C:/wamp64/tmp/add
 *   php import_add_csv.php --source=C:/wamp64/tmp/add --only=z_tarifparticulier
 */

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', '1');
}
if (!defined('NOSESSION')) {
    define('NOSESSION', '1');
}

$sapi_type   = php_sapi_name();
$script_file = basename(__FILE__);
$path        = __DIR__.'/';

if (substr($sapi_type, 0, 3) === 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(1);
}

require_once $path.'../../../master.inc.php';


/*
 * Lecture des arguments
 */

$source    = '';
$database  = 'aeroprod';
$model     = 'aero';
$prefix    = 'advance_1_table_';
$only      = array();
$skip      = array();
$maxSize   = 0;
$sample    = 0;
$makeIndex = true;
$keep      = false;
$dryrun    = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--dry-run') {
        $dryrun = true;
    } elseif ($arg === '--no-index') {
        $makeIndex = false;
    } elseif ($arg === '--keep') {
        $keep = true;
    } elseif (preg_match('/^--source=(.+)$/', $arg, $m)) {
        $source = rtrim(str_replace('\\', '/', $m[1]), '/');
    } elseif (preg_match('/^--database=(.+)$/', $arg, $m)) {
        $database = $m[1];
    } elseif (preg_match('/^--model=(.*)$/', $arg, $m)) {
        $model = $m[1];
    } elseif (preg_match('/^--prefix=(.*)$/', $arg, $m)) {
        $prefix = $m[1];
    } elseif (preg_match('/^--only=(.+)$/', $arg, $m)) {
        $only = array_filter(array_map('trim', explode(',', $m[1])));
    } elseif (preg_match('/^--skip=(.+)$/', $arg, $m)) {
        $skip = array_filter(array_map('trim', explode(',', $m[1])));
    } elseif (preg_match('/^--max-size=(\d+)$/', $arg, $m)) {
        $maxSize = (int) $m[1];
    } elseif (preg_match('/^--sample=(\d+)$/', $arg, $m)) {
        $sample = (int) $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        exit(1);
    }
}

if ($source === '') {
    echo "Usage: php ".$script_file." --source=DOSSIER [--database=aeroprod] [--model=aero]\n";
    echo "       [--prefix=advance_1_table_] [--only=a,b] [--skip=a,b] [--max-size=N]\n";
    echo "       [--sample=N] [--no-index] [--keep] [--dry-run]\n";
    exit(1);
}
if (!is_dir($source)) {
    echo "Dossier source introuvable : ".$source."\n";
    exit(1);
}

// Les identifiants de base et de table finissent dans du SQL que rien ne peut
// échapper : on les cantonne à un alphabet sûr plutôt que de faire confiance.
foreach (array('database' => $database, 'model' => $model) as $label => $value) {
    if ($value !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        echo "Nom de base non exploitable (--".$label.") : ".$value."\n";
        exit(1);
    }
}


/*
 * Vérifications préalables
 */

$sqlModeBackup = null;

/**
 * Interroge une variable serveur.
 *
 * @param  DoliDB $db   Connexion
 * @param  string $name Nom de la variable
 * @return string       Valeur, ou chaîne vide
 */
function serverVariable($db, $name)
{
    $resql = $db->query("SHOW VARIABLES LIKE '".$db->escape($name)."'");
    if (!$resql) {
        return '';
    }
    $obj = $db->fetch_object($resql);
    $db->free($resql);
    return $obj ? (string) $obj->Value : '';
}

// LOAD DATA INFILE résout un chemin relatif par rapport au datadir du serveur
// MySQL, pas au dossier courant du script : une source relative trouverait les
// fichiers en PHP puis échouerait à chaque chargement. On la rend absolue.
$realSource = realpath($source);
if ($realSource !== false) {
    $source = str_replace('\\', '/', $realSource);
}

$securePriv = str_replace('\\', '/', serverVariable($db, 'secure_file_priv'));

if ($securePriv === 'NULL') {
    echo "Le serveur interdit toute lecture de fichier (secure_file_priv = NULL).\n";
    echo "L'import par LOAD DATA INFILE est impossible en l'état.\n";
    exit(1);
}
if ($securePriv !== '') {
    $normalized = rtrim($securePriv, '/');
    if (stripos($source.'/', $normalized.'/') !== 0) {
        echo "Le serveur n'accepte de lire que sous : ".$normalized."\n";
        echo "Or la source est               : ".$source."\n\n";
        echo "Déplacez les CSV sous ce dossier, par exemple :\n";
        echo "   ".$normalized."/add\n";
        exit(1);
    }
}

// La base de destination doit exister : la créer serait un geste plus lourd que ce
// que ce script assume, et son nom engage le reste des travaux.
$resql = $db->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA"
    ." WHERE SCHEMA_NAME = '".$db->escape($database)."'");
if (!$resql || $db->num_rows($resql) === 0) {
    echo "Base de destination introuvable : ".$database."\n";
    echo "Créez-la d'abord, en utf8mb4 :\n";
    echo "   CREATE DATABASE ".$database." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    exit(1);
}


/*
 * Inventaire des fichiers
 */

$files = glob($source.'/'.$prefix.'*.csv');
if ($files === false || empty($files)) {
    echo "Aucun fichier ".$prefix."*.csv dans ".$source."\n";
    exit(1);
}
sort($files);

$jobs = array();
foreach ($files as $file) {
    $table = basename($file, '.csv');
    if ($prefix !== '' && strpos($table, $prefix) === 0) {
        $table = substr($table, strlen($prefix));
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        echo "Nom de table écarté, caractères inattendus : ".$table."\n";
        continue;
    }
    if (!empty($only) && !in_array($table, $only, true)) {
        continue;
    }
    if (in_array($table, $skip, true)) {
        continue;
    }
    $size = filesize($file);
    if ($maxSize > 0 && $size > $maxSize * 1024 * 1024) {
        continue;
    }
    $jobs[$table] = array('file' => $file, 'size' => $size);
}

if (empty($jobs)) {
    echo "Aucune table à traiter après application des filtres.\n";
    exit(1);
}

$totalSize = 0;
foreach ($jobs as $job) {
    $totalSize += $job['size'];
}

echo "Source          : ".$source."\n";
echo "Destination     : ".$database."\n";
echo "Schémas connus  : ".($model === '' ? '(aucun, inférence partout)' : $model)."\n";
echo "Tables          : ".count($jobs)."\n";
echo "Volume          : ".round($totalSize / 1048576, 1)." Mo\n";
echo "Inférence       : ".($sample > 0 ? $sample." premier(s) enregistrement(s)" : "fichier entier")."\n";
echo "Mode            : ".($dryrun ? "SIMULATION (aucune écriture)" : "ÉCRITURE")."\n";
echo str_repeat('=', 100)."\n";


/*
 * Outils d'inférence
 */

/**
 * Ouvre un CSV et retourne son entête.
 *
 * @param  string   $file Chemin
 * @return array          array(handle, colonnes) ou array(null, null)
 */
function openCsv($file)
{
    $fh = fopen($file, 'r');
    if ($fh === false) {
        return array(null, null);
    }
    // Le BOM éventuel collerait au premier nom de colonne.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $head = fgetcsv($fh, 0, ',', '"', '');
    if ($head === false || $head === null) {
        fclose($fh);
        return array(null, null);
    }
    $cols = array();
    foreach ($head as $name) {
        $cols[] = trim((string) $name);
    }
    return array($fh, $cols);
}

/**
 * Déduit un type MySQL par colonne à partir du contenu du fichier.
 *
 * @param  string $file   Chemin du CSV
 * @param  array  $cols   Noms de colonnes
 * @param  int    $sample Nombre d'enregistrements à lire, 0 pour tout
 * @return array          array('types' => array, 'rows' => int, 'ragged' => int)
 */
function inferTypes($file, $cols, $sample)
{
    $n = count($cols);

    // Un profil par colonne, resserré à chaque valeur rencontrée.
    $prof = array();
    for ($i = 0; $i < $n; $i++) {
        $prof[$i] = array(
            'vide'    => true,   // que des NULL ou des chaînes vides
            'int'     => true,
            'dec'     => true,
            'date'    => true,
            'datetime' => true,
            'lead0'   => false,  // un zéro de tête : c'est une référence, pas un nombre
            'len'     => 0,
            'intmax'  => 0,
            'scale'   => 0,
        );
    }

    list($fh, $ignored) = openCsv($file);
    if ($fh === null) {
        return array('types' => array(), 'rows' => 0, 'ragged' => 0);
    }

    $rows = 0;
    $ragged = 0;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === null) {
            continue;
        }
        // fgetcsv rend array(null) sur une ligne vide en fin de fichier.
        if (count($r) === 1 && ($r[0] === null || $r[0] === '')) {
            continue;
        }
        $rows++;
        if (count($r) !== $n) {
            $ragged++;
        }

        $limit = min($n, count($r));
        for ($i = 0; $i < $limit; $i++) {
            $v = $r[$i];
            if ($v === null) {
                continue;
            }
            $v = (string) $v;
            if ($v === '' || $v === 'NULL') {
                continue;
            }

            $p = &$prof[$i];
            $p['vide'] = false;

            $len = strlen($v);
            if ($len > $p['len']) {
                $p['len'] = $len;
            }

            if ($p['int'] || $p['dec']) {
                // Un zéro de tête sur plus d'un caractère désigne une référence.
                if ($len > 1 && ($v[0] === '0' && $v[1] !== '.')) {
                    $p['lead0'] = true;
                    $p['int']   = false;
                    $p['dec']   = false;
                }
            }
            if ($p['int'] && !preg_match('/^-?\d+$/', $v)) {
                $p['int'] = false;
            }
            if ($p['int']) {
                $abs = ltrim($v, '-');
                if (strlen($abs) > strlen((string) $p['intmax'])) {
                    $p['intmax'] = (int) str_repeat('9', min(18, strlen($abs)));
                }
            }
            if ($p['dec'] && !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
                $p['dec'] = false;
            }
            if ($p['dec']) {
                $dot = strpos($v, '.');
                if ($dot !== false) {
                    $s = $len - $dot - 1;
                    if ($s > $p['scale']) {
                        $p['scale'] = $s;
                    }
                }
            }
            if ($p['date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $p['date'] = false;
            }
            if ($p['datetime'] && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $v)) {
                $p['datetime'] = false;
            }
            unset($p);
        }

        if ($sample > 0 && $rows >= $sample) {
            break;
        }
    }
    fclose($fh);

    $types = array();
    for ($i = 0; $i < $n; $i++) {
        $p = $prof[$i];

        if ($p['vide']) {
            // Aucune valeur : rien ne permet de trancher, le texte court ne perd rien.
            $types[$i] = 'VARCHAR(255)';
            continue;
        }
        if ($p['date']) {
            $types[$i] = 'DATE';
            continue;
        }
        if ($p['datetime']) {
            $types[$i] = 'DATETIME';
            continue;
        }
        if ($p['int'] && !$p['lead0']) {
            if ($p['len'] <= 9) {
                $types[$i] = 'INT';
            } elseif ($p['len'] <= 18) {
                $types[$i] = 'BIGINT';
            } else {
                $types[$i] = 'VARCHAR(64)';
            }
            continue;
        }
        if ($p['dec'] && !$p['lead0']) {
            // Sage tient ses montants en decimal(24,6) : on s'aligne tant que
            // l'échelle observée y tient, pour que les comparaisons soient exactes.
            $types[$i] = ($p['scale'] <= 6) ? 'DECIMAL(24,6)' : 'DOUBLE';
            continue;
        }

        // Texte. La marge absorbe ce qu'un échantillon n'aurait pas vu ; sur un
        // fichier lu en entier elle ne coûte rien, VARCHAR étant de taille variable.
        $len = $p['len'];
        if ($sample > 0) {
            $len = (int) ceil($len * 1.5);
        }
        if ($len <= 32) {
            $types[$i] = 'VARCHAR(32)';
        } elseif ($len <= 64) {
            $types[$i] = 'VARCHAR(64)';
        } elseif ($len <= 255) {
            $types[$i] = 'VARCHAR(255)';
        } elseif ($len <= 1024) {
            $types[$i] = 'VARCHAR(1024)';
        } elseif ($len <= 65000) {
            $types[$i] = 'TEXT';
        } elseif ($len <= 16000000) {
            $types[$i] = 'MEDIUMTEXT';
        } else {
            $types[$i] = 'LONGTEXT';
        }
    }

    $types = fitRowSize($types);

    return array('types' => $types, 'rows' => $rows, 'ragged' => $ragged);
}

/**
 * Ramène la ligne sous la limite de taille d'InnoDB.
 *
 * Une ligne InnoDB ne peut excéder 65 535 octets, colonnes TEXT et BLOB exclues
 * puisque celles-là ne laissent qu'un pointeur sur place. En utf8mb4 un caractère
 * peut en peser quatre : un VARCHAR(255) réserve donc 1 020 octets, et une table
 * qui aligne quelques dizaines de colonnes de texte dépasse la limite — ce sont
 * les tables de documents d'ADD, qui portent de longues zones de saisie libre.
 *
 * Plutôt que de rogner sur les longueurs, au risque de tronquer, on bascule en
 * TEXT les colonnes les plus larges jusqu'à repasser sous la limite. Aucune valeur
 * n'est perdue ; seules ces colonnes cessent d'être indexables en l'état, ce dont
 * on peut se passer sur des zones de commentaire.
 *
 * Une seconde limite, plus basse, guette les tables très larges : InnoDB refuse
 * qu'une ligne occupe plus de la moitié d'une page, soit 8 126 octets. Les tables
 * de paramétrage d'ADD, qui alignent trois cents colonnes, s'y heurtent alors même
 * qu'elles tiennent sous 65 535. D'où le budget paramétrable, que l'appelant abaisse
 * quand le serveur refuse la table.
 *
 * @param  array $types  Types retenus, indexés par position
 * @param  int   $budget Octets alloués à la ligne
 * @return array         Types ajustés
 */
function fitRowSize($types, $budget = 60000)
{
    $cost = function ($type) {
        if (preg_match('/^VARCHAR\((\d+)\)$/', $type, $m)) {
            return ((int) $m[1]) * 4 + 2;
        }
        if (strpos($type, 'TEXT') !== false) {
            return 20;   // seul le pointeur reste dans la ligne
        }
        if ($type === 'BIGINT' || $type === 'DOUBLE') {
            return 8;
        }
        if ($type === 'INT') {
            return 4;
        }
        if ($type === 'DATE') {
            return 3;
        }
        if ($type === 'DATETIME') {
            return 5;
        }
        return 11;       // DECIMAL(24,6)
    };

    $total = 0;
    foreach ($types as $t) {
        $total += $cost($t);
    }
    if ($total <= $budget) {
        return $types;
    }

    // Les plus larges d'abord : chaque bascule rapporte le plus possible, donc en
    // convertit le moins possible.
    $order = array();
    foreach ($types as $i => $t) {
        if (preg_match('/^VARCHAR\((\d+)\)$/', $t, $m)) {
            $order[$i] = (int) $m[1];
        }
    }
    arsort($order);

    foreach ($order as $i => $width) {
        if ($total <= $budget) {
            break;
        }
        $total -= $cost($types[$i]);
        $types[$i] = 'TEXT';
        $total += 20;
    }

    return $types;
}

/**
 * Retourne les colonnes de la table modèle si elle correspond au CSV.
 *
 * L'export est plus récent que les tables importées lors des premiers travaux :
 * une colonne ajoutée depuis suffit à disqualifier le modèle, sans quoi le
 * chargement écrirait des valeurs dans les mauvaises colonnes.
 *
 * @param  DoliDB $db    Connexion
 * @param  string $model Base modèle
 * @param  string $table Table
 * @param  array  $cols  Colonnes du CSV
 * @return bool          Vrai si le modèle est utilisable
 */
function modelMatches($db, $model, $table, $cols)
{
    if ($model === '') {
        return false;
    }
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        ." WHERE TABLE_SCHEMA = '".$db->escape($model)."'"
        ." AND TABLE_NAME = '".$db->escape($table)."'";
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) === 0) {
        return false;
    }
    $have = array();
    while ($obj = $db->fetch_object($resql)) {
        $have[strtolower($obj->COLUMN_NAME)] = true;
    }
    $db->free($resql);

    if (count($have) !== count($cols)) {
        return false;
    }
    foreach ($cols as $c) {
        if (!isset($have[strtolower($c)])) {
            return false;
        }
    }
    return true;
}


/*
 * Traitement
 */

// L'export porte des dates à zéro et des champs vides sur des colonnes typées ;
// un mode strict ferait échouer le chargement là où un avertissement suffit. La
// valeur d'origine est remise en fin de passage.
$sqlModeBackup = serverVariable($db, 'sql_mode');
$db->query("SET SESSION sql_mode = ''");

// Colonnes sur lesquelles portent les jointures d'analyse. L'index est créé après
// le chargement : le construire avant ralentirait chaque insertion.
$indexCandidates = array('AR_Ref', 'CT_Num', 'DO_Piece', 'DE_No', 'CL_No', 'cbMarq', 'DO_Date', 'AF_RefFourniss');

$report = array();
$totalRows = 0;
$startAll = microtime(true);
$rank = 0;

foreach ($jobs as $table => $job) {
    $rank++;
    $start = microtime(true);

    printf("[%3d/%3d] %-40s %8s Mo  ", $rank, count($jobs), $table, round($job['size'] / 1048576, 1));

    list($fh, $cols) = openCsv($job['file']);
    if ($fh === null) {
        echo "ILLISIBLE\n";
        $report[$table] = array('etat' => 'illisible');
        continue;
    }
    fclose($fh);

    if (empty($cols)) {
        echo "SANS ENTÊTE\n";
        $report[$table] = array('etat' => 'sans entête');
        continue;
    }

    // Une colonne sans nom empêcherait de nommer les cibles du chargement.
    $bad = false;
    foreach ($cols as $c) {
        if ($c === '' || !preg_match('/^[A-Za-z0-9_]+$/', $c)) {
            $bad = true;
            break;
        }
    }
    if ($bad) {
        echo "COLONNES NON EXPLOITABLES\n";
        $report[$table] = array('etat' => 'colonnes non exploitables');
        continue;
    }

    if ($keep) {
        $resql = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES"
            ." WHERE TABLE_SCHEMA = '".$db->escape($database)."'"
            ." AND TABLE_NAME = '".$db->escape($table)."'");
        if ($resql && $db->num_rows($resql) > 0) {
            echo "conservée\n";
            $report[$table] = array('etat' => 'conservée');
            continue;
        }
    }

    // Schéma : modèle connu, sinon inférence.
    $useModel = modelMatches($db, $model, $table, $cols);
    $dateCols = array();
    $ddl = '';
    $inferred = array();

    // En ligne, base unique : le modèle peut être la base de destination elle-même
    // (tables créées au préalable depuis un dump de structure). La table en place
    // EST alors le modèle — la refaire par DROP + CREATE LIKE la détruirait, et
    // MySQL refuse de toute façon un CREATE LIKE d'une table sur elle-même
    // (« Not unique table/alias »). On la vide au lieu de la refaire.
    $modelIsDest = ($useModel && $model === $database);

    if ($useModel) {
        if (!$modelIsDest) {
            $ddl = "CREATE TABLE `".$database."`.`".$table."` LIKE `".$model."`.`".$table."`";
        }
        $origine = 'modèle '.$model.($modelIsDest ? ' (en place, vidée)' : '');
        $rowsFile = null;
        $ragged = 0;
    } else {
        $inf = inferTypes($job['file'], $cols, $sample);
        if (empty($inf['types'])) {
            echo "INFÉRENCE IMPOSSIBLE\n";
            $report[$table] = array('etat' => 'inférence impossible');
            continue;
        }
        $rowsFile = $inf['rows'];
        $ragged   = $inf['ragged'];
        $inferred = $inf['types'];
        $origine  = 'inféré';
    }

    /**
     * Assemble le CREATE d'une table inférée, sous un budget de taille de ligne.
     *
     * @param  int $budget Octets alloués à la ligne, 0 pour ne rien ajuster
     * @return array       array(ddl, colonnes de date)
     */
    $buildDdl = function ($budget, $engine = 'InnoDB') use ($cols, $inferred, $database, $table) {
        $types = ($budget > 0) ? fitRowSize($inferred, $budget) : $inferred;
        $defs  = array();
        $dates = array();
        foreach ($cols as $i => $c) {
            $type = isset($types[$i]) ? $types[$i] : 'VARCHAR(255)';
            $defs[] = "  `".$c."` ".$type." NULL";
            // Les colonnes qui ne sont pas du texte passeront par une variable au
            // chargement : l'export écrit un champ vide là où la valeur est absente,
            // et MySQL le traduirait par un zéro ou une date à zéro. Un identifiant
            // absent deviendrait ainsi l'identifiant 0, qu'on ne saurait plus
            // distinguer d'un vrai.
            if ($type === 'DATE' || $type === 'DATETIME') {
                $dates[$c] = 'date';
            } elseif (strpos($type, 'VARCHAR') === false && strpos($type, 'TEXT') === false) {
                $dates[$c] = 'num';
            }
        }
        // Pas de COLLATE explicite : la table hérite de celui de la base, et toutes
        // les tables de la source parlent ainsi la même langue. Une collation propre
        // à quelques tables obligerait à écrire CONVERT(... USING utf8mb4) dans
        // chaque jointure, ce que les premiers travaux ont assez coûté.
        $ddl = "CREATE TABLE `".$database."`.`".$table."` (\n".implode(",\n", $defs)."\n)"
            ." ENGINE=".$engine." DEFAULT CHARSET=utf8mb4";
        return array($ddl, $dates);
    };

    if (!$useModel) {
        list($ddl, $dateCols) = $buildDdl(0);
    }

    if ($dryrun) {
        echo "\n".($modelIsDest ? "TRUNCATE TABLE `".$database."`.`".$table."`" : $ddl).";\n";
        if ($rowsFile !== null) {
            echo "   -- ".$rowsFile." enregistrement(s)"
                .($ragged > 0 ? ", ".$ragged." de largeur inattendue" : '')."\n";
        }
        echo "\n";
        $report[$table] = array('etat' => 'simulé', 'origine' => $origine, 'fichier' => $rowsFile);
        continue;
    }

    if ($modelIsDest) {
        $created = $db->query("TRUNCATE TABLE `".$database."`.`".$table."`");
    } else {
        $db->query("DROP TABLE IF EXISTS `".$database."`.`".$table."`");
        $created = $db->query($ddl);
    }

    // Une ligne encore trop large : on resserre le budget et on réessaie, ce qui
    // bascule en TEXT les colonnes suivantes par ordre de largeur. Les paliers
    // descendent sous la limite de demi-page d'InnoDB, seul recours quand la table
    // compte plusieurs centaines de colonnes.
    if (!$created && !$useModel && stripos($db->lasterror(), 'row size too large') !== false) {
        foreach (array(7800, 5000, 2500) as $budget) {
            list($ddl, $dateCols) = $buildDdl($budget);
            $db->query("DROP TABLE IF EXISTS `".$database."`.`".$table."`");
            $created = $db->query($ddl);
            if ($created) {
                $origine .= ' (ligne resserrée)';
                break;
            }
        }

        // Dernier recours pour les tables de paramétrage d'ADD, qui alignent plus de
        // trois cents colonnes : InnoDB les refuse même entièrement en TEXT, la demi-
        // page ne suffisant plus à loger les seuls pointeurs. MyISAM ignore cette
        // contrainte. Le choix ne porte à conséquence que sur des tables figées, d'une
        // poignée de lignes, qu'on ne fait que lire.
        if (!$created) {
            list($ddl, $dateCols) = $buildDdl(2500, 'MyISAM');
            $db->query("DROP TABLE IF EXISTS `".$database."`.`".$table."`");
            $created = $db->query($ddl);
            if ($created) {
                $origine .= ' (MyISAM, trop de colonnes)';
            }
        }
    }

    if (!$created) {
        echo "ÉCHEC CREATE : ".$db->lasterror()."\n";
        $report[$table] = array('etat' => 'échec create', 'message' => $db->lasterror());
        continue;
    }

    // Les tables f_* de la base modèle datent des premiers imports et sont restées
    // en utf8mb3 ; CREATE TABLE LIKE recopierait ce jeu de caractères et rouvrirait
    // le chapitre des collations divergentes. La table étant encore vide, la
    // conversion ne coûte rien. Si un index trop long l'empêche — utf8mb4 compte
    // quatre octets par caractère là où utf8mb3 en comptait trois — on garde la
    // collation d'origine et on le dit, plutôt que d'abandonner la table.
    $collationNote = '';
    if ($useModel) {
        if (!$db->query("ALTER TABLE `".$database."`.`".$table."` CONVERT TO CHARACTER SET utf8mb4")) {
            $collationNote = '  collation d\'origine conservée';
        }
    }

    // Chargement. Les colonnes sont nommées dans l'ordre du fichier : c'est ce qui
    // rend l'opération sûre quand le modèle range ses colonnes autrement.
    //
    // Les colonnes de date passent par une variable, le temps de ramener à NULL les
    // valeurs vides et les dates à zéro que porte l'export.
    $targets = array();
    $sets    = array();
    foreach ($cols as $c) {
        if (isset($dateCols[$c])) {
            $targets[] = '@v_'.$c;
            if ($dateCols[$c] === 'date') {
                $sets[] = "`".$c."` = IF(@v_".$c." IN ('', '0000-00-00', '0000-00-00 00:00:00'), NULL, @v_".$c.")";
            } else {
                $sets[] = "`".$c."` = NULLIF(@v_".$c.", '')";
            }
        } else {
            $targets[] = '`'.$c.'`';
        }
    }

    // ESCAPED BY '' et non ESCAPED BY '"' : le second ferait du guillemet un
    // caractère d'échappement, si bien que le guillemet fermant d'une ligne
    // échapperait le saut de ligne qui la suit. MySQL lit alors le fichier entier
    // comme un seul enregistrement, que IGNORE 1 LINES écarte — la table reste
    // vide sans la moindre erreur. Le piège n'apparaît que sur les tables dont la
    // dernière colonne est entre guillemets, ce qui le rend d'autant plus sournois.
    //
    // Vidé, aucun caractère n'échappe plus rien, et le doublement RFC 4180 des
    // guillemets internes reste géré par ENCLOSED BY, qui le prévoit nativement.
    $sql = "LOAD DATA INFILE '".$db->escape($job['file'])."'"
        ." INTO TABLE `".$database."`.`".$table."`"
        ." CHARACTER SET utf8mb4"
        ." FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY ''"
        ." LINES TERMINATED BY '\\n'"
        ." IGNORE 1 LINES"
        ." (".implode(', ', $targets).")";
    if (!empty($sets)) {
        $sql .= " SET ".implode(', ', $sets);
    }

    if (!$db->query($sql)) {
        echo "ÉCHEC LOAD : ".$db->lasterror()."\n";
        $report[$table] = array('etat' => 'échec load', 'origine' => $origine, 'message' => $db->lasterror());
        continue;
    }

    // Avertissements : c'est là que se voient les troncatures et les conversions.
    $warn = 0;
    $resql = $db->query("SELECT @@warning_count w");
    if ($resql && ($obj = $db->fetch_object($resql))) {
        $warn = (int) $obj->w;
    }

    $loaded = 0;
    $resql = $db->query("SELECT COUNT(*) n FROM `".$database."`.`".$table."`");
    if ($resql && ($obj = $db->fetch_object($resql))) {
        $loaded = (int) $obj->n;
    }
    $totalRows += $loaded;

    // Index d'analyse, une fois les lignes en place.
    $idx = 0;
    if ($makeIndex && !$useModel && $loaded > 0) {
        foreach ($indexCandidates as $cand) {
            foreach ($cols as $c) {
                if (strcasecmp($c, $cand) !== 0) {
                    continue;
                }
                if ($db->query("CREATE INDEX `idx_".$table."_".$c."` ON `".$database."`.`".$table."` (`".$c."`)")) {
                    $idx++;
                }
                break;
            }
        }
    }

    $duration = microtime(true) - $start;

    $suffix = '';
    if ($rowsFile !== null && $rowsFile !== $loaded) {
        $suffix .= '  ÉCART fichier '.$rowsFile;
    }
    if ($warn > 0) {
        $suffix .= '  '.$warn.' avertissement(s)';
    }
    if ($ragged > 0) {
        $suffix .= '  '.$ragged.' ligne(s) de largeur inattendue';
    }
    if ($idx > 0) {
        $suffix .= '  '.$idx.' index';
    }
    $suffix .= $collationNote;

    printf("%10s lignes  %6.1f s  %-12s%s\n", number_format($loaded, 0, ',', ' '),
        $duration, $origine, $suffix);

    $report[$table] = array(
        'etat'    => 'chargée',
        'origine' => $origine,
        'lignes'  => $loaded,
        'fichier' => $rowsFile,
        'warn'    => $warn,
        'ragged'  => $ragged,
        'duree'   => $duration,
    );
}

// Remise en état du mode SQL de la session.
if ($sqlModeBackup !== null) {
    $db->query("SET SESSION sql_mode = '".$db->escape($sqlModeBackup)."'");
}


/*
 * Récapitulatif
 */

echo str_repeat('=', 100)."\n";

$chargees = $vides = $ecarts = $avertissements = $echecs = 0;
foreach ($report as $table => $r) {
    if ($r['etat'] === 'chargée') {
        $chargees++;
        if ((int) $r['lignes'] === 0) {
            $vides++;
        }
        if ($r['fichier'] !== null && $r['fichier'] !== $r['lignes']) {
            $ecarts++;
        }
        if (!empty($r['warn'])) {
            $avertissements++;
        }
    } elseif ($r['etat'] !== 'simulé' && $r['etat'] !== 'conservée') {
        $echecs++;
    }
}

echo "Tables chargées : ".$chargees." (dont ".$vides." sans aucune ligne)\n";
echo "Lignes          : ".number_format($totalRows, 0, ',', ' ')."\n";
echo "Durée           : ".round(microtime(true) - $startAll, 1)." s\n";

if ($ecarts > 0) {
    echo "\nÉcart entre le fichier et la table (".$ecarts."), à regarder de près :\n";
    foreach ($report as $table => $r) {
        if ($r['etat'] === 'chargée' && $r['fichier'] !== null && $r['fichier'] !== $r['lignes']) {
            echo "  ".str_pad($table, 40)." fichier ".$r['fichier']."  table ".$r['lignes']."\n";
        }
    }
}

if ($avertissements > 0) {
    echo "\nAvertissements au chargement (".$avertissements." table(s)) :\n";
    foreach ($report as $table => $r) {
        if ($r['etat'] === 'chargée' && !empty($r['warn'])) {
            echo "  ".str_pad($table, 40).$r['warn']."\n";
        }
    }
    echo "  Un avertissement signale une valeur convertie ou tronquée. Sur une colonne\n";
    echo "  inférée, relancer la table seule avec --sample=0 lève le doute.\n";
}

if ($echecs > 0) {
    echo "\nÉchecs (".$echecs.") :\n";
    foreach ($report as $table => $r) {
        if ($r['etat'] !== 'chargée' && $r['etat'] !== 'simulé' && $r['etat'] !== 'conservée') {
            echo "  ".str_pad($table, 40).$r['etat']
                .(isset($r['message']) ? ' — '.$r['message'] : '')."\n";
        }
    }
}

$db->close();

exit($echecs > 0 ? 1 : 0);
