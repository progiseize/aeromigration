<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/realign_kit_status.php
 * \ingroup aeromigration
 * \brief   Remet chaque lot d'accord avec ses composants : suivi imposé, disponibilité durcie.
 *
 * ------------------------------------------------------------------------------
 * CE QUI S'EST PASSÉ
 * ------------------------------------------------------------------------------
 *
 * Depuis la 1.13.0, un lot ne choisit plus son suivi : il prend le plus contraignant de ses
 * composants (`ARRET_STOCK` > `NON_SUIVI` > `SUIVI`), et sa disponibilité se durcit dès qu'un
 * composant est arrêté, suspendu ou indisponible. Tout passe par une porte unique
 * (`aerotb_status_write`), et la liste déroulante de la fiche ne propose qu'un couple par
 * disponibilité.
 *
 * Mais la règle **ne se déclenche que sur événement** : un composant modifié, une composition
 * changée, un mouvement de stock. Les lots que rien n'a touché depuis la 1.13.0 sont restés tels
 * quels. Au relevé du 25/09/2026 sur la copie de production : **6 lots** portaient un suivi
 * différent de celui de leurs composants, et **5 lots en vente sur 20** étaient MOINS restrictifs
 * que les leurs — dont #14580 « Pochette VFR 2025 », affiché *Disponible* avec un composant en
 * commercialisation arrêtée, et vendu 54 fois sur l'année.
 *
 * ## Ce que fait le script
 *
 * Pour chaque produit composé du catalogue, dans l'ordre que le module impose lui-même :
 *
 * 1. **le suivi** — `aerotb_kit_sync()` : celui des composants remplace celui du lot, à condition
 *    que le couple (disponibilité × suivi) existe dans la configuration ;
 * 2. **la disponibilité** — `aerotb_kit_availability_refresh()` : l'état le plus grave des
 *    composants s'impose, la disponibilité d'origine étant mémorisée pour être rendue quand le
 *    blocage se lèvera. À défaut d'état bloquant, un lot qu'on ne peut plus assembler suit la
 *    bascule de rupture de sa combinaison.
 *
 * **La règle ne fait que durcir.** Un lot volontairement arrêté alors que ses composants vont bien
 * n'est jamais radouci : ce choix-là est celui de quelqu'un, pas un oubli.
 *
 * Le poids et le prix de revient d'un lot viennent eux aussi de ses composants, mais ils ont leur
 * propre synchronisation (`aerotb_kit_weight_sync`, `aerotb_kit_cost_sync`) : ce script n'y touche
 * pas, il ne s'occupe que de l'état commercial.
 *
 * ## La boutique
 *
 * L'alignement n'envoie rien à PrestaShop : `aerotb_kit_availability_refresh()` écrit sans pousser,
 * pour ne pas faire partir une rafale de mises à jour depuis un trigger. Sur un rattrapage de masse
 * la question se pose autrement — un lot qui devient « Commercialisation arrêtée » reste en vente
 * en ligne tant que la boutique l'ignore. L'option `--push` réécrit donc le couple retenu, à
 * l'identique, en demandant cette fois la propagation.
 *
 * ## Rejouable
 *
 * Le script converge : une seconde exécution ne trouve plus rien à faire. La simulation applique
 * réellement les règles dans une transaction annulée à la fin — ce qu'elle annonce est donc ce qui
 * sera écrit, et non une prévision refaite à la main.
 *
 * Usage :
 *   php realign_kit_status.php                simule et compte (aucune écriture)
 *   php realign_kit_status.php --confirm      applique
 *   php realign_kit_status.php --confirm --push   applique et propage vers la boutique
 *   php realign_kit_status.php --list=30      montre 30 cas en détail
 *   php realign_kit_status.php --id=6033      un seul lot
 */

if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = dirname(__FILE__).'/';
if (substr($sapi_type, 0, 3) === 'cgi') {
	echo "Erreur : ce script doit être lancé en ligne de commande (CLI).\n";
	exit(1);
}

require_once $path.'../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/aerotoolbox/lib/aerokit.lib.php');
dol_include_once('/aerotoolbox/lib/aerotoolbox.lib.php');

$langs->loadLangs(array('main', 'products', 'stocks', 'aerotoolbox@aerotoolbox'));

