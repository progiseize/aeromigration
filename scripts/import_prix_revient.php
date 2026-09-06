<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_prix_revient.php
 * \ingroup aeromigration
 * \brief   Pose le prix de revient (cost_price) des produits depuis le fichier client.
 *
 * ------------------------------------------------------------------------------
 * D'OÙ VIENT LA DONNÉE
 * ------------------------------------------------------------------------------
 *
 * Le client a valorisé tout son catalogue (16 008 lignes) et livré
 * `migrationdata/prix_revient_migration.csv` : pour chaque article, le prix de revient 2026.
 * ADD ne portait pas cette information de façon fiable — le fichier fait foi.
 *
 * Format (Windows-1252, séparateur « ; », en-tête) :
 *
 *     Numero produit;Libelle produit;Fournisseur;Réf fournisseur;EAN;Prix revient 2026
 *
 * Seules deux colonnes sont lues : la référence (PADÉE À CINQ CHIFFRES par l'outil
 * d'export, là où `ref_ext` ne l'est pas — appariement canonique, zéros de tête
 * neutralisés) et le prix, saisi « à la française » (« 69,92 € », virgule décimale,
 * symbole €, éventuel séparateur de milliers).
 *
 * ## Les prix à zéro (2 828 lignes « 0,00 € ») — arbitrage client du 06/09/2026
 *
 * Un montant à zéro est VOULU : soit l'article n'a pas de stock (la valorisation se fera
 * au premier réapprovisionnement), soit c'est du vieux stock déprécié — un prix de revient
 * nul est alors le bon. Les zéros sont donc posés tels quels, et comptés à part au rapport.
 * (Les « 1 885 zéros » de l'analyse du 03/09 excluaient emplacements et articles
 * techniques ; le fichier en compte 2 828 en tout, dont 395 sur la série 90000+.)
 *
 * ## Deux populations connues du fichier, traitées sans bruit
 *
 * - **115 montants vides, tous « PRODUIT COMPOSE »** : le client n'a pas valorisé les
 *   kits — leur coût découle des composants. Rien d'écrit, compté à part.
 * - **395 emplacements (série 90000+)** : supprimés par import_disposuivi (arbitrage du
 *   03/09). Leur absence est l'état voulu — comptée comme telle, pas comme un écart.
 *
 * ## Écriture et idempotence
 *
 * La cible est `llx_product.cost_price`, écrite par `setValueFrom()` — PAS par
 * `Product::update()` : le update complet déclenche PRODUCT_MODIFY (synchronisation
 * boutique) et `fetch()` recharge les sept niveaux de prix, ce qui rendait déjà
 * interminable le premier jet d'import_disposuivi. Ici : une lecture d'index en une
 * requête, puis un UPDATE ciblé par produit à modifier.
 *
 * Une valeur déjà conforme (au demi-centime près) n'est pas réécrite : le script est
 * rejouable. Une valeur DIFFÉRENTE déjà en place est REMPLACÉE — le fichier client fait
 * foi. Comme les autres scripts sur fichier client, il se rejoue après tout rejeu de
 * `product`.
 *
 * Usage :
 *   php import_prix_revient.php                  simulation : ventilation complète, rien d'écrit
 *   php import_prix_revient.php --confirm        applique
 *   php import_prix_revient.php --limit=20 --confirm     lot d'essai
 *   php import_prix_revient.php --file=/chemin/vers/fichier.csv
 *   php import_prix_revient.php --user=LOGIN
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

$langs->loadLangs(array('admin'));

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** Nombre d'exemples listés par catégorie d'écart. */
const SAMPLES = 8;

/** Écart en euros en deçà duquel une valeur en place est considérée conforme. */
const EPSILON = 0.005;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$file      = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--file=CSV] [--user=LOGIN]\n";
        exit(1);
    }
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
 * Le fichier : le plus récent de migrationdata/, sauf --file.
 */

if ($file === '') {
    $candidates = glob(__DIR__.'/../migrationdata/prix_revient_*.csv');
    if (empty($candidates)) {
        echo "Aucun fichier migrationdata/prix_revient_*.csv trouvé. Précisez --file=.\n";
        exit(1);
    }
    sort($candidates);
    $file = end($candidates);
}
if (!is_readable($file)) {
    echo "Fichier illisible : ".$file."\n";
    exit(1);
}


/**
 * Référence ADD canonique : zéros de tête neutralisés — l'export client pade à cinq
 * chiffres (« 00456 ») ce que `ref_ext` porte sans zéros (« 456 »).
 *
 * @param  string $ref Référence brute
 * @return string
 */
