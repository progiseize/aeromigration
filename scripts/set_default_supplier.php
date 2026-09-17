<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/set_default_supplier.php
 * \ingroup aeromigration
 * \brief   Reprise du « fournisseur principal » ADD : l'étoile du tarif par défaut (aerotoolbox).
 *
 * ------------------------------------------------------------------------------
 * CIBLE
 * ------------------------------------------------------------------------------
 *
 * aerotoolbox 1.45.0 marque UN tarif fournisseur par article comme tarif par défaut :
 * extrafield booléen `aerotb_default` de `llx_product_fournisseur_price_extrafields`. La Vue 360°
 * et l'onglet Prix d'achat l'affichent en étoile pleine, et le réappro le retient comme
 * « meilleur » tarif. Le script n'écrit pas ce SQL lui-même : il passe par
 * `aerotb_price_default_set()` (lib/aeroreappro.lib.php), qui remet le produit à plat — une
 * seule étoile — et crée la ligne d'extrafields au besoin, en transaction.
 *
 * ------------------------------------------------------------------------------
 * SOURCE
 * ------------------------------------------------------------------------------
 *
 * `f_artfourniss` (Sage, table importée) : une ligne par couple article × fournisseur,
 * `AF_Principal = 1` marque le principal (15 406 lignes, jamais deux par article).
 * Rapprochement : `AR_Ref` → `llx_product.ref_ext = 'SAGE:'+AR_Ref`, `CT_Num` →
 * `llx_societe.ref_ext = 'SAGE:'+CT_Num`, puis le tarif Dolibarr de ce produit chez ce tiers ;
 * s'il y a plusieurs paliers, celui de la plus petite quantité (79 produits).
 *
 * ## Les déclinaisons héritent du parent
 *
 * Les tarifs des déclinaisons (`#XXXXX-NNN`) ont été recopiés du parent le 14/09
 * (copy_supplier_prices_to_variants) : elles n'ont pas de ligne dans `f_artfourniss`, mais leur
 * fournisseur principal est celui du parent. Seconde passe, désactivable par `--no-variants`.
 *
 * ## Ce qui a déjà été posé à la main est respecté
 *
 * Par défaut, un produit qui porte déjà une étoile n'est pas touché (compté « déjà posé » si
 * c'est le même tarif, « posé à la main, conservé » sinon). `--force` réaligne tout sur ADD.
 *
 * Usage :
 *   php set_default_supplier.php                 simulation (rapport complet), rien d'écrit
 *   php set_default_supplier.php --confirm       applique
 *   php set_default_supplier.php --force         écrase aussi les étoiles posées à la main
 *   php set_default_supplier.php --no-variants   sans la passe déclinaisons
 *   php set_default_supplier.php --ref=#00175    un seul article (et ses déclinaisons)
 *   php set_default_supplier.php --limit=N --confirm    lot d'essai
 *   php set_default_supplier.php --source-db=BASE   base où est f_artfourniss (défaut : AEROMIG_SOURCE_DB, sinon celle de Dolibarr)
 *   php set_default_supplier.php --user=LOGIN
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
dol_include_once('/aerotoolbox/lib/aeroreappro.lib.php');

$langs->loadLangs(array('admin'));

if (!function_exists('aerotb_price_default_set')) {
    echo "aerotoolbox 1.45.0 ou plus est requis (helper aerotb_price_default_set introuvable).\n";
    exit(1);
}

/** Nombre d'exemples listés par catégorie. */
const SAMPLES = 8;


/*
 * Arguments
 */

$confirm    = false;
$force      = false;
$variants   = true;
$limit      = 0;
$userLogin  = '';
$onlyRef    = '';
$sourceDb   = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--no-variants') {
        $variants = false;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--ref=(.+)$/', $arg, $m)) {
        $onlyRef = trim($m[1]);
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--force] [--no-variants] [--ref=REF] [--limit=N] [--source-db=BASE] [--user=LOGIN]\n";
        exit(1);
    }
}