$confirm = false;
$push    = false;
$list    = 0;
$only    = 0;
foreach ($argv as $i => $arg) {
	if ($i === 0) {
		continue;
	}
	if ($arg === '--confirm') {
		$confirm = true;
	} elseif ($arg === '--push') {
		$push = true;
	} elseif (preg_match('/^--list=(\d+)$/', $arg, $m)) {
		$list = (int) $m[1];
	} elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
		$only = (int) $m[1];
	} elseif ($arg === '--help' || $arg === '-h') {
		echo "Usage: php ".$script_file." [--confirm] [--push] [--list=N] [--id=X]\n";
		exit(0);
	} else {
		echo "Argument inconnu : ".$arg."\n";
		exit(1);
	}
}

/**
 * Le libellé d'une entrée de dictionnaire, tel que la fiche l'affiche.
 *
 * @param  DoliDB $db    Base
 * @param  string $table Table sans préfixe
 * @param  int    $id    Identifiant
 * @return string
 */
function aerotb_realign_label($db, $table, $id)
{
	static $cache = array();

	$id = (int) $id;
	if ($id <= 0) {
		return '(vide)';
	}
	$key = $table.':'.$id;
	if (isset($cache[$key])) {
		return $cache[$key];
	}
	$cache[$key] = '?';
	$resql = $db->query('SELECT label FROM '.MAIN_DB_PREFIX.$db->escape($table).' WHERE rowid = '.$id);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$cache[$key] = (string) $o->label;
	}

	return $cache[$key];
}

/**
 * Le couple porté par un article, lu en base sans passer par le cache d'objet.
 *
 * @param  DoliDB $db  Base
 * @param  int    $pid Article
 * @return array{av:int,tk:int}
 */
function aerotb_realign_couple($db, $pid)
{
	$out   = array('av' => 0, 'tk' => 0);
	$resql = $db->query('SELECT aerotb_availability AS av, aerotb_tracking AS tk FROM '
		.MAIN_DB_PREFIX.'product_extrafields WHERE fk_object = '.((int) $pid));
	if ($resql && ($o = $db->fetch_object($resql))) {
		$out = array('av' => (int) $o->av, 'tk' => (int) $o->tk);
	}

	return $out;
}

echo "===============================================================\n";
echo " Produits composés — remise d'accord avec leurs composants\n";
echo "===============================================================\n";
echo "Base        : ".$db->database_name."\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (transaction annulée)")."\n";
echo "Boutique    : ".($push ? "propagation demandée" : "aucune propagation")."\n\n";

// ── Les lots du catalogue ──
$sql  = 'SELECT DISTINCT pa.fk_product_pere AS id, p.ref, p.label, p.tosell';
$sql .= ' FROM '.MAIN_DB_PREFIX.'product_association AS pa';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = pa.fk_product_pere';
$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
if ($only > 0) {
	$sql .= ' AND p.rowid = '.$only;
}
$sql .= ' ORDER BY p.ref';

$lots  = array();
$resql = $db->query($sql);
if (!$resql) {
	echo "ERREUR SQL : ".$db->lasterror()."\n";
	exit(1);
}
while ($o = $db->fetch_object($resql)) {
	$lots[(int) $o->id] = array('ref' => (string) $o->ref, 'label' => (string) $o->label, 'tosell' => (int) $o->tosell);
}
$db->free($resql);

$enVente = 0;
foreach ($lots as $l) {
	$enVente += $l['tosell'] ? 1 : 0;
}
echo "Lots        : ".count($lots)." (dont ".$enVente." en vente)\n\n";
if (!$lots) {
	echo "--- Rien à faire. ---\n";
	exit(0);
}

// ── Le passage, dans une transaction : annulée en simulation, validée sinon ──
$db->begin();

$changes = array();
$erreurs = 0;
foreach ($lots as $pid => $l) {
	$avant = aerotb_realign_couple($db, $pid);

	// L'ordre compte : le suivi d'abord, la disponibilité le relit pour composer le couple. Aucun
	// utilisateur n'est passé — le script tient son propre journal, l'agenda de chaque fiche n'a pas
	// besoin de cent quatre-vingt-une lignes de rattrapage.
	$r1 = aerotb_kit_sync($db, $pid, null, false, true);
	$r2 = aerotb_kit_availability_refresh($db, $pid, null, true);
	if ($r1 < 0 || $r2 < 0) {
		$erreurs++;
		echo "   ERREUR sur ".$l['ref']." (suivi ".$r1.", disponibilité ".$r2.")\n";
		continue;
	}

	$apres = aerotb_realign_couple($db, $pid);
	if ($apres === $avant) {
		continue;
	}
	$changes[$pid] = array('ref' => $l['ref'], 'label' => $l['label'], 'tosell' => $l['tosell'],
		'avant' => $avant, 'apres' => $apres);
}

