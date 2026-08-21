<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/set_alert_stock.php
 * \ingroup aeromigration
 * \brief   Pose un stock d'alerte calculé sur les produits qui n'en ont pas.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI UNE VALEUR CALCULÉE
 * ------------------------------------------------------------------------------
 *
 * Le stock d'alerte (`llx_product.seuil_stock_alerte`) déclenche la liste des « produits à
 * commander prioritairement ». Dans l'ancien ERP, le champ (`f_artstock.AS_QteMini`) n'est
 * renseigné que sur 926 articles — des valeurs choisies à la main, que la reprise a déjà
 * posées. Pour le reste du catalogue, laisser zéro rend la liste muette ; poser une valeur
 * uniforme la noierait. Le client a arbitré (26/08/2026) : UNE VALEUR CALCULÉE, affinée
 * ensuite au cas par cas.
 *
 *     seuil = arrondi SUPÉRIEUR de ( ventes 2026 / 8 × 2 )
 *
 * soit DEUX MOIS de ventes moyennes — les ventes sont les quantités nettes facturées dans
 * ADD sur l'année civile 2026 (le « /8 » correspond aux huit mois écoulés, la reprise étant
 * à deux semaines ; il est figé, arbitrage client). L'arrondi supérieur est voulu : prudent
 * sur du stock, tout article vendu au moins une fois obtient un seuil d'au moins 1.
 *
 * ## Ce que le script ne touche pas
 *
 * - les produits dont le seuil est DÉJÀ > 0 — les 926 valeurs humaines de l'ancien ERP,
 *   et tout ce qui aura été affiné depuis : seuls les VIDES sont comblés (arbitrage n° 1) ;
 * - les SERVICES, qui n'ont pas de stock ;
 * - la famille logistique, que rien ne stocke (même liste que `close_delivered_orders` :
 *   `PORT*`, `*RETRAIT*`, `TRANSPORT`, `ECART`, `EMBALLAGE`, `FRANCO`) — sans quoi la
 *   formule leur donnerait les plus gros seuils du catalogue (PORTSTD : 586) ;
 * - les articles sans vente 2026 : leur seuil reste zéro, ils n'alertent jamais.
 *
 * Les références s'apparient en forme CANONIQUE (zéros de tête neutralisés), comme le
 * catalogue client. Rejouable : un seuil posé au premier passage est « déjà renseigné »
 * au second.
 *
 * Usage :
 *   php set_alert_stock.php                  simulation : ventilation complète, rien d'écrit
 *   php set_alert_stock.php --confirm        applique
 *   php set_alert_stock.php --limit=20 --confirm     lot d'essai
 *   php set_alert_stock.php --source-db=NOM  base des tables ADD (défaut : AEROMIG_SOURCE_DB)
 *   php set_alert_stock.php --user=LOGIN
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

/** Bornes de la formule : ventes de l'année civile 2026, huit mois écoulés, deux mois visés. */
const SALES_FROM      = '2026-01-01';
const MONTHS_ELAPSED  = 8;
const MONTHS_COVERED  = 2;

/** Nombre d'exemples listés par catégorie d'écart. */
const SAMPLES = 8;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$sourceDb  = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--source-db=NOM] [--user=LOGIN]\n";
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
 * Base des tables source — même résolution que les scripts de reprise.
 */

if ($sourceDb === null) {
    $sourceDb = trim(getDolGlobalString('AEROMIG_SOURCE_DB', 'aeroprod'));
}
if ($sourceDb !== '' && $sourceDb === $db->database_name) {
    $sourceDb = '';
}

/**
 * Qualifie une table source.
 *
 * @param  string $table Nom de la table
 * @return string        Nom qualifié si la source est dans une autre base
 */
function src($table)
{
    global $sourceDb;

    return ($sourceDb === '') ? $table : '`'.$sourceDb.'`.'.$table;
}

if (!$db->query('SELECT 1 FROM '.src('f_docligne_global').' LIMIT 1', 1)) {
    echo "Table source inaccessible : ".src('f_docligne_global')."\n";
    echo "Précisez --source-db=NOM, ou --source-db= si les tables f_* sont dans la base de Dolibarr.\n";
    exit(1);
}

/**
 * Référence ADD canonique : zéros de tête neutralisés.
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
 * La référence appartient-elle à la famille logistique, que rien ne stocke ?
 * Même liste que `close_delivered_orders.php` (inventaire du 20/08/2026).
 *
 * @param  string $productRef Référence produit
 * @return bool
 */
function isLogisticsRef($productRef)
{
    $ref = strtoupper(trim($productRef));

    if ($ref === '' || strpos($ref, 'PORT') === 0 || strpos($ref, 'RETRAIT') !== false) {
        return true;
    }

    return in_array($ref, array('TRANSPORT', 'ECART', 'EMBALLAGE', 'FRANCO'), true);
}


/*
 * 1. Ventes nettes 2026 par référence canonique (factures et avoirs ADD).
 */

$salesByRef = array();
$resql = $db->query('SELECT TRIM(AR_Ref) as ref, SUM(DL_Qte) as qty FROM '.src('f_docligne_global')
    ." WHERE DO_Domaine = 0 AND DO_Type IN (6,7) AND TRIM(COALESCE(AR_Ref,'')) <> ''"
    ." AND DO_Date >= '".$db->escape(SALES_FROM)."'"
    .' GROUP BY TRIM(AR_Ref)');