/*
 * Utilisateur (pour la trace ; le helper n'en a pas besoin)
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
 * La base source : --source-db, sinon la constante du module, sinon celle de Dolibarr.
 */

if ($sourceDb === null) {
    $sourceDb = trim(getDolGlobalString('AEROMIG_SOURCE_DB', ''));
}
if ($sourceDb === $db->database_name) {
    $sourceDb = '';
}
$srcTable = ($sourceDb !== '' ? '`'.$db->escape($sourceDb).'`.' : '').'f_artfourniss';
$resql = $db->query('SELECT 1 FROM '.$srcTable.' LIMIT 1');
if (!$resql) {
    echo "Table source introuvable : ".$srcTable." — ".$db->lasterror()."\n";
    echo "Précisez --source-db=BASE (ou la constante AEROMIG_SOURCE_DB).\n";
    exit(1);
}


/*
 * Index en mémoire : ref_ext n'est indexé ni sur les produits ni sur les tiers, une jointure
 * directe balaie 161 000 tiers par ligne source. Trois lectures ensemblistes, puis le
 * rapprochement se fait en PHP.
 */

$prodBySage = array();   // 'SAGE:00175' -> array(rowid, ref)
$resql = $db->query('SELECT rowid, ref, ref_ext FROM '.MAIN_DB_PREFIX.'product'
    ." WHERE entity IN (".getEntity('product').") AND ref_ext LIKE 'SAGE:%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $prodBySage[trim($o->ref_ext)] = array((int) $o->rowid, $o->ref);
}
if ($resql) {
    $db->free($resql);
}

$socBySage = array();    // 'SAGE:F18' -> array(rowid, nom)
$resql = $db->query('SELECT rowid, nom, ref_ext FROM '.MAIN_DB_PREFIX.'societe'
    ." WHERE entity IN (".getEntity('societe').") AND ref_ext LIKE 'SAGE:%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $socBySage[trim($o->ref_ext)] = array((int) $o->rowid, $o->nom);
}
if ($resql) {
    $db->free($resql);
}

// Tarifs par (produit, fournisseur) : le plus petit palier d'abord, le premier lu l'emporte.
$priceByPair = array();  // "pid|sid" -> price rowid
$resql = $db->query('SELECT rowid, fk_product, fk_soc FROM '.MAIN_DB_PREFIX.'product_fournisseur_price'
    .' WHERE entity IN ('.getEntity('productsupplierprice').') ORDER BY fk_product, fk_soc, quantity, rowid');
while ($resql && ($o = $db->fetch_object($resql))) {
    $k = ((int) $o->fk_product).'|'.((int) $o->fk_soc);
    if (!isset($priceByPair[$k])) {
        $priceByPair[$k] = (int) $o->rowid;
    }
}
if ($resql) {
    $db->free($resql);
}


/*
 * Lecture de la source : le principal ADD, rapproché du produit, du tiers et du tarif.
 */

$targets = array();   // pid -> array(price_id, sid, ref, nom)
$stats = array(
    'principaux'        => 0,
    'article_absent'    => 0,
    'tiers_absent'      => 0,
    'tarif_absent'      => 0,
    'poses'             => 0,
    'deja'              => 0,
    'manuel_conserve'   => 0,
    'ecrases'           => 0,   // --force : étoile manuelle remplacée
    'var_poses'         => 0,
    'var_deja'          => 0,
    'var_sans_tarif'    => 0,   // déclinaison sans tarif chez le fournisseur du parent
    'var_manuel'        => 0,
    'erreur'            => 0,
);
$samples = array('article_absent' => array(), 'tiers_absent' => array(), 'tarif_absent' => array(),
    'manuel_conserve' => array(), 'erreur' => array());