// ── Ce que cela change ──
$nbTrack  = 0;
$nbAvail  = 0;
$nbVente  = 0;
foreach ($changes as $c) {
	$nbTrack += ($c['avant']['tk'] !== $c['apres']['tk']) ? 1 : 0;
	$nbAvail += ($c['avant']['av'] !== $c['apres']['av']) ? 1 : 0;
	$nbVente += $c['tosell'] ? 1 : 0;
}
echo "Lots corrigés                : ".count($changes)." (dont ".$nbVente." en vente)\n";
echo "   dont le suivi change      : ".$nbTrack."\n";
echo "   dont la disponibilité     : ".$nbAvail."\n";
echo "Erreurs                      : ".$erreurs."\n";

if ($changes) {
	echo "\nDétail".($list > 0 ? '' : ' (les lots en vente ; --list=N pour tout voir)')." :\n";
	$n = 0;
	foreach ($changes as $c) {
		if ($list <= 0 && !$c['tosell']) {
			continue;
		}
		if ($list > 0 && ++$n > $list) {
			break;
		}
		echo '   '.str_pad($c['ref'], 9).($c['tosell'] ? '[en vente] ' : '[retiré]   ').dol_trunc($c['label'], 40)."\n";
		if ($c['avant']['av'] !== $c['apres']['av']) {
			echo '      disponibilité : '.aerotb_realign_label($db, 'c_aerotoolbox_availability', $c['avant']['av'])
				.'  ->  '.aerotb_realign_label($db, 'c_aerotoolbox_availability', $c['apres']['av'])."\n";
		}
		if ($c['avant']['tk'] !== $c['apres']['tk']) {
			echo '      suivi         : '.aerotb_realign_label($db, 'c_aerotoolbox_tracking', $c['avant']['tk'])
				.'  ->  '.aerotb_realign_label($db, 'c_aerotoolbox_tracking', $c['apres']['tk'])."\n";
		}
	}
}

if (!$confirm) {
	$db->rollback();
	echo "\n--- SIMULATION : la transaction a été annulée, rien n'a été écrit. ---\n";
	echo "--- Relancer avec --confirm pour appliquer. ---\n";
	exit(0);
}

if ($erreurs > 0) {
	$db->rollback();
	echo "\n--- ÉCHEC : ".$erreurs." erreur(s), transaction annulée, rien n'a changé. ---\n";
	exit(1);
}
$db->commit();
echo "\nÉcriture validée.\n";

// ── La boutique, à la demande ──
if ($push && $changes) {
	echo "\nPropagation vers la boutique :\n";
	$ok = 0;
	$ko = 0;
	foreach ($changes as $pid => $c) {
		// Le couple retenu est réécrit à l'identique, cette fois en demandant la propagation. La
		// cascade est coupée : les lots parents viennent d'être traités par la boucle ci-dessus.
		$info = array();
		if (aerotb_status_write($db, $pid, $c['apres']['av'], $c['apres']['tk'], null, true, $info, false) < 0) {
			$ko++;
			echo '   ÉCHEC '.$c['ref']."\n";
			continue;
		}
		$ok++;
		if (!empty($info['error'])) {
			echo '   '.$c['ref'].' : '.$info['error']."\n";
		}
	}
	echo '   '.$ok." envoi(s), ".$ko." échec(s)\n";
}

// ── Le contrôle après coup : plus rien ne doit être désaligné ──
$reste = 0;
foreach (array_keys($lots) as $pid) {
	$cur = aerotb_realign_couple($db, $pid);
	$tk  = aerotb_kit_tracking($db, $pid, true);
	$av  = aerotb_kit_forced_availability($db, $pid, true);
	if ($tk > 0 && $cur['tk'] !== $tk) {
		$reste++;
	} elseif ($av > 0 && aerotb_kit_availability_rank($db, $cur['av']) < aerotb_kit_availability_rank($db, $av)) {
		$reste++;
	}
}
echo "\nContrôle    : lots encore désalignés : ".$reste." (attendu 0)\n";
echo "\n--- Terminé. ---\n";
