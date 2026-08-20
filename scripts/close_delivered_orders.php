<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/close_delivered_orders.php
 * \ingroup aeromigration
 * \brief   Classe « Livrée » les commandes que les expéditions reprises couvrent en totalité.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CE SCRIPT, ALORS QUE LE COEUR SAIT CLÔTURER UNE COMMANDE LIVRÉE
 * ------------------------------------------------------------------------------
 *
 * À la clôture d'une expédition, `Expedition::setClosed()` clôture la commande d'origine si
 * les quantités expédiées recouvrent les quantités commandées — mais sa comparaison exige que
 * TOUTE ligne de type produit soit couverte (expedition.class.php:2905), lignes de texte libre
 * comprises. Or les commandes portent presque toutes une ligne que rien n'expédie jamais :
 * l'article « Transport » des commandes de la boutique, les pseudo-articles `PORT*`/`RETRAIT*`
 * des commandes de l'ancien ERP, les mentions en texte libre. Résultat mesuré le 20/08/2026 :
 * 2 569 commandes clôturées par la règle du coeur, là où **27 511 sur 27 790** sont
 * intégralement livrées dès qu'on ne compte plus la logistique.
 *
 * Le script `shipment` restaure de surcroît le statut posé par `customerorder` après chaque
 * clôture — voulu pour ne pas réécrire l'histoire, mais il annule aussi les rares clôtures
 * légitimes du coeur. Ce script applique donc la règle métier À PART, après coup :
 *
 * **une commande est « Livrée » quand toutes ses lignes d'articles sont couvertes par les
 * expéditions reprises clôturées** — en ignorant ce qui ne s'expédie pas : le texte libre,
 * les services, et la famille logistique (`Transport`, `PORT*`, `*RETRAIT*`, `ECART`,
 * `EMBALLAGE`, `FRANCO`).
 *
 * Ne sont regardées que les commandes en statut « validée » (1) ou « expédition en cours »
 * (2) : les fermées le sont déjà, les annulées et les brouillons ne se touchent pas. Les
 * livraisons réellement partielles (279 au premier passage) sont listées, jamais forcées.
 * L'écriture passe par `Commande::cloture()`, l'API du coeur ; l'agenda automatique est coupé
 * en mémoire le temps du passage, comme dans les reprises.
 *
 * Rejouable : une commande déjà « Livrée » sort du périmètre au passage suivant.
 *
 * Usage :
 *   php close_delivered_orders.php             dénombre et détaille, sans rien écrire
 *   php close_delivered_orders.php --confirm   applique
 *   php close_delivered_orders.php --limit=100 --confirm   par petits lots
 *   php close_delivered_orders.php --user=LOGIN
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
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

$langs->loadLangs(array('admin'));

/** Préfixe des ref_ext posés par la reprise — même valeur que AeroMigrationRunner. */
const REF_EXT_PREFIX = 'SAGE:';

/** Tolérance de comparaison des quantités. */
const QTY_TOLERANCE = 0.000001;

/** Nombre d'exemples listés par catégorie. */
const SAMPLES = 10;


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';
$limit     = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--user=LOGIN]\n";
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

// L'agenda automatique consignerait un événement « Commande classée livrée » par clôture :
// 27 500 entrées sans valeur. Coupure en mémoire, comme dans les scripts de reprise.
if (!empty($conf->global)) {
    foreach (get_object_vars($conf->global) as $name => $value) {
        if (strpos($name, 'MAIN_AGENDA_ACTIONAUTO_') === 0 && !empty($value)) {
            $conf->global->$name = 0;
        }
    }
}

/**
 * Ramène toutes les lignes d'une requête.
 *
 * @param  DoliDB $db  Connexion
 * @param  string $sql Requête
 * @return array<int,stdClass>
 */
function fetchAll($db, $sql)
{
    $resql = $db->query($sql);
    if (!$resql) {
        echo "Erreur SQL : ".$db->lasterror()."\n";
        exit(1);
    }
    $rows = array();
    while ($obj = $db->fetch_object($resql)) {
        $rows[] = $obj;
    }
    $db->free($resql);

    return $rows;
}

/**
 * La ligne appartient-elle à la famille logistique, que rien n'expédie jamais ?
 *
 * La liste vient de l'inventaire du 20/08/2026 : « Transport » (commandes de la boutique),
 * les pseudo-articles `PORT*` et `*RETRAIT*` (commandes de l'ancien ERP), et trois artefacts
 * de gestion. Le texte libre et les services sont écartés en amont.
 *
 * @param  string $productRef Référence du produit de la ligne
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
 * 1. Quantités expédiées par ligne de commande — expéditions reprises, clôturées.
 */