$resql = $db->query('SELECT AR_Ref, CT_Num FROM '.$srcTable.' WHERE AF_Principal = 1 ORDER BY AR_Ref');
if (!$resql) {
    echo "Lecture impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($o = $db->fetch_object($resql)) {
    $stats['principaux']++;
    $refSage = 'SAGE:'.trim((string) $o->AR_Ref);
    $socSage = 'SAGE:'.trim((string) $o->CT_Num);

    if (!isset($prodBySage[$refSage])) {
        $stats['article_absent']++;
        if (count($samples['article_absent']) < SAMPLES) {
            $samples['article_absent'][] = trim((string) $o->AR_Ref);
        }
        continue;
    }
    list($pid, $ref) = $prodBySage[$refSage];
    if ($onlyRef !== '' && $ref !== $onlyRef) {
        continue;
    }
    if (!isset($socBySage[$socSage])) {
        $stats['tiers_absent']++;
        if (count($samples['tiers_absent']) < SAMPLES) {
            $samples['tiers_absent'][] = $ref.' → '.trim((string) $o->CT_Num);
        }
        continue;
    }
    list($sid, $nom) = $socBySage[$socSage];
    if (!isset($priceByPair[$pid.'|'.$sid])) {
        $stats['tarif_absent']++;
        if (count($samples['tarif_absent']) < SAMPLES) {
            $samples['tarif_absent'][] = $ref.' chez '.$nom;
        }
        continue;
    }
    $targets[$pid] = array('price_id' => $priceByPair[$pid.'|'.$sid], 'sid' => $sid, 'ref' => $ref, 'nom' => $nom);
}
$db->free($resql);


/*
 * Les étoiles déjà en place, produit par produit (une requête).
 */

$current = array();   // pid -> price_id étoilé
$resql = $db->query('SELECT pfp.fk_product, pe.fk_object FROM '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields AS pe'
    .' INNER JOIN '.MAIN_DB_PREFIX.'product_fournisseur_price AS pfp ON pfp.rowid = pe.fk_object'
    .' WHERE pe.aerotb_default = 1');
while ($resql && ($o = $db->fetch_object($resql))) {
    $current[(int) $o->fk_product] = (int) $o->fk_object;
}
if ($resql) {
    $db->free($resql);
}


/*
 * Passe 1 : les articles.
 */

echo "Script      : fournisseur principal ADD → tarif par défaut (étoile aerotoolbox)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)").($force ? ' — --force : les étoiles posées à la main sont réalignées' : '')."\n";
if ($sourceDb !== '') {
    echo "Base source : ".$sourceDb."\n";
}
echo str_repeat('-', 60)."\n";

/**
 * Pose l'étoile sur un tarif si nécessaire. Compte dans $stats sous les clés données.
 *
 * @param  int    $pid      Produit
 * @param  int    $priceId  Tarif visé
 * @param  string $label    Réf pour les exemples
 * @param  string $kPose    Clé stats « posé »
 * @param  string $kDeja    Clé stats « déjà »
 * @param  string $kManuel  Clé stats « manuel conservé »
 * @return bool             Vrai si une écriture a eu lieu (ou aurait eu lieu)
 */
function star_apply($pid, $priceId, $label, $kPose, $kDeja, $kManuel)
{
    global $db, $current, $stats, $samples, $confirm, $force;

    if (isset($current[$pid])) {
        if ($current[$pid] === $priceId) {
            $stats[$kDeja]++;
            return false;
        }
        if (!$force) {
            $stats[$kManuel]++;
            if ($kManuel === 'manuel_conserve' && count($samples['manuel_conserve']) < SAMPLES) {
                $samples['manuel_conserve'][] = $label;
            }
            return false;
        }
        $stats['ecrases']++;
    }
    $stats[$kPose]++;
    if ($confirm) {
        if (!aerotb_price_default_set($db, $pid, $priceId, true)) {
            $stats[$kPose]--;
            $stats['erreur']++;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $label.' : '.$db->lasterror();
            }
            return false;
        }
        $current[$pid] = $priceId;
    }

    return true;
}

$writes = 0;
foreach ($targets as $pid => $t) {
    if (star_apply($pid, $t['price_id'], $t['ref'], 'poses', 'deja', 'manuel_conserve')) {
        $writes++;
        if ($limit > 0 && $writes >= $limit) {
            echo "Limite atteinte (".$limit.").\n";
            break;
        }
    }
}


