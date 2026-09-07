<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/ventilate_declinaisons.php
 * \ingroup aeromigration
 * \brief   Ventile le stock des parents vers leurs déclinaisons, et propage dispo/suivi.
 *
 * ------------------------------------------------------------------------------
 * D'OÙ VIENT LA DONNÉE
 * ------------------------------------------------------------------------------
 *
 * ADD tenait le stock par déclinaison dans les GAMMES Sage (f_gamstock), jamais lues par
 * la reprise : les parents Dolibarr ont reçu le TOTAL, les déclinaisons sont nées à zéro.
 * `migrationdata/ventilation_gammes_20260905.csv` est la résolution de ces gammes vers
 * les combinaisons boutique (photo synchrone du 05/09, 565 lignes sur 572 — 98,8 %,
 * les 7 restantes arbitrées à part) :
 *
 *     AR_Ref;id_product;id_product_attribute;valeur;qte
 *
 * ## Ce que fait le script
 *
 * 1. **Ventilation** : pour chaque ligne, SORTIE de `qte` sur le parent (retrouvé par
 *    `ref_ext = 'SAGE:<AR_Ref>'`) et ENTRÉE de `qte` sur la déclinaison (retrouvée par
 *    son lien boutique `llx_prestasync_product.fk_product_presta_attribute`), en
 *    mouvements tracés « Ventilation déclinaisons (gammes ADD du 05/09/2026) ».
 *    AUCUNE création de stock : chaque famille conserve exactement son total.
 *
 * 2. **Dispo/suivi** : chaque déclinaison (réf `#XXXXX-NNN`) hérite des extrafields
 *    aerotoolbox (`aerotb_availability`, `aerotb_tracking`) de son parent `#XXXXX` —
 *    l'arbitrage client du 03/09 (import_disposuivi) vaut pour toute la famille.
 *
 * ## Les garde-fous
 *
 * - Le stock du parent dans l'entrepôt est PLAFOND : si les ventes depuis le 05/09 l'ont
 *   fait descendre sous la quantité de la gamme, la ligne est plafonnée au disponible et
 *   l'écart est signalé — c'est la dérive post-coupure, à solder à l'inventaire.
 * - Les quantités NÉGATIVES d'ADD (9 lignes, des -1 d'inventaire) ne sont pas ventilées :
 *   signalées seulement, le solde reste porté par le parent.
 * - Une déclinaison déjà pourvue en stock n'est PAS re-ventilée (rejouable).
 *
 * Usage :
 *   php ventilate_declinaisons.php --entrepot=1                 simulation
 *   php ventilate_declinaisons.php --entrepot=1 --confirm       applique
 *   php ventilate_declinaisons.php --entrepot=1 --limit=10 --confirm
 *   php ventilate_declinaisons.php --entrepot=1 --file=... --user=LOGIN
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
const SAMPLES = 10;

/** Libellé des mouvements de ventilation. */
const MVT_LABEL = 'Ventilation déclinaisons (gammes ADD du 05/09/2026)';


/*
 * Arguments
 */

$confirm   = false;
$limit     = 0;
$userLogin = '';
$file      = '';
$entrepot  = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--entrepot=(\d+)$/', $arg, $m)) {
        $entrepot = (int) $m[1];
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." --entrepot=N [--confirm] [--limit=N] [--file=CSV] [--user=LOGIN]\n";
        exit(1);
    }
}

