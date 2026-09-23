<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/normalize_stock_alert.php
 * \ingroup aeromigration
 * \brief   Remet du sens dans le seuil d'alerte de stock : zéro sur le catalogue actif, rien ailleurs.
 *
 * ------------------------------------------------------------------------------
 * CE QUI S'EST PASSÉ
 * ------------------------------------------------------------------------------
 *
 * `llx_product.seuil_stock_alerte` a trois sens chez Dolibarr : NULL = pas d'avertissement,
 * 0 = avertir dès que le stock est vide, un nombre = avertir en dessous. Le catalogue repris
 * n'en connaissait qu'un : **aucun NULL, 13 075 zéros**, parce que le formulaire natif de
 * création écrit `0` quand la case est vide (`product/card.php`, `GETPOST(...) ? ... : 0`) et
 * que l'ancien ERP faisait pareil — dans ADD, `f_artstock.AS_QteMini` vaut 0 sur 26 998 lignes
 * sur 27 924, et seuls ~1 000 articles portaient une vraie règle (toutes reprises : 921 des
 * 924 minis > 0 sont en place).
 *
 * Tant que ces zéros n'étaient que du remplissage, le module les lisait comme « pas d'alerte ».
 * Le client veut désormais s'en servir : un seuil à zéro = « à commander dès que c'est vide ».
 * Il faut donc que le zéro soit posé là où il veut dire quelque chose — et nulle part ailleurs,
 * sans quoi dix mille fiches allumeraient une alarme rouge.
 *
 * ## Ce que fait le script
 *
 * Pour chaque article :
 *
 * - **seuil > 0** : on n'y touche jamais. C'est une règle saisie, elle vaut par elle-même.
 * - **catalogue actif** — un bien (pas un service), en vente ET en achat, dont la disponibilité
 *   et le suivi ne sont pas des états d'arrêt (`COM_ARRET`, `INDISPO_DEF`, `PRESTATION`,
 *   `ARRET_STOCK`) : le seuil est posé à **0**. C'est le « plus d'article sans règle » demandé.
 * - **tout le reste** — services, articles retirés de la vente ou des achats, arrêtés : le seuil
 *   repasse à **NULL**. Un article dont on ne veut plus n'a pas de règle de réapprovisionnement.
 *
 * Écriture en SQL direct, par lots : la colonne ne porte aucun trigger et ne part pas à la
 * boutique. Le script est **rejouable** — il converge vers le même état.
 *
 * ## Après le passage
 *
 * - La Vue 360° lit les trois états : « Non », « Oui (dès que vide) », « Oui (n) ».
 * - L'alarme de la fiche et les priorités du réapprovisionnement prennent le zéro en compte,
 *   mais seulement sur les articles actifs (aerotoolbox 1.52.2).
 *
 * Usage :
 *   php normalize_stock_alert.php               simule et compte (aucune écriture)
 *   php normalize_stock_alert.php --confirm     applique
 *   php normalize_stock_alert.php --list=20     montre 20 exemples de chaque cas
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
dol_include_once('/aerotoolbox/lib/aeroreappro.lib.php');   // aerotb_reappro_stopped_states()

$langs->loadLangs(array('main', 'products', 'stocks'));

$confirm = false;
$list    = 0;
foreach ($argv as $i => $arg) {
	if ($i === 0) {
		continue;
	}
	if ($arg === '--confirm') {
		$confirm = true;
	} elseif (preg_match('/^--list=(\d+)$/', $arg, $m)) {
		$list = (int) $m[1];
	} elseif ($arg === '--help' || $arg === '-h') {
		echo "Usage: php ".$script_file." [--confirm] [--list=N]\n";
		exit(0);
	} else {
		echo "Argument inconnu : ".$arg."\n";
		exit(1);
	}
}

$stopped = aerotb_reappro_stopped_states($db);
$availKo = $stopped['avail'] ? implode(',', $stopped['avail']) : '0';
$trackKo = $stopped['track'] ? implode(',', $stopped['track']) : '0';

echo "===============================================================\n";
echo " Seuil d'alerte de stock — remise en cohérence\n";
echo "===============================================================\n";
echo "Base        : ".$db->database_name."\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "États d'arrêt écartés : disponibilité ".$availKo." / suivi ".$trackKo."\n\n";

// Le périmètre actif, en une expression réutilisée telle quelle par les deux requêtes.
$actif  = "p.fk_product_type = 0 AND p.tosell = 1 AND p.tobuy = 1";
$actif .= " AND COALESCE(e.aerotb_availability, 0) NOT IN (".$availKo.")";
$actif .= " AND COALESCE(e.aerotb_tracking, 0) NOT IN (".$trackKo.")";

$from  = MAIN_DB_PREFIX."product AS p";
$from .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields AS e ON e.fk_object = p.rowid";
$where = "p.entity IN (".getEntity('product').")";