/*
 * Passe 2 : les déclinaisons héritent du fournisseur principal du parent — leur tarif chez ce
 * tiers (le plus petit palier), s'il existe.
 */

if ($variants && !($limit > 0 && $writes >= $limit)) {
    $sql  = 'SELECT pac.fk_product_parent AS parent, pac.fk_product_child AS child, c.ref AS cref';
    $sql .= ' FROM '.MAIN_DB_PREFIX.'product_attribute_combination AS pac';
    $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS c ON c.rowid = pac.fk_product_child';
    $sql .= ' WHERE pac.entity IN ('.getEntity('product').')';
    if ($onlyRef !== '') {
        $sql .= ' AND pac.fk_product_parent IN (SELECT rowid FROM '.MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($onlyRef)."')";
    }
    $sql .= ' ORDER BY c.ref';

    $children = array();
    $resql = $db->query($sql);
    while ($resql && ($o = $db->fetch_object($resql))) {
        $children[] = $o;
    }
    if ($resql) {
        $db->free($resql);
    }

    foreach ($children as $ch) {
        $parent = (int) $ch->parent;
        if (!isset($targets[$parent])) {
            continue;   // parent sans principal ADD : rien à hériter
        }
        $sid = $targets[$parent]['sid'];
        $k   = ((int) $ch->child).'|'.$sid;
        if (!isset($priceByPair[$k])) {
            $stats['var_sans_tarif']++;
            continue;
        }
        if (star_apply((int) $ch->child, $priceByPair[$k], $ch->cref, 'var_poses', 'var_deja', 'var_manuel')) {
            $writes++;
            if ($limit > 0 && $writes >= $limit) {
                echo "Limite atteinte (".$limit.").\n";
                break;
            }
        }
    }
}


/*
 * Rapport
 */

printf("Principaux ADD lus                : %s\n", number_format($stats['principaux'], 0, ',', ' '));
printf("Articles — étoiles %s : %s\n", $confirm ? 'posées   ' : 'à poser  ', number_format($stats['poses'], 0, ',', ' '));
printf("Articles — déjà sur ce tarif      : %s\n", number_format($stats['deja'], 0, ',', ' '));
if ($force) {
    printf("  dont étoiles manuelles réalignées : %s\n", number_format($stats['ecrases'], 0, ',', ' '));
} else {
    printf("Articles — posée à la main, conservée : %s%s\n", number_format($stats['manuel_conserve'], 0, ',', ' '),
        $samples['manuel_conserve'] ? ' — '.implode(', ', $samples['manuel_conserve']) : '');
}
if ($variants) {
    printf("Déclinaisons — étoiles %s : %s\n", $confirm ? 'posées ' : 'à poser', number_format($stats['var_poses'], 0, ',', ' '));
    printf("Déclinaisons — déjà sur ce tarif  : %s\n", number_format($stats['var_deja'], 0, ',', ' '));
    printf("Déclinaisons — posée à la main    : %s\n", number_format($stats['var_manuel'], 0, ',', ' '));
    printf("Déclinaisons — sans tarif chez le fournisseur du parent : %s\n", number_format($stats['var_sans_tarif'], 0, ',', ' '));
}

echo "\nÉcarts (rien d'écrit) :\n";
$ecarts = array(
    'article_absent' => 'principal ADD sans article repris',
    'tiers_absent'   => 'fournisseur ADD sans tiers repris',
    'tarif_absent'   => 'aucun tarif de l\'article chez ce fournisseur',
    'erreur'         => 'écriture refusée',
);
foreach ($ecarts as $key => $libelle) {
    printf("  %7s  %s%s\n", number_format($stats[$key], 0, ',', ' '), $libelle,
        (!empty($samples[$key])) ? ' — '.implode(', ', $samples[$key]) : '');
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit($stats['erreur'] > 0 ? 1 : 0);
