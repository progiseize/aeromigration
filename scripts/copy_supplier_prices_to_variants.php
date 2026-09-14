<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/copy_supplier_prices_to_variants.php
 * \ingroup aeromigration
 * \brief   Recopie les tarifs fournisseurs d'un produit parent sur ses déclinaisons qui n'en ont pas.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * Les déclinaisons (`#XXXXX-NNN`, chantier du 08/09/2026) sont nées sans tarif fournisseur :
 * au 11/09, 1 016 des 1 042 variantes n'en portent aucun alors que leur parent en a. Or c'est
 * la fiche ENFANT qui se commande (panier de réappro, conditionnement, commandes fournisseurs)
 * — sans tarif, elle est « non commandable d'ici ».
 *
 * ------------------------------------------------------------------------------
 * RÈGLES
 * ------------------------------------------------------------------------------
 *
 * Pour chaque déclinaison, pour chaque FOURNISSEUR du parent : si l'enfant n'a AUCUNE ligne
 * chez ce fournisseur, on lui recopie TOUTES les lignes du parent chez lui (tous les paliers
 * de quantité : prix, TVA, remise, délai, conditionnement, extrafields aerotoolbox…). Si
 * l'enfant a déjà au moins une ligne chez ce fournisseur, on n'y touche pas, même différente.
 * Le script ne fait que combler des trous : rejouable, et valable pour les déclinaisons
 * créées plus tard.
 *
 * ## La référence fournisseur — convention retenue le 14/09/2026
 *
 * Dolibarr impose l'unicité de (réf fournisseur, fournisseur, quantité) : la réf du parent ne
 * peut pas être recopiée telle quelle sur plusieurs enfants. La base porte déjà la convention
 * héritée d'ADD — le code fournisseur, puis NOTRE réf entre parenthèses (« 1408 (#10917) »,
 * 988 lignes) — que les fournisseurs reçoivent ainsi sur les commandes. On la prolonge :
 *
 *     parent « 1408 (#10917) »  ->  enfant « 1408 (#10917-002) »
 *     parent « 114288 »         ->  enfant « 114288 (#14121-002) »
 *     parent « #09887 »         ->  enfant « #09887-002 »   (pas de code fournisseur : notre réf seule)
 *     parent « » (vide)         ->  enfant « #XXXXX-NNN »
 *
 * Le code-barres fournisseur n'est PAS recopié (unique lui aussi, et propre à l'article du
 * parent).
 *
 * ## Les doublons du parent
 *
 * 197 parents portent PLUSIEURS lignes chez un même fournisseur à la MÊME quantité — jamais
 * des paliers : une ligne de reprise avec notre réf (« #10113 », 0 €) à côté de la vraie
 * (« K503 », 11,53 €), ou une réf par taille ajoutée à la main sur le parent faute de tarif
 * sur l'enfant (« K503 - 03 L »). Les recopier toutes multiplierait le doublon par le nombre
 * de déclinaisons. Par (fournisseur, quantité), UNE seule ligne est retenue : celle qui a un
 * prix, sinon la plus récente ; les cas sont listés au rapport. `--all-lines` recopie tout.
 *
 * ## Écriture
 *
 * INSERT … SELECT colonne à colonne (liste lue dans le schéma, pas figée), sans passer par
 * ProductFournisseur::update_buyprice() — ni trigger ni recalcul, un tarif d'achat ne
 * concerne pas la boutique. Transaction globale : une erreur annule tout. import_key porte
 * l'horodatage du passage.
 *
 * Usage :
 *   php copy_supplier_prices_to_variants.php                simulation : ventilation + exemples, rien d'écrit
 *   php copy_supplier_prices_to_variants.php --confirm      applique
 *   php copy_supplier_prices_to_variants.php --limit=20 --confirm   lot d'essai (N déclinaisons)
 *   php copy_supplier_prices_to_variants.php --parent=#10917        une seule famille
 *   php copy_supplier_prices_to_variants.php --all-lines             recopie aussi les doublons du parent
 *   php copy_supplier_prices_to_variants.php --user=LOGIN
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

$langs->loadLangs(array('admin'));

/** Nombre d'exemples listés par catégorie. */
const SAMPLES = 8;

/** Longueur maximale de ref_fourn (varchar(128)). */
const REF_FOURN_MAX = 128;


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$onlyParent = '';
$allLines  = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--parent=(.+)$/', $arg, $m)) {
        $onlyParent = trim($m[1]);
    } elseif ($arg === '--all-lines') {
        $allLines = true;
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--parent=REF] [--all-lines] [--user=LOGIN]\n";
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


/**
 * Réf fournisseur de l'enfant, selon la convention ADD prolongée : le code fournisseur du
 * parent (débarrassé de « (#XXXXX) » ou d'un « #XXXXX » nu) suivi de « (#XXXXX-NNN) » ; s'il
 * ne reste aucun code fournisseur, la réf de l'enfant seule.
 *
 * @param  string $parentRefFourn Réf fournisseur du parent
 * @param  string $parentRef      Réf du parent (« #10917 »)
 * @param  string $childRef       Réf de la déclinaison (« #10917-002 »)
 * @return string
 */
function child_ref_fourn($parentRefFourn, $parentRef, $childRef)
{
    $base = trim((string) $parentRefFourn);
    // « 1408 (#10917) » -> « 1408 » ; on retire aussi une parenthèse portant une autre réf
    // interne (#\d+), reliquat d'un déplacement de tarif.
    $base = trim(preg_replace('/\s*\(\s*#\d{1,5}(?:-\d{1,3})?\s*\)\s*$/', '', $base));
    // « #09887 » nu (le parent n'a que notre réf comme réf fournisseur) -> vide.
    if ($base === $parentRef || preg_match('/^#\d{1,5}$/', $base)) {
        $base = '';
    }
    $out = ($base === '') ? $childRef : $base.' ('.$childRef.')';

    return dol_trunc($out, REF_FOURN_MAX, 'right', 'UTF-8', 1);
}


/*
 * Colonnes à recopier : lues dans le schéma (les deux tables), moins celles que le script
 * pose lui-même ou qui n'ont pas à suivre (identifiants, horodatages, code-barres unique).
 */

/**
 * Colonnes d'une table, hors exclusions.
 *
 * @param  DoliDB   $db      Base
 * @param  string   $table   Nom complet
 * @param  string[] $exclude Colonnes à écarter
 * @return string[]
 */
function table_columns($db, $table, array $exclude)
{
    $cols  = array();
    $resql = $db->query('SHOW COLUMNS FROM '.$table);
    while ($resql && ($o = $db->fetch_object($resql))) {
        if (!in_array($o->Field, $exclude, true)) {
            $cols[] = $o->Field;
        }
    }

    return $cols;
}

/**
 * Les lignes d'un fournisseur à recopier : une par quantité — celle qui porte un prix, sinon
 * la plus récente — sauf si on a demandé toutes les lignes.
 *
 * @param  object[] $lines    Lignes du parent chez ce fournisseur (triées par quantité, tms)
 * @param  bool     $allLines Tout recopier, doublons compris
 * @param  object[] $dropped  En sortie : les lignes écartées
 * @return object[]
 */
function pick_lines(array $lines, $allLines, array &$dropped)
{
    $dropped = array();
    if ($allLines) {
        return $lines;
    }
    $byQty = array();
    foreach ($lines as $l) {
        $q = (string) (float) $l->quantity;
        if (!isset($byQty[$q])) {
            $byQty[$q] = $l;
            continue;
        }
        $cur = $byQty[$q];
        // Un prix bat pas de prix ; à égalité, la ligne la plus récente (tri d'entrée) l'emporte.
        $better = ((float) $l->price > 0 && (float) $cur->price <= 0)
            || (((float) $l->price > 0) === ((float) $cur->price > 0));
        if ($better) {
            $dropped[] = $cur;
            $byQty[$q] = $l;
        } else {
            $dropped[] = $l;
        }
    }

    return array_values($byQty);
}

$tPrice = MAIN_DB_PREFIX.'product_fournisseur_price';
$tExtra = MAIN_DB_PREFIX.'product_fournisseur_price_extrafields';
$colsPrice = table_columns($db, $tPrice, array('rowid', 'tms', 'datec', 'fk_product', 'ref_fourn', 'fk_user', 'import_key', 'barcode', 'fk_barcode_type'));
$colsExtra = table_columns($db, $tExtra, array('rowid', 'tms', 'fk_object', 'import_key'));
$importKey = dol_print_date(dol_now(), '%Y%m%d%H%M%S');


/*
 * Les déclinaisons et, pour chacune, les fournisseurs du parent qu'elle n'a pas.
 */

$sql  = 'SELECT pac.fk_product_parent AS pid, pac.fk_product_child AS cid, par.ref AS pref, c.ref AS cref';
$sql .= ' FROM '.MAIN_DB_PREFIX.'product_attribute_combination AS pac';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS par ON par.rowid = pac.fk_product_parent';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS c ON c.rowid = pac.fk_product_child';
$sql .= ' WHERE pac.entity IN ('.getEntity('product').')';
if ($onlyParent !== '') {
    $sql .= " AND par.ref = '".$db->escape($onlyParent)."'";
}
$sql .= ' ORDER BY par.ref, c.ref';

$children = array();
$resql = $db->query($sql);
while ($resql && ($o = $db->fetch_object($resql))) {
    $children[] = $o;
}
if ($resql) {
    $db->free($resql);
}

// Tarifs par produit : produit -> fournisseur -> lignes (rowid, ref_fourn, quantity, price).
$pricesByProduct = array();
$resql = $db->query('SELECT rowid, fk_product, fk_soc, ref_fourn, quantity, price, entity, tms FROM '.$tPrice
    .' WHERE entity IN ('.getEntity('productsupplierprice').') ORDER BY fk_product, fk_soc, quantity, tms');
while ($resql && ($o = $db->fetch_object($resql))) {
    $pricesByProduct[(int) $o->fk_product][(int) $o->fk_soc][] = $o;
}
if ($resql) {
    $db->free($resql);
}

// Noms des fournisseurs, pour les exemples.
$socNames = array();
$resql = $db->query('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe WHERE fournisseur = 1');
while ($resql && ($o = $db->fetch_object($resql))) {
    $socNames[(int) $o->rowid] = $o->nom;
}
if ($resql) {
    $db->free($resql);
}


/*
 * Parcours.
 */

$stats = array(
    'variantes'        => count($children),
    'a_completer'      => 0,   // déclinaisons recevant au moins une ligne
    'deja_completes'   => 0,   // tous les fournisseurs du parent déjà présents
    'parent_sans_tarif' => 0,
    'lignes'           => 0,   // lignes de tarif créées
    'fournisseurs'     => 0,   // couples (déclinaison, fournisseur) complétés
    'conflit'          => 0,   // réf fournisseur déjà prise ailleurs (index unique)
    'doublons'         => 0,   // lignes du parent écartées (même fournisseur, même quantité)
    'erreur'           => 0,
);
$samples = array('exemples' => array(), 'conflit' => array(), 'erreur' => array(), 'doublons' => array());
$suppliersTouched = array();

echo "Script      : tarifs fournisseurs parent -> déclinaisons (trous seulement)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Import key  : ".$importKey."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

$done = 0;
foreach ($children as $ch) {
    $pid = (int) $ch->pid;
    $cid = (int) $ch->cid;

    if (empty($pricesByProduct[$pid])) {
        $stats['parent_sans_tarif']++;
        continue;
    }

    $touched = false;
    foreach ($pricesByProduct[$pid] as $socid => $lines) {
        if (!empty($pricesByProduct[$cid][$socid])) {
            continue;   // l'enfant a déjà ce fournisseur : on n'y touche pas
        }

        $dropped = array();
        $lines = pick_lines($lines, $allLines, $dropped);
        foreach ($dropped as $d) {
            $stats['doublons']++;
            $dkey = $pid.'-'.$socid.'-'.$d->rowid;
            if (count($samples['doublons']) < SAMPLES && !isset($samples['doublons'][$dkey])) {
                $kept = null;
                foreach ($lines as $k) {
                    if ((float) $k->quantity == (float) $d->quantity) {
                        $kept = $k;
                    }
                }
                $samples['doublons'][$dkey] = $ch->pref.' / '.(isset($socNames[$socid]) ? $socNames[$socid] : $socid)
                    .' : « '.$d->ref_fourn.' » ('.price((float) $d->price, 0, $langs, 0, -1, -1, $conf->currency).') écartée, « '
                    .($kept ? $kept->ref_fourn : '?').' » ('.($kept ? price((float) $kept->price, 0, $langs, 0, -1, -1, $conf->currency) : '').') retenue';
            }
        }

        // Vérification d'unicité AVANT d'écrire : (ref_fourn, fk_soc, quantity, entity).
        $plan = array();
        $conflict = false;
        foreach ($lines as $l) {
            $newRef = child_ref_fourn($l->ref_fourn, $ch->pref, $ch->cref);
            $sqlc = 'SELECT rowid FROM '.$tPrice." WHERE ref_fourn = '".$db->escape($newRef)."'"
                .' AND fk_soc = '.$socid.' AND quantity = '.((float) $l->quantity).' AND entity = '.((int) $l->entity);
            $rc = $db->query($sqlc);
            if ($rc && $db->num_rows($rc) > 0) {
                $conflict = true;
                $stats['conflit']++;
                if (count($samples['conflit']) < SAMPLES) {
                    $samples['conflit'][] = $ch->cref.' / '.(isset($socNames[$socid]) ? $socNames[$socid] : $socid).' : « '.$newRef.' » (qté '.((float) $l->quantity).') déjà utilisée';
                }
                break;
            }
            $plan[] = array($l, $newRef);
        }
        if ($conflict) {
            continue;
        }

        $touched = true;
        $stats['fournisseurs']++;
        $suppliersTouched[$socid] = true;

        foreach ($plan as $p) {
            list($l, $newRef) = $p;
            $stats['lignes']++;
            if (count($samples['exemples']) < SAMPLES) {
                $samples['exemples'][] = sprintf('%s / %s : « %s » -> « %s » (qté %s, %s)', $ch->cref,
                    isset($socNames[$socid]) ? $socNames[$socid] : $socid, (string) $l->ref_fourn, $newRef,
                    (float) $l->quantity, price((float) $l->price, 0, $langs, 0, -1, -1, $conf->currency));
            }
            if (!$confirm) {
                continue;
            }

            $sqli  = 'INSERT INTO '.$tPrice.' (fk_product, ref_fourn, datec, fk_user, import_key, '.implode(', ', $colsPrice).')';
            $sqli .= ' SELECT '.$cid.", '".$db->escape($newRef)."', '".$db->idate(dol_now())."', ".((int) $user->id).", '".$db->escape($importKey)."', ";
            $sqli .= implode(', ', $colsPrice).' FROM '.$tPrice.' WHERE rowid = '.((int) $l->rowid);
            if (!$db->query($sqli)) {
                $stats['erreur']++;
                if (count($samples['erreur']) < SAMPLES) {
                    $samples['erreur'][] = $ch->cref.' : '.$db->lasterror();
                }
                continue;
            }
            $newId = (int) $db->last_insert_id($tPrice);

            // Les extrafields (libellé fournisseur, conditionnement aerotoolbox) suivent.
            if ($colsExtra) {
                $sqle  = 'INSERT INTO '.$tExtra.' (fk_object, import_key, '.implode(', ', $colsExtra).')';
                $sqle .= ' SELECT '.$newId.", '".$db->escape($importKey)."', ".implode(', ', $colsExtra);
                $sqle .= ' FROM '.$tExtra.' WHERE fk_object = '.((int) $l->rowid);
                if (!$db->query($sqle)) {
                    $stats['erreur']++;
                    if (count($samples['erreur']) < SAMPLES) {
                        $samples['erreur'][] = $ch->cref.' (extrafields) : '.$db->lasterror();
                    }
                }
            }
        }
    }

    if ($touched) {
        $stats['a_completer']++;
        $done++;
        if ($limit > 0 && $done >= $limit) {
            echo "Limite atteinte (".$limit.").\n";
            break;
        }
    } else {
        $stats['deja_completes']++;
    }
}

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

printf("Déclinaisons examinées        : %s\n", number_format($stats['variantes'], 0, ',', ' '));
printf("Déclinaisons %s : %s\n", $confirm ? 'complétées   ' : 'à compléter  ', number_format($stats['a_completer'], 0, ',', ' '));
printf("  couples (déclinaison, fournisseur) : %s, chez %s fournisseur(s) distinct(s)\n",
    number_format($stats['fournisseurs'], 0, ',', ' '), number_format(count($suppliersTouched), 0, ',', ' '));
printf("  lignes de tarif %s : %s\n", $confirm ? 'créées ' : 'à créer', number_format($stats['lignes'], 0, ',', ' '));
printf("Déjà complètes (rien à faire) : %s\n", number_format($stats['deja_completes'], 0, ',', ' '));
printf("Parent sans aucun tarif       : %s\n", number_format($stats['parent_sans_tarif'], 0, ',', ' '));

if ($samples['exemples']) {
    echo "\nExemples de réf fournisseur :\n";
    foreach ($samples['exemples'] as $e) {
        echo "  - ".$e."\n";
    }
}
if ($stats['doublons'] > 0) {
    printf("
Doublons du parent (même fournisseur, même quantité) : %s ligne(s) non recopiée(s)%s
",
        number_format($stats['doublons'], 0, ',', ' '), $allLines ? '' : ' — --all-lines pour tout recopier');
    foreach ($samples['doublons'] as $e) {
        echo "  - ".$e."
";
    }
}
if ($stats['conflit'] > 0) {
    printf("\nÉcarts :\n  %7s  couple(s) sautés : réf fournisseur déjà prise (index unique) — %s\n",
        number_format($stats['conflit'], 0, ',', ' '), implode(' ; ', $samples['conflit']));
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['erreur'] > 0 ? 1 : 0);