/**
 * Compte une population.
 *
 * @param  DoliDB $db  Base
 * @param  string $sql Requête de comptage
 * @return int         Nombre de lignes
 */
function compte($db, $sql)
{
	$res = $db->query($sql);
	if (!$res) {
		echo "ERREUR SQL : ".$db->lasterror()."\n";
		exit(1);
	}
	$o = $db->fetch_object($res);

	return (int) $o->n;
}

// ── L'état des lieux ──
$total   = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where);
$posé    = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte > 0");
$zero    = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0");
$nul     = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte IS NULL");
echo "Avant      : ".$total." article(s) — ".$posé." avec un seuil saisi, ".$zero." à zéro, ".$nul." sans rien\n";

// ── Ce qui va changer ──
$aPoser   = "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte IS NULL AND ".$actif;
$aVider   = "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0 AND NOT (".$actif.")";
$nPoser   = compte($db, $aPoser);
$nVider   = compte($db, $aVider);
$nGarde   = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0 AND ".$actif);

echo "\n";
echo "À poser à 0 (actifs sans seuil)        : ".$nPoser."\n";
echo "À vider    (zéros hors périmètre)      : ".$nVider."\n";
echo "Zéros conservés (actifs, déjà à 0)     : ".$nGarde."\n";
echo "Seuils saisis, intacts                 : ".$posé."\n";

// ── Le détail de ce qui sera vidé, par motif ──
$motifs = array(
	'services'          => "p.fk_product_type <> 0",
	'hors vente'        => "p.fk_product_type = 0 AND p.tosell = 0",
	'hors achat'        => "p.fk_product_type = 0 AND p.tosell = 1 AND p.tobuy = 0",
	'état d\'arrêt'     => "p.fk_product_type = 0 AND p.tosell = 1 AND p.tobuy = 1 AND (COALESCE(e.aerotb_availability, 0) IN (".$availKo.") OR COALESCE(e.aerotb_tracking, 0) IN (".$trackKo."))",
);
echo "\nMotifs du vidage :\n";
foreach ($motifs as $nom => $cond) {
	echo '   '.$nom.str_repeat(' ', max(1, 22 - dol_strlen($nom))).' : '.compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0 AND ".$cond)."\n";
}

// ── Quelques exemples, à la demande ──
if ($list > 0) {
	foreach (array('à poser à 0' => $actif." AND p.seuil_stock_alerte IS NULL", 'à vider' => "NOT (".$actif.") AND p.seuil_stock_alerte = 0") as $titre => $cond) {
		echo "\nExemples « ".$titre." » :\n";
		$res = $db->query("SELECT p.ref, p.label, p.tosell, p.tobuy, p.fk_product_type FROM ".$from." WHERE ".$where." AND ".$cond." ORDER BY p.ref LIMIT ".$list);
		while ($res && ($o = $db->fetch_object($res))) {
			echo "   ".str_pad($o->ref, 12).dol_trunc($o->label, 48)
				." [".($o->fk_product_type ? 'service' : 'bien').", vente ".$o->tosell.", achat ".$o->tobuy."]\n";
		}
	}
}

if (!$confirm) {
	echo "\n--- SIMULATION : rien n'a été écrit. Relancer avec --confirm pour appliquer. ---\n";
	exit(0);
}

// ── L'écriture ──
$db->begin();
$ok = true;

$sql = "UPDATE ".MAIN_DB_PREFIX."product AS p";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields AS e ON e.fk_object = p.rowid";
$sql .= " SET p.seuil_stock_alerte = 0";
$sql .= " WHERE ".$where." AND p.seuil_stock_alerte IS NULL AND ".$actif;
if (!$db->query($sql)) {
	echo "ERREUR (pose) : ".$db->lasterror()."\n";
	$ok = false;
}

$sql = "UPDATE ".MAIN_DB_PREFIX."product AS p";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields AS e ON e.fk_object = p.rowid";
$sql .= " SET p.seuil_stock_alerte = NULL";
$sql .= " WHERE ".$where." AND p.seuil_stock_alerte = 0 AND NOT (".$actif.")";
if ($ok && !$db->query($sql)) {
	echo "ERREUR (vidage) : ".$db->lasterror()."\n";
	$ok = false;
}

if (!$ok) {
	$db->rollback();
	echo "\n--- ÉCHEC : transaction annulée, rien n'a changé. ---\n";
	exit(1);
}
$db->commit();

// ── Le contrôle après coup ──
$posé2 = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte > 0");
$zero2 = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0");
$nul2  = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte IS NULL");
echo "\nAprès      : ".$posé2." avec un seuil saisi, ".$zero2." à zéro (actifs), ".$nul2." sans rien\n";
$reste = compte($db, "SELECT COUNT(*) n FROM ".$from." WHERE ".$where." AND p.seuil_stock_alerte = 0 AND NOT (".$actif.")");
echo "Contrôle   : zéros restés hors périmètre : ".$reste." (attendu 0)\n";
echo "\n--- Terminé. ---\n";