$shipped = array();
foreach (fetchAll($db,
    'SELECT ed.fk_elementdet as cdid, SUM(ed.qty) as qty'
    .' FROM '.MAIN_DB_PREFIX.'expeditiondet as ed'
    .' INNER JOIN '.MAIN_DB_PREFIX.'expedition as e ON e.rowid = ed.fk_expedition'
    .' WHERE e.entity IN ('.getEntity('expedition').')'
    ."   AND e.ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'"
    .'   AND e.fk_statut = 2'
    .' GROUP BY ed.fk_elementdet'
) as $row) {
    $shipped[(int) $row->cdid] = (float) $row->qty;
}

if (empty($shipped)) {
    echo "Aucune expédition reprise clôturée : lancez d'abord « migrate.php shipment ».\n";
    exit(1);
}


/*
 * 2. Lignes des commandes encore ouvertes (validées ou en cours d'expédition).
 */

$orders = array();   // rowid -> ['ref' => …, 'lines' => [[cdid, qty, product_type, fk_product, pref], …]]
foreach (fetchAll($db,
    'SELECT c.rowid, c.ref, cd.rowid as cdid, cd.qty, cd.product_type, cd.fk_product, p.ref as pref'
    .' FROM '.MAIN_DB_PREFIX.'commande as c'
    .' INNER JOIN '.MAIN_DB_PREFIX.'commandedet as cd ON cd.fk_commande = c.rowid'
    .' LEFT JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = cd.fk_product'
    .' WHERE c.entity IN ('.getEntity('commande').')'
    .'   AND c.fk_statut IN (1, 2)'
    .' ORDER BY c.rowid'
) as $row) {
    $id = (int) $row->rowid;
    if (!isset($orders[$id])) {
        $orders[$id] = array('ref' => (string) $row->ref, 'lines' => array());
    }
    $orders[$id]['lines'][] = $row;
}


/*
 * 3. Verdict par commande.
 */

$deliverable = array();   // rowid -> ref
$partial     = array();   // rowid -> ref, pour le rapport

foreach ($orders as $id => $order) {
    $touched  = false;   // au moins une ligne servie par une expédition reprise
    $blocking = 0;

    foreach ($order['lines'] as $line) {
        $cdid = (int) $line->cdid;
        if (isset($shipped[$cdid])) {
            $touched = true;
        }

        // Ne bloquent que les lignes d'articles réels : le texte libre, les services et la
        // famille logistique ne s'expédient jamais.
        if ((int) $line->product_type !== 0 || (int) $line->fk_product <= 0 || (float) $line->qty <= 0) {
            continue;
        }
        if (isLogisticsRef((string) $line->pref)) {
            continue;
        }

        $got = isset($shipped[$cdid]) ? $shipped[$cdid] : 0.0;
        if ($got + QTY_TOLERANCE < (float) $line->qty) {
            $blocking++;
        }
    }

    if (!$touched) {
        continue;   // Commande étrangère aux expéditions reprises : hors périmètre.
    }

    if ($blocking === 0) {
        $deliverable[$id] = $order['ref'];
    } else {
        $partial[$id] = $order['ref'];
    }
}

echo "Commandes touchées par les expéditions reprises, encore ouvertes : "
    .number_format(count($deliverable) + count($partial), 0, ',', ' ')."\n";
echo "  À classer « Livrée » (toutes les lignes d'articles couvertes) : "
    .number_format(count($deliverable), 0, ',', ' ')."\n";
echo "  Livraisons partielles, laissées telles quelles : "
    .number_format(count($partial), 0, ',', ' ')
    .(empty($partial) ? '' : ' — '.implode(', ', array_slice($partial, 0, SAMPLES)))."\n\n";

if (!$confirm) {
    echo "Simulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
    exit(0);
}


/*
 * 4. Clôture, par l'API du coeur.
 */

$closed = 0;
$failed = 0;
$errors = array();

foreach ($deliverable as $id => $ref) {
    $order = new Commande($db);
    if ($order->fetch($id) <= 0) {
        $failed++;
        $errors[] = $ref.' : chargement impossible';
        continue;
    }

    $result = $order->cloture($user);
    if ($result <= 0) {
        $failed++;
        $errors[] = $ref.' : '.($order->error !== '' ? $order->error : 'clôture refusée ('.$result.')');
        continue;
    }

    $closed++;

    if ($limit > 0 && ($closed + $failed) >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
    if ($closed % 1000 === 0) {
        echo "  ".number_format($closed, 0, ',', ' ')." clôturée(s)…\n";
    }
}

echo "\nClôturées « Livrée » : ".number_format($closed, 0, ',', ' ')."\n";
echo "En échec            : ".$failed."\n";
foreach (array_slice($errors, 0, 20) as $err) {
    echo "  ".$err."\n";
}

$db->close();

exit($failed > 0 ? 1 : 0);
