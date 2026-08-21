<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_consignes.php
 * \ingroup aeromigration
 * \brief   Pose garantie, consignes et notes produits arbitrées par le client.
 *
 * ------------------------------------------------------------------------------
 * D'OÙ VIENT LA DONNÉE, ET POURQUOI ELLE PASSE PAR UN FICHIER
 * ------------------------------------------------------------------------------
 *
 * L'ancien ERP mélangeait dans un même texte libre les durées de garantie, les consignes
 * de préparation/emballage et des informations d'approvisionnement datées. Le client a
 * arbitré chaque texte dans un fichier Excel (data/2608_consignes.xlsx) : nature retenue,
 * durée en mois, texte reformulé à conserver, ou suppression pure.
 *
 * `migrationdata/consignes_produits_*.csv` est la forme propre de cet arbitrage — clés
 * portables uniquement (la référence article ADD, jamais d'id Dolibarr) :
 *
 *     ref_add;champ;garantie_mois;texte
 *
 * `champ` dit où la valeur se pose sur le produit (résolu par `ref_ext` « SAGE:<ref> ») :
 *
 *     garantie  ->  extrafields aerotb_warranty = oui  +  aerotb_warranty_months
 *     prep      ->  extrafield  aerotb_prep_notes   (consigne de préparation)
 *     pack      ->  extrafield  aerotb_pack_notes   (consigne d'emballage)
 *     vente     ->  extrafield  aerotb_sale_notes   (consigne de vente)
 *     note      ->  llx_product.note_public         (le bloc « Notes » de la fiche)
 *
 * Les lignes « SUPPRESSION » de l'arbitrage n'apparaissent pas dans le fichier : ne rien
 * remonter EST la décision, il n'y a rien à écrire ni à effacer.
 *
 * ## Écriture et idempotence
 *
 * Une valeur déjà conforme n'est pas réécrite : le script est rejouable. Une valeur
 * DIFFÉRENTE déjà en place est REMPLACÉE — l'arbitrage du client fait foi — mais chaque
 * remplacement est compté et listé au rapport, pour être visible.
 *
 * Usage :
 *   php import_consignes.php                  simulation : ventilation complète, rien d'écrit
 *   php import_consignes.php --confirm        applique
 *   php import_consignes.php --limit=20 --confirm     lot d'essai
 *   php import_consignes.php --file=/chemin/vers/fichier.csv
 *   php import_consignes.php --user=LOGIN
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

/** Champs du fichier -> extrafield produit cible (les consignes ; garantie et note à part). */
const CONSIGNE_FIELDS = array(
    'prep'  => 'aerotb_prep_notes',
    'pack'  => 'aerotb_pack_notes',
    'vente' => 'aerotb_sale_notes',
);


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
    $candidates = glob(__DIR__.'/../migrationdata/consignes_produits_*.csv');
    if (empty($candidates)) {
        echo "Aucun fichier migrationdata/consignes_produits_*.csv trouvé.\n";
        echo "Générez-le depuis l'arbitrage client (data/2608_consignes.xlsx), ou précisez --file=.\n";
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
 * Référence ADD canonique : zéros de tête neutralisés. Le fichier vient d'Excel, qui a
 * converti la colonne en nombre — « 00175 » y est devenu « 175 » — quand `ref_ext` porte
 * la référence exacte. Même appariement canonique que le catalogue client.
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
 * Index en mémoire : référence ADD canonique -> rowid produit (-1 si deux produits
 * se disputent la même référence canonique — jamais deviné, écarté et compté).
 */

$prodByRef = array();
$resql = $db->query('SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'product'
    ." WHERE entity IN (".getEntity('product').") AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = canon_ref(substr($o->ref_ext, strlen(REF_EXT_PREFIX)));
    if ($key !== '') {
        $prodByRef[$key] = isset($prodByRef[$key]) ? -1 : (int) $o->rowid;
    }
}
if ($resql) {
    $db->free($resql);
}


/*
 * Parcours du fichier.
 */

$stats = array(
    'lignes'          => 0,
    'maj'             => 0,
    'deja'            => 0,
    'remplace'        => 0,
    'produit_absent'  => 0,
    'ref_ambigue'     => 0,
    'champ_inconnu'   => 0,
    'valeur_invalide' => 0,
    'erreur'          => 0,
);
$parChamp = array();
$samples  = array('produit_absent' => array(), 'ref_ambigue' => array(), 'remplace' => array(), 'erreur' => array());

$fh = fopen($file, 'r');
if (!$fh) {
    echo "Ouverture impossible : ".$file."\n";
    exit(1);
}
$header = fgetcsv($fh, 0, ';');   // en-tête, ignoré — l'ordre des colonnes est le contrat

echo "Script      : garantie, consignes et notes produits (arbitrage client)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Fichier     : ".$file."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

// Un même produit revient pour plusieurs champs : l'objet chargé est réutilisé.
$product = null;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 4) {
        continue;
    }
    $stats['lignes']++;

    list($refAdd, $champ, $mois, $texte) = $row;
    $refAdd = canon_ref($refAdd);
    $champ  = trim((string) $champ);
    $mois   = (int) trim((string) $mois);
    $texte  = trim((string) $texte);

    if ($champ !== 'garantie' && $champ !== 'note' && !isset(CONSIGNE_FIELDS[$champ])) {
        $stats['champ_inconnu']++;
        continue;
    }
    if (($champ === 'garantie' && $mois <= 0) || ($champ !== 'garantie' && $texte === '')) {
        $stats['valeur_invalide']++;
        continue;
    }

    // ── Résolution du produit ──────────────────────────────────────────────
    if (!isset($prodByRef[$refAdd])) {
        $stats['produit_absent']++;
        if (count($samples['produit_absent']) < SAMPLES) {
            $samples['produit_absent'][] = $refAdd;
        }
        continue;
    }
    if ($prodByRef[$refAdd] < 0) {
        $stats['ref_ambigue']++;
        if (count($samples['ref_ambigue']) < SAMPLES) {
            $samples['ref_ambigue'][] = $refAdd;
        }
        continue;
    }
    $prodId = $prodByRef[$refAdd];

    if ($product === null || (int) $product->id !== $prodId) {
        $product = new Product($db);
        if ($product->fetch($prodId) <= 0) {
            $stats['erreur']++;
            $product = null;
            continue;
        }
    }

    // ── Comparaison à l'existant, écriture seulement si écart ──────────────
    $res = 1;
    if ($champ === 'garantie') {
        $curOn    = !empty($product->array_options['options_aerotb_warranty']);
        $curMonth = (int) ($product->array_options['options_aerotb_warranty_months'] ?? 0);
        if ($curOn && $curMonth === $mois) {
            $stats['deja']++;
            continue;
        }
        if ($curOn && $curMonth > 0 && $curMonth !== $mois) {
            $stats['remplace']++;
            if (count($samples['remplace']) < SAMPLES) {
                $samples['remplace'][] = $refAdd.' (garantie '.$curMonth.' -> '.$mois.')';
            }
        }
        $stats['maj']++;
        $parChamp[$champ] = ($parChamp[$champ] ?? 0) + 1;
        if ($confirm) {
            $product->array_options['options_aerotb_warranty'] = 1;
            $product->array_options['options_aerotb_warranty_months'] = $mois;
            $res = $product->updateExtraField('aerotb_warranty');
            if ($res >= 0) {
                $res = $product->updateExtraField('aerotb_warranty_months');
            }
        }
    } elseif ($champ === 'note') {
        $cur = trim((string) $product->note_public);
        if ($cur === $texte) {
            $stats['deja']++;
            continue;
        }
        if ($cur !== '') {
            $stats['remplace']++;
            if (count($samples['remplace']) < SAMPLES) {
                $samples['remplace'][] = $refAdd.' (note)';
            }
        }
        $stats['maj']++;
        $parChamp[$champ] = ($parChamp[$champ] ?? 0) + 1;
        if ($confirm) {
            $res = $product->setValueFrom('note_public', $texte, '', null, 'text', '', $user);
            if ($res >= 0) {
                $product->note_public = $texte;
            }
        }
    } else {
        $key = 'options_'.CONSIGNE_FIELDS[$champ];
        $cur = trim((string) ($product->array_options[$key] ?? ''));
        if ($cur === $texte) {
            $stats['deja']++;
            continue;
        }
        if ($cur !== '') {
            $stats['remplace']++;
            if (count($samples['remplace']) < SAMPLES) {
                $samples['remplace'][] = $refAdd.' ('.$champ.')';
            }
        }
        $stats['maj']++;
        $parChamp[$champ] = ($parChamp[$champ] ?? 0) + 1;
        if ($confirm) {
            $product->array_options[$key] = $texte;
            $res = $product->updateExtraField(CONSIGNE_FIELDS[$champ]);
        }
    }

    if ($confirm && $res < 0) {
        $stats['erreur']++;
        $stats['maj']--;
        $parChamp[$champ]--;
        if (count($samples['erreur']) < SAMPLES) {
            $samples['erreur'][] = $refAdd.' ('.$champ.') : '.$product->error;
        }
    }

    if ($limit > 0 && $stats['maj'] >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}
fclose($fh);

if ($confirm) {
    $db->commit();
}

printf("\nLignes lues          : %s\n", number_format($stats['lignes'], 0, ',', ' '));
printf("Valeurs %s : %s — déjà à jour : %s\n",
    $confirm ? 'écrites ' : 'à écrire',
    number_format($stats['maj'], 0, ',', ' '), number_format($stats['deja'], 0, ',', ' '));
foreach ($parChamp as $c => $n) {
    printf("  %-9s %s\n", $c, number_format($n, 0, ',', ' '));
}

$ecarts = array(
    'remplace'        => 'valeur différente déjà en place, remplacée (le fichier fait foi)',
    'produit_absent'  => 'référence ADD sans produit repris',
    'ref_ambigue'     => 'référence canonique portée par plusieurs produits',
    'champ_inconnu'   => 'champ inconnu dans le fichier',
    'valeur_invalide' => 'durée ou texte manquant dans le fichier',
    'erreur'          => 'écriture refusée',
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