function canon_ref($ref)
{
    $ref = strtolower(trim((string) $ref));
    $c = ltrim($ref, '0');

    return ($c === '') ? $ref : $c;
}

/**
 * Montant « à la française » -> float : « 1 234,56 € » devient 1234.56.
 * Renvoie null quand, une fois l'habillage retiré, il ne reste pas un nombre.
 *
 * @param  string $raw Montant brut (déjà en UTF-8)
 * @return float|null
 */
function parse_price_fr($raw)
{
    // Ne garder que chiffres, virgule, point et signe : € , espaces (dont insécables) et
    // tout autre habillage tombent d'un bloc.
    $s = preg_replace('/[^0-9,.\-]/u', '', (string) $raw);
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    return (float) $s;
}


/*
 * Index en mémoire : référence canonique -> (rowid, cost_price courant) ; -1 si deux
 * produits se disputent la même référence canonique — jamais deviné, écarté et compté.
 * La valeur courante est préchargée : la comparaison se fait en mémoire, sans recharger
 * 16 000 produits.
 */

$prodByRef = array();
$resql = $db->query('SELECT p.rowid, p.ref_ext, p.cost_price'
    .' FROM '.MAIN_DB_PREFIX.'product as p'
    ." WHERE p.entity IN (".getEntity('product').") AND p.ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = canon_ref(substr($o->ref_ext, strlen(REF_EXT_PREFIX)));
    if ($key !== '') {
        $prodByRef[$key] = isset($prodByRef[$key]) ? -1
            : array((int) $o->rowid, ($o->cost_price === null) ? null : (float) $o->cost_price);
    }
}
if ($resql) {
    $db->free($resql);
}


/*
 * Parcours du fichier.
 */

$stats = array(
    'lignes'         => 0,
    'poses'          => 0,
    'zeros_poses'    => 0,
    'zeros_fichier'  => 0,
    'deja'           => 0,
    'kits_vides'     => 0,
    'empl_absents'   => 0,
    'produit_absent' => 0,
    'ref_ambigue'    => 0,
    'prix_invalide'  => 0,
    'erreur'         => 0,
);
$samples = array('produit_absent' => array(), 'ref_ambigue' => array(), 'prix_invalide' => array(),
    'erreur' => array());

$total = max(0, count(file($file, FILE_SKIP_EMPTY_LINES)) - 1);

$fh = fopen($file, 'r');
if (!$fh) {
    echo "Ouverture impossible : ".$file."\n";
    exit(1);
}
fgetcsv($fh, 0, ';');   // en-tête, ignoré — l'ordre des colonnes est le contrat

/**
 * Trace d'avancement, réécrite sur place : sans elle, 16 000 lignes semblent bloquées.
 *
 * @param array<string,int> $stats Compteurs courants
 * @param int               $total Lignes attendues
 * @return void
 */
function show_progress($stats, $total)
{
    printf("\r  %s/%s lignes   posés %s (dont zéros %s)   conformes %s   erreurs %s",
        str_pad(number_format($stats['lignes'], 0, ',', ' '), 6, ' ', STR_PAD_LEFT),
        number_format($total, 0, ',', ' '),
        number_format($stats['poses'], 0, ',', ' '),
        number_format($stats['zeros_poses'], 0, ',', ' '),
        number_format($stats['deja'], 0, ',', ' '),
        number_format($stats['erreur'], 0, ',', ' '));
    flush();
}

echo "Script      : prix de revient des produits (fichier client)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Fichier     : ".$file."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