if (!$resql) {
    echo "Lecture des ventes impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $key = canon_ref($obj->ref);
    $salesByRef[$key] = ($salesByRef[$key] ?? 0) + (float) $obj->qty;
}
$db->free($resql);


/*
 * 2. Parcours des produits repris.
 */

$stats = array(
    'produits'      => 0,
    'maj'           => 0,
    'conserve'      => 0,
    'service'       => 0,
    'logistique'    => 0,
    'sans_vente'    => 0,
    'vente_negative' => 0,
    'erreur'        => 0,
);
$tranches = array('1' => 0, '2-4' => 0, '5-19' => 0, '20+' => 0);
$samples  = array('logistique' => array(), 'vente_negative' => array(), 'erreur' => array(), 'maj' => array());

$resql = $db->query('SELECT rowid, ref, ref_ext, fk_product_type, seuil_stock_alerte FROM '.MAIN_DB_PREFIX.'product'
    ." WHERE entity IN (".getEntity('product').") AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'"
    .' ORDER BY rowid ASC');
if (!$resql) {
    echo "Lecture des produits impossible : ".$db->lasterror()."\n";
    exit(1);
}
$products = array();
while ($obj = $db->fetch_object($resql)) {
    $products[] = $obj;
}
$db->free($resql);

echo "Script      : stock d'alerte calculé (ventes 2026 / ".MONTHS_ELAPSED." x ".MONTHS_COVERED.", arrondi supérieur)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Source      : ".($sourceDb !== '' ? $sourceDb : $db->database_name)."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

$product = new Product($db);

foreach ($products as $p) {
    $stats['produits']++;
    $addRef = substr($p->ref_ext, strlen(REF_EXT_PREFIX));

    // ── Ce qui ne se stocke pas ────────────────────────────────────────────
    if ((int) $p->fk_product_type === Product::TYPE_SERVICE) {
        $stats['service']++;
        continue;
    }
    if (isLogisticsRef($addRef)) {
        $stats['logistique']++;
        if (count($samples['logistique']) < SAMPLES) {
            $samples['logistique'][] = $addRef;
        }
        continue;
    }

    // ── Un seuil déjà renseigné est une valeur humaine : conservé ──────────
    if ((float) $p->seuil_stock_alerte > 0) {
        $stats['conserve']++;
        continue;
    }

    // ── La formule ─────────────────────────────────────────────────────────
    $key = canon_ref($addRef);
    if (!isset($salesByRef[$key])) {
        $stats['sans_vente']++;
        continue;
    }
    $qty = $salesByRef[$key];
    if ($qty <= 0) {
        $stats['vente_negative']++;
        if (count($samples['vente_negative']) < SAMPLES) {
            $samples['vente_negative'][] = $addRef.' ('.$qty.')';
        }
        continue;
    }

    $seuil = (int) ceil($qty / MONTHS_ELAPSED * MONTHS_COVERED);

    $stats['maj']++;
    if ($seuil >= 20) {
        $tranches['20+']++;
    } elseif ($seuil >= 5) {
        $tranches['5-19']++;
    } elseif ($seuil >= 2) {
        $tranches['2-4']++;
    } else {
        $tranches['1']++;
    }
    if (count($samples['maj']) < SAMPLES) {
        $samples['maj'][] = $addRef.' ('.round($qty).' vendus -> '.$seuil.')';
    }

    if ($confirm) {
        $product->id = (int) $p->rowid;
        if ($product->setValueFrom('seuil_stock_alerte', $seuil, '', null, '', '', $user) < 0) {
            $stats['erreur']++;
            $stats['maj']--;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $addRef.' : '.$product->error;
            }
        }
    }

    if ($limit > 0 && $stats['maj'] >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}

if ($confirm) {
    $db->commit();
}

printf("\nProduits repris parcourus : %s\n", number_format($stats['produits'], 0, ',', ' '));
printf("Seuils %s : %s\n", $confirm ? 'posés  ' : 'à poser', number_format($stats['maj'], 0, ',', ' '));
printf("  seuil 1      : %s\n", number_format($tranches['1'], 0, ',', ' '));
printf("  seuil 2-4    : %s\n", number_format($tranches['2-4'], 0, ',', ' '));
printf("  seuil 5-19   : %s\n", number_format($tranches['5-19'], 0, ',', ' '));
printf("  seuil 20+    : %s\n", number_format($tranches['20+'], 0, ',', ' '));
if (!empty($samples['maj'])) {
    echo "  ex. : ".implode(', ', $samples['maj'])."\n";
}

$ecarts = array(
    'conserve'       => 'seuil déjà renseigné, conservé (valeur humaine)',
    'sans_vente'     => 'aucune vente 2026 : seuil laissé à zéro',
    'vente_negative' => 'ventes nettes négatives ou nulles (avoirs) : laissé à zéro',
    'service'        => 'service, pas de stock',
    'logistique'     => 'famille logistique, rien à stocker',
    'erreur'         => 'écriture refusée',
);
echo "\nHors périmètre :\n";
foreach ($ecarts as $key => $libelle) {
    if ($stats[$key] > 0) {
        printf("  %s  %s%s\n", str_pad(number_format($stats[$key], 0, ',', ' '), 7, ' ', STR_PAD_LEFT), $libelle,
            (!empty($samples[$key])) ? ' — '.implode(', ', $samples[$key]) : '');
    }
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['erreur'] > 0 ? 1 : 0);