if ($entrepot <= 0) {
    echo "--entrepot=N est obligatoire (rowid llx_entrepot de l'entrepôt cible).\n";
    exit(1);
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
 * L'entrepôt existe.
 */

$resql = $db->query('SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'entrepot WHERE rowid = '.((int) $entrepot));
if (!$resql || !($oent = $db->fetch_object($resql))) {
    echo "Entrepôt ".$entrepot." introuvable dans llx_entrepot.\n";
    exit(1);
}
$entrepotRef = $oent->ref;


/*
 * Le fichier : le plus récent de migrationdata/, sauf --file.
 */

if ($file === '') {
    $candidates = glob(__DIR__.'/../migrationdata/ventilation_gammes_*.csv');
    if (empty($candidates)) {
        echo "Aucun fichier migrationdata/ventilation_gammes_*.csv trouvé. Précisez --file=.\n";
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


/*
 * Index en mémoire.
 */

// Déclinaisons : id combinaison boutique -> id produit Dolibarr.
$childByAttr = array();
$resql = $db->query('SELECT fk_product_presta_attribute att, fk_product_doli doli'
    .' FROM '.MAIN_DB_PREFIX.'prestasync_product WHERE fk_product_presta_attribute > 0');
while ($resql && ($o = $db->fetch_object($resql))) {
    $childByAttr[(int) $o->att] = (int) $o->doli;
}

// Parents : référence ADD canonique -> id produit Dolibarr.
$parentByRef = array();
$resql = $db->query("SELECT rowid, ref_ext FROM ".MAIN_DB_PREFIX."product"
    ." WHERE entity IN (".getEntity('product').") AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = canon_ref(substr($o->ref_ext, strlen(REF_EXT_PREFIX)));
    if ($key !== '' && !isset($parentByRef[$key])) {
        $parentByRef[$key] = (int) $o->rowid;
    }
}

// Stock courant dans l'entrepôt cible, pour tous les produits : plafond côté parents,
// détection « déjà ventilé » côté déclinaisons. Consommé au fil des lignes.
$stockInWh = array();
$resql = $db->query('SELECT fk_product, reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE fk_entrepot = '.((int) $entrepot));
while ($resql && ($o = $db->fetch_object($resql))) {
    $stockInWh[(int) $o->fk_product] = (float) $o->reel;
}


/*
 * PASSE 1 — ventilation du stock.
 */

$stats = array(
    'lignes'        => 0,
    'ventilees'     => 0,
    'unites'        => 0.0,
    'plafonnees'    => 0,
    'unites_perdues' => 0.0,
    'deja'          => 0,
    'negatives'     => 0,
    'enfant_absent' => 0,
    'parent_absent' => 0,
    'erreur'        => 0,
);
$samples = array('plafonnees' => array(), 'negatives' => array(), 'enfant_absent' => array(),
    'parent_absent' => array(), 'erreur' => array());

echo "Script      : ventilation du stock des déclinaisons + dispo/suivi\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Fichier     : ".$file."\n";
echo "Entrepôt    : ".$entrepot." (".$entrepotRef.")\n";
echo str_repeat('-', 60)."\n";

$fh = fopen($file, 'r');
if (!$fh) {
    echo "Ouverture impossible : ".$file."\n";
    exit(1);
}
fgetcsv($fh, 0, ';');   // en-tête

if ($confirm) {
    $db->begin();
}

$parentCache = array();
$writes = 0;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 5) {
        continue;
    }
    $stats['lignes']++;

    $refAdd = canon_ref($row[0]);
    $attrId = (int) $row[2];
    $valeur = trim((string) $row[3]);
    $qte    = (float) str_replace(',', '.', $row[4]);

    if ($qte <= 0) {
        // Les -1 d'inventaire ADD : rien à ventiler, le solde reste au parent.
        $stats['negatives']++;
        if (count($samples['negatives']) < SAMPLES) {
            $samples['negatives'][] = $refAdd.' « '.$valeur.' » ('.$qte.')';
        }
        continue;
    }

    if (!isset($childByAttr[$attrId])) {
        $stats['enfant_absent']++;
        if (count($samples['enfant_absent']) < SAMPLES) {
            $samples['enfant_absent'][] = $refAdd.' « '.$valeur.' » (combinaison '.$attrId.')';
        }
        continue;
    }
    if (!isset($parentByRef[$refAdd])) {
        $stats['parent_absent']++;
        if (count($samples['parent_absent']) < SAMPLES) {
            $samples['parent_absent'][] = $refAdd;
        }
        continue;
    }
    $childId  = $childByAttr[$attrId];
    $parentId = $parentByRef[$refAdd];

    // Déjà ventilé : la déclinaison porte du stock dans l'entrepôt, on ne double pas.
    if (!empty($stockInWh[$childId])) {
        $stats['deja']++;
        continue;
    }

    // Plafond : jamais plus que ce que le parent détient encore (dérive post-05/09).
    $dispo = isset($stockInWh[$parentId]) ? $stockInWh[$parentId] : 0.0;
    $mv    = min($qte, max(0.0, $dispo));
    if ($mv < $qte) {
        $stats['plafonnees']++;
        $stats['unites_perdues'] += ($qte - $mv);
        if (count($samples['plafonnees']) < SAMPLES) {
            $samples['plafonnees'][] = $refAdd.' « '.$valeur.' » : '.$qte.' demandées, '.$mv.' disponibles';
        }
    }
    if ($mv <= 0) {
        continue;
    }

    $stats['ventilees']++;
    $stats['unites'] += $mv;
    $stockInWh[$parentId] = $dispo - $mv;
    $writes++;

    if ($confirm) {
        if (!isset($parentCache[$parentId])) {
            $p = new Product($db);
            if ($p->fetch($parentId) <= 0) {
                $stats['erreur']++;
                if (count($samples['erreur']) < SAMPLES) {
                    $samples['erreur'][] = $refAdd.' (lecture parent) : '.$p->error;
                }
                continue;
            }
            $parentCache[$parentId] = $p;
        }
        $parent = $parentCache[$parentId];

        $child = new Product($db);
        if ($child->fetch($childId) <= 0) {
            $stats['erreur']++;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $refAdd.' « '.$valeur.' » (lecture déclinaison) : '.$child->error;
            }
            continue;
        }

        // Sortie du parent puis entrée sur la déclinaison — movement : 0 = entrée, 1 = sortie.
        if ($parent->correct_stock($user, $entrepot, $mv, 1, MVT_LABEL) < 0) {
            $stats['erreur']++;
            $stats['ventilees']--;
            $stats['unites'] -= $mv;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $refAdd.' (sortie parent) : '.$parent->error;
            }
            continue;
        }
        if ($child->correct_stock($user, $entrepot, $mv, 0, MVT_LABEL) < 0) {
            $stats['erreur']++;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $refAdd.' « '.$valeur.' » (entrée déclinaison) : '.$child->error;
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


/*
 * PASSE 2 — dispo/suivi : chaque déclinaison #XXXXX-NNN hérite de son parent #XXXXX.
 * Toutes les déclinaisons liées sont couvertes, y compris celles sans stock.
 */

$stats2 = array('vues' => 0, 'dispo_posees' => 0, 'suivi_poses' => 0, 'conformes' => 0,
    'parent_absent' => 0, 'erreur' => 0);
$samples2 = array('erreur' => array(), 'parent_absent' => array());

$sql = "SELECT c.rowid child_id, c.ref child_ref,"
    ." ce.aerotb_availability c_avail, ce.aerotb_tracking c_track,"
    ." p.rowid parent_id, pe.aerotb_availability p_avail, pe.aerotb_tracking p_track"
    ." FROM ".MAIN_DB_PREFIX."product c"
    ." INNER JOIN ".MAIN_DB_PREFIX."prestasync_product pp ON pp.fk_product_doli = c.rowid AND pp.fk_product_presta_attribute > 0"
    ." LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields ce ON ce.fk_object = c.rowid"
    ." LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.ref = SUBSTRING_INDEX(c.ref, '-', 1) AND p.entity IN (".getEntity('product').")"
    ." LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid"
    ." WHERE c.entity IN (".getEntity('product').") AND c.ref REGEXP '^#[0-9]{5}-[0-9]{3}\$'"
    ." GROUP BY c.rowid";
$resql = $db->query($sql);
$rows = array();
while ($resql && ($o = $db->fetch_object($resql))) {
    $rows[] = $o;
}

foreach ($rows as $o) {
    $stats2['vues']++;
    if (empty($o->parent_id)) {
        $stats2['parent_absent']++;
        if (count($samples2['parent_absent']) < SAMPLES) {
            $samples2['parent_absent'][] = $o->child_ref;
        }
        continue;
    }
    $needAvail = ((int) $o->p_avail > 0 && (int) $o->c_avail !== (int) $o->p_avail);
    $needTrack = ((int) $o->p_track > 0 && (int) $o->c_track !== (int) $o->p_track);
    if (!$needAvail && !$needTrack) {
        $stats2['conformes']++;
        continue;
    }

    if ($confirm) {
        $product = new Product($db);
        $product->id = (int) $o->child_id;
        $failed = false;
        if ($needAvail) {
            $product->array_options['options_aerotb_availability'] = (int) $o->p_avail;
            if ($product->updateExtraField('aerotb_availability') < 0) {
                $failed = true;
            }
        }
        if (!$failed && $needTrack) {
            $product->array_options['options_aerotb_tracking'] = (int) $o->p_track;
            if ($product->updateExtraField('aerotb_tracking') < 0) {
                $failed = true;
            }
        }
        if ($failed) {
            $stats2['erreur']++;
            if (count($samples2['erreur']) < SAMPLES) {
                $samples2['erreur'][] = $o->child_ref.' : '.$product->error;
            }
            continue;
        }
    }
    if ($needAvail) {
        $stats2['dispo_posees']++;
    }
    if ($needTrack) {
        $stats2['suivi_poses']++;
    }
}

if ($confirm) {
    if ($stats['erreur'] > 0 || $stats2['erreur'] > 0) {
        $db->rollback();
        echo "\nÉCHEC — ".($stats['erreur'] + $stats2['erreur'])." erreur(s), tout est annulé (transaction) :\n";
        foreach (array_merge($samples['erreur'], $samples2['erreur']) as $e) {
            echo "  - ".$e."\n";
        }
        exit(1);
    }
    $db->commit();
}


/*
 * Rapport.
 */

printf("\n— Ventilation du stock —\n");
printf("Lignes lues                : %s\n", $stats['lignes']);
printf("Mouvements %s   : %s  (%s unité(s) déplacées du parent vers la déclinaison)\n",
    $confirm ? 'faits    ' : 'à faire  ', $stats['ventilees'], rtrim(rtrim(number_format($stats['unites'], 2, '.', ' '), '0'), '.'));
printf("Déjà ventilées (stock > 0) : %s\n", $stats['deja']);
if ($stats['plafonnees'] > 0) {
    printf("Plafonnées (dérive 05/09)  : %s — %s unité(s) non ventilées, à solder à l'inventaire\n",
        $stats['plafonnees'], rtrim(rtrim(number_format($stats['unites_perdues'], 2, '.', ' '), '0'), '.'));
    foreach ($samples['plafonnees'] as $e) {
        echo "  - ".$e."\n";
    }
}
if ($stats['negatives'] > 0) {
    printf("Négatives ADD, ignorées    : %s — le solde reste au parent : %s\n", $stats['negatives'], implode(', ', $samples['negatives']));
}

$ecarts = array('enfant_absent' => 'déclinaison non importée (lien boutique absent)',
    'parent_absent' => 'parent SAGE introuvable', 'erreur' => 'mouvement refusé');
foreach ($ecarts as $key => $lib) {
    if ($stats[$key] > 0) {
        printf("%7s  %s — %s\n", $stats[$key], $lib, implode(', ', $samples[$key]));
    }
}

printf("\n— Dispo / suivi (héritage du parent) —\n");
printf("Déclinaisons vues          : %s  (déjà conformes : %s)\n", $stats2['vues'], $stats2['conformes']);
printf("Disponibilités %s : %s\n", $confirm ? 'posées ' : 'à poser', $stats2['dispo_posees']);
printf("Suivis %s         : %s\n", $confirm ? 'posés ' : 'à poser', $stats2['suivi_poses']);
if ($stats2['parent_absent'] > 0) {
    printf("%7s  parent #XXXXX introuvable — %s\n", $stats2['parent_absent'], implode(', ', $samples2['parent_absent']));
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit(($stats['erreur'] + $stats2['erreur']) > 0 ? 1 : 0);