$writes = 0;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 6) {
        continue;
    }
    $stats['lignes']++;
    if ($stats['lignes'] % 500 === 0) {
        show_progress($stats, $total);
    }

    // Le fichier est en Windows-1252 : converti avant lecture — le symbole € (0x80 en
    // 1252) rendrait le montant illisible en UTF-8 brut.
    $refAdd = canon_ref(mb_convert_encoding($row[0], 'UTF-8', 'Windows-1252'));
    $rawPrice = trim(mb_convert_encoding($row[5], 'UTF-8', 'Windows-1252'));
    $price  = parse_price_fr($rawPrice);

    if ($price === null) {
        // Montant vide = produit composé non valorisé par le client (le coût découle des
        // composants) : rien à écrire, c'est l'état voulu. Tout autre illisible est un écart.
        if ($rawPrice === '') {
            $stats['kits_vides']++;
            continue;
        }
        $stats['prix_invalide']++;
        if (count($samples['prix_invalide']) < SAMPLES) {
            $samples['prix_invalide'][] = $refAdd.' « '.$rawPrice.' »';
        }
        continue;
    }
    if ($price == 0.0) {
        // Arbitrage client du 06/09/2026 : zéro = pas de stock (valorisation au premier
        // réappro) ou vieux stock déprécié — le zéro est la bonne valeur, posé tel quel.
        $stats['zeros_fichier']++;
    }

    // ── Résolution du produit ──────────────────────────────────────────────
    if (!isset($prodByRef[$refAdd])) {
        if (ctype_digit($refAdd) && (int) $refAdd >= 90000) {
            // Série 90000+ : les emplacements supprimés par import_disposuivi — leur
            // absence est l'état voulu, pas un écart.
            $stats['empl_absents']++;
            continue;
        }
        $stats['produit_absent']++;
        if (count($samples['produit_absent']) < SAMPLES) {
            $samples['produit_absent'][] = $refAdd;
        }
        continue;
    }
    if ($prodByRef[$refAdd] === -1) {
        $stats['ref_ambigue']++;
        if (count($samples['ref_ambigue']) < SAMPLES) {
            $samples['ref_ambigue'][] = $refAdd;
        }
        continue;
    }
    list($prodId, $curCost) = $prodByRef[$refAdd];

    // ── Comparaison : conforme au demi-centime près, rien à réécrire ────────
    if ($curCost !== null && abs($curCost - $price) < EPSILON) {
        $stats['deja']++;
        continue;
    }

    $stats['poses']++;
    if ($price == 0.0) {
        $stats['zeros_poses']++;
    }
    $writes++;

    if ($confirm) {
        // setValueFrom, PAS update() : le update complet déclenche PRODUCT_MODIFY
        // (synchronisation boutique) pour un simple prix de revient, et imposerait un
        // fetch() par produit.
        $product = new Product($db);
        $product->id = $prodId;
        if ($product->setValueFrom('cost_price', price2num($price), '', null, 'text', '', $user) < 0) {
            $stats['erreur']++;
            $stats['poses']--;
            if ($price == 0.0) {
                $stats['zeros_poses']--;
            }
            $writes--;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $refAdd.' : '.$product->error;
            }
            continue;
        }
    }

    if ($limit > 0 && $writes >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}
fclose($fh);
show_progress($stats, $total);
echo "\n";

if ($confirm) {
    if ($stats['erreur'] > 0) {
        $db->rollback();
        echo "\nÉCHEC — ".$stats['erreur']." erreur(s), tout est annulé (transaction) :\n";
        foreach ($samples['erreur'] as $e) {
            echo "  - ".$e."\n";
        }
        exit(1);
    }
    $db->commit();
}

printf("\nLignes lues                : %s\n", number_format($stats['lignes'], 0, ',', ' '));
printf("Prix de revient %s : %s\n", $confirm ? 'posés ' : 'à poser', number_format($stats['poses'], 0, ',', ' '));
printf("  dont zéros (arbitrage client : pas de stock ou stock déprécié) : %s\n",
    number_format($stats['zeros_poses'], 0, ',', ' '));
printf("Produits déjà conformes    : %s\n", number_format($stats['deja'], 0, ',', ' '));
printf("Zéros dans le fichier      : %s (attendu 2 828)\n", number_format($stats['zeros_fichier'], 0, ',', ' '));

echo "\nCas connus, rien d'écrit :\n";
printf("  %7s  produit(s) composé(s) sans montant (coût = composants)\n", number_format($stats['kits_vides'], 0, ',', ' '));
printf("  %7s  emplacement(s) 90000+ absents (supprimés par import_disposuivi)\n", number_format($stats['empl_absents'], 0, ',', ' '));

$ecarts = array(
    'produit_absent' => 'référence sans produit repris',
    'ref_ambigue'    => 'référence canonique portée par plusieurs produits',
    'prix_invalide'  => 'montant illisible',
    'erreur'         => 'écriture refusée',
);
$shown = false;
foreach ($ecarts as $key => $libelle) {
    if ($stats[$key] > 0) {
        if (!$shown) {
            echo "\nÉcarts :\n";
            $shown = true;
        }
        printf("  %s  %s%s\n", str_pad(number_format($stats[$key], 0, ',', ' '), 7, ' ', STR_PAD_LEFT), $libelle,
            (!empty($samples[$key])) ? ' — '.implode(', ', $samples[$key]) : '');
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['erreur'] > 0 ? 1 : 0);
