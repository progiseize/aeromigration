<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/import_disposuivi.php
 * \ingroup aeromigration
 * \brief   Applique l'arbitrage client sur la disponibilité et le suivi des produits.
 *
 * ------------------------------------------------------------------------------
 * D'OÙ VIENT LA DONNÉE
 * ------------------------------------------------------------------------------
 *
 * Le client a passé en revue tout son catalogue (16 008 lignes) et livré
 * `migrationdata/disposuivi_migration.csv` : pour chaque article, la disponibilité et le
 * suivi VOULUS — la passe de qualification manuelle par-dessus la reprise, qui couvre
 * notamment les articles jamais qualifiés par la surcouche ADD (A3 : `disponibilite_origine`
 * à zéro). Les libellés du fichier collent exactement aux dictionnaires d'aerotoolbox
 * (`c_aerotoolbox_availability`, `c_aerotoolbox_tracking`).
 *
 * Format (Windows-1252, séparateur « ; », en-tête) :
 *
 *     Numero produit;Libelle;Fournisseur;Réf fournisseur;EAN;Disponibilite;Suivi
 *
 * Seules trois colonnes sont lues : la référence (PADÉE À CINQ CHIFFRES par l'outil
 * d'export, là où ADD et `ref_ext` ne le sont pas — appariement canonique, zéros de tête
 * neutralisés), la disponibilité et le suivi.
 *
 * ## Quatre valeurs spéciales, arbitrées par le client (03/09/2026)
 *
 * - **CORRIGÉ SUR ADD** (114) : le client a corrigé la fiche directement dans ADD — rien
 *   à faire ici, la reprise `product` porte déjà (ou portera) la bonne valeur.
 * - **EMPLACEMENT** (395, série 90000+) : ADD gérait ses emplacements comme des articles.
 *   « Ces produits peuvent être supprimés » — supprimés via Product::delete(). Vérifié en
 *   base : aucun document, stock, tarif ni liaison boutique ne les référence. ⚠ Ils
 *   existent toujours dans `f_article` : un rejeu de `product` les RECRÉE — ce script se
 *   rejoue donc après lui (jour J compris), tant que le client ne les a pas supprimés
 *   dans ADD.
 * - **PRESTATION** (46) : « ces produits doivent devenir des services » — type passé à
 *   service (`setValueFrom`, sans déclencheur : 38 sont liées à la boutique et un
 *   PRODUCT_MODIFY partirait en synchronisation), disponibilité posée sur l'entrée
 *   « Prestation » du dictionnaire. Trois portent du stock (#07338 −4, #09163 −1,
 *   #09647 −1 — deux recoupent S8) : soldé par une correction de stock AVANT la
 *   bascule, un service ne stockant pas.
 * - **PRODUIT EN DEPOT-VENTE** (3) : fonctionnalité non développée — rien d'écrit,
 *   signalées au rapport. À noter : contrairement à l'intuition du client, les trois sont
 *   liées à la boutique (llx_prestasync_product).
 *
 * Tout le reste va dans les extrafields `aerotb_availability` et `aerotb_tracking`, par
 * correspondance de libellé. Un suivi vide ou « -- » ne touche à rien.
 *
 * ## Écriture et idempotence
 *
 * Une valeur déjà conforme n'est pas réécrite : le script est rejouable. Une valeur
 * DIFFÉRENTE déjà en place est REMPLACÉE — l'arbitrage du client fait foi. Un emplacement
 * déjà supprimé est reconnu et compté comme tel, pas comme une anomalie.
 *
 * Usage :
 *   php import_disposuivi.php                  simulation : ventilation complète, rien d'écrit
 *   php import_disposuivi.php --confirm        applique
 *   php import_disposuivi.php --limit=20 --confirm     lot d'essai
 *   php import_disposuivi.php --file=/chemin/vers/fichier.csv
 *   php import_disposuivi.php --user=LOGIN
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
    $candidates = glob(__DIR__.'/../migrationdata/disposuivi_*.csv');
    if (empty($candidates)) {
        echo "Aucun fichier migrationdata/disposuivi_*.csv trouvé. Précisez --file=.\n";
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
 * chiffres (« 00456 ») ce qu'ADD et `ref_ext` portent sans zéros (« 456 »).
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
 * Clé de rapprochement d'un libellé de dictionnaire : minuscules, espaces réduits.
 *
 * @param  string $label Libellé brut (déjà en UTF-8)
 * @return string
 */
function label_key($label)
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $label)), 'UTF-8');
}


/*
 * Dictionnaires aerotoolbox : libellé -> rowid. Leur absence signifie que le module
 * n'est pas activé — rien ne pourrait s'écrire, on s'arrête avec un message clair.
 */

$availByLabel = array();
$trackByLabel = array();
foreach (array('availability' => &$availByLabel, 'tracking' => &$trackByLabel) as $dict => &$map) {
    $resql = $db->query('SELECT rowid, label FROM '.MAIN_DB_PREFIX.'c_aerotoolbox_'.$dict, 1);
    if (!$resql) {
        echo "Dictionnaire c_aerotoolbox_".$dict." introuvable : activez le module aerotoolbox.\n";
        exit(1);
    }
    while ($o = $db->fetch_object($resql)) {
        $map[label_key($o->label)] = (int) $o->rowid;
    }
    $db->free($resql);
}
unset($map);


/*
 * Index en mémoire : référence canonique -> (rowid, type, dispo courante, suivi courant) ;
 * -1 si deux produits se disputent la même référence canonique — jamais deviné, écarté et
 * compté. Les valeurs courantes sont préchargées ici : la comparaison se fait en mémoire,
 * sans recharger 16 000 produits (Product::fetch() relit aussi les sept niveaux de prix —
 * c'est lui qui rendait le premier jet interminable).
 */

$prodByRef = array();
$resql = $db->query('SELECT p.rowid, p.ref_ext, p.fk_product_type,'
    .' e.aerotb_availability as avail, e.aerotb_tracking as track'
    .' FROM '.MAIN_DB_PREFIX.'product as p'
    .' LEFT JOIN '.MAIN_DB_PREFIX.'product_extrafields as e ON e.fk_object = p.rowid'
    ." WHERE p.entity IN (".getEntity('product').") AND p.ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'");
while ($resql && ($o = $db->fetch_object($resql))) {
    $key = canon_ref(substr($o->ref_ext, strlen(REF_EXT_PREFIX)));
    if ($key !== '') {
        $prodByRef[$key] = isset($prodByRef[$key]) ? -1
            : array((int) $o->rowid, (int) $o->fk_product_type, (int) $o->avail, (int) $o->track);
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
    'dispo_posees'   => 0,
    'suivi_poses'    => 0,
    'deja'           => 0,
    'supprimes'      => 0,
    'deja_supprimes' => 0,
    'services'       => 0,
    'deja_services'  => 0,
    'stock_solde'    => 0,
    'depotvente'     => 0,
    'corrige_add'    => 0,
    'suivi_vide'     => 0,
    'produit_absent' => 0,
    'ref_ambigue'    => 0,
    'dispo_inconnue' => 0,
    'suivi_inconnu'  => 0,
    'erreur'         => 0,
);
$samples = array('produit_absent' => array(), 'ref_ambigue' => array(), 'dispo_inconnue' => array(),
    'suivi_inconnu' => array(), 'erreur' => array(), 'depotvente' => array(), 'stock_solde' => array());

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
    printf("\r  %s/%s lignes   dispo %s   suivi %s   conformes %s   suppr. %s   erreurs %s",
        str_pad(number_format($stats['lignes'], 0, ',', ' '), 6, ' ', STR_PAD_LEFT),
        number_format($total, 0, ',', ' '),
        number_format($stats['dispo_posees'], 0, ',', ' '),
        number_format($stats['suivi_poses'], 0, ',', ' '),
        number_format($stats['deja'], 0, ',', ' '),
        number_format($stats['supprimes'], 0, ',', ' '),
        number_format($stats['erreur'], 0, ',', ' '));
    flush();
}

echo "Script      : disponibilité et suivi des produits (arbitrage client)\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Fichier     : ".$file."\n";
echo str_repeat('-', 60)."\n";

if ($confirm) {
    $db->begin();
}

$writes = 0;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 7) {
        continue;
    }
    $stats['lignes']++;
    if ($stats['lignes'] % 500 === 0) {
        show_progress($stats, $total);
    }

    // Le fichier est en Windows-1252 : converti champ par champ, les libellés accentués
    // (« Commercialisation arrêtée ») doivent rejoindre les dictionnaires, en UTF-8.
    $refAdd = canon_ref(mb_convert_encoding($row[0], 'UTF-8', 'Windows-1252'));
    $dispo  = label_key(mb_convert_encoding($row[5], 'UTF-8', 'Windows-1252'));
    $suivi  = label_key(mb_convert_encoding($row[6], 'UTF-8', 'Windows-1252'));

    // ── Corrigé dans ADD : la reprise fait foi, rien à faire ici ────────────
    if ($dispo === 'corrigé sur add') {
        $stats['corrige_add']++;
        continue;
    }

    // ── Résolution du produit ──────────────────────────────────────────────
    if (!isset($prodByRef[$refAdd])) {
        if ($dispo === 'emplacement') {
            // Déjà supprimé par un passage précédent : c'est l'état voulu.
            $stats['deja_supprimes']++;
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
    list($prodId, $prodType, $curAvail, $curTrack) = $prodByRef[$refAdd];

    // ── Emplacements : des articles qui n'auraient jamais dû en être ────────
    if ($dispo === 'emplacement') {
        $stats['supprimes']++;
        $writes++;
        if ($confirm) {
            $product = new Product($db);
            if ($product->fetch($prodId) <= 0 || $product->delete($user) <= 0) {
                $stats['erreur']++;
                $stats['supprimes']--;
                $writes--;
                if (count($samples['erreur']) < SAMPLES) {
                    $samples['erreur'][] = $refAdd.' (suppression) : '.$product->error;
                }
            } else {
                unset($prodByRef[$refAdd]);
            }
        }
        if ($limit > 0 && $writes >= $limit) {
            echo "Limite atteinte (".$limit.").\n";
            break;
        }
        continue;
    }

    // ── Dépôt-vente : fonctionnalité non développée, signalé seulement ─────
    if ($dispo === 'produit en depot-vente') {
        $stats['depotvente']++;
        $samples['depotvente'][] = $refAdd;
        continue;
    }

    // ── Le reste écrit sur le produit : chargé une fois, écrit champ à champ ──
    $isPrestation = ($dispo === 'prestation');
    $availTarget  = isset($availByLabel[$dispo]) ? $availByLabel[$dispo] : 0;
    if ($availTarget <= 0) {
        $stats['dispo_inconnue']++;
        if (count($samples['dispo_inconnue']) < SAMPLES) {
            $samples['dispo_inconnue'][] = $refAdd.' « '.$dispo.' »';
        }
        continue;
    }

    $trackTarget = 0;
    if ($suivi === '' || $suivi === '--' || $suivi === 'corrigé sur add') {
        $stats['suivi_vide']++;
    } elseif (isset($trackByLabel[$suivi])) {
        $trackTarget = $trackByLabel[$suivi];
    } else {
        $stats['suivi_inconnu']++;
        if (count($samples['suivi_inconnu']) < SAMPLES) {
            $samples['suivi_inconnu'][] = $refAdd.' « '.$suivi.' »';
        }
    }

    $touched = false;
    $failed  = false;

    // L'objet complet n'est nécessaire que pour la bascule en service (solde de stock) :
    // les extrafields s'écrivent avec le seul identifiant, et la comparaison est déjà
    // faite sur l'index préchargé.
    $product = new Product($db);
    $product->id = $prodId;

    // ── Prestations : le stock d'abord, le type ensuite, la dispo enfin ─────
    if ($isPrestation && $prodType !== Product::TYPE_SERVICE) {
        if ($confirm && $product->fetch($prodId) <= 0) {
            $stats['erreur']++;
            if (count($samples['erreur']) < SAMPLES) {
                $samples['erreur'][] = $refAdd.' (lecture) : '.$product->error;
            }
            continue;
        }
        // Un service ne stocke pas : les reliquats (le trio de S8) sont soldés par une
        // correction tracée, tant que le produit peut encore mouvementer.
        $sql   = 'SELECT fk_entrepot, reel FROM '.MAIN_DB_PREFIX.'product_stock'
            .' WHERE fk_product = '.((int) $prodId).' AND reel <> 0';
        $resql = $db->query($sql);
        $stockRows = array();
        while ($resql && ($o = $db->fetch_object($resql))) {
            $stockRows[] = $o;
        }
        if ($resql) {
            $db->free($resql);
        }
        foreach ($stockRows as $o) {
            $qty = (float) $o->reel;
            $stats['stock_solde']++;
            $samples['stock_solde'][] = $refAdd.' ('.($qty > 0 ? '-' : '+').abs($qty).')';
            if ($confirm) {
                // movement : 0 = entrée, 1 = sortie — toujours en quantité positive.
                $res = $product->correct_stock($user, (int) $o->fk_entrepot, abs($qty), ($qty > 0 ? 1 : 0),
                    'Solde avant passage en service (arbitrage client du 03/09/2026)');
                if ($res < 0) {
                    $failed = true;
                    if (count($samples['erreur']) < SAMPLES) {
                        $samples['erreur'][] = $refAdd.' (solde du stock) : '.$product->error;
                    }
                }
            }
        }

        if (!$failed) {
            $stats['services']++;
            $touched = true;
            if ($confirm) {
                // setValueFrom, PAS update() : 38 de ces fiches sont liées à la boutique,
                // et un PRODUCT_MODIFY partirait en synchronisation pour un simple type.
                if ($product->setValueFrom('fk_product_type', Product::TYPE_SERVICE, '', null, 'int', '', $user) < 0) {
                    $failed = true;
                    $stats['services']--;
                    if (count($samples['erreur']) < SAMPLES) {
                        $samples['erreur'][] = $refAdd.' (type service) : '.$product->error;
                    }
                }
            }
        }
    } elseif ($isPrestation) {
        $stats['deja_services']++;
    }

    // ── Disponibilité et suivi : extrafields aerotoolbox ────────────────────
    if (!$failed) {
        if ($curAvail !== $availTarget) {
            $stats['dispo_posees']++;
            $touched = true;
            if ($confirm) {
                $product->array_options['options_aerotb_availability'] = $availTarget;
                if ($product->updateExtraField('aerotb_availability') < 0) {
                    $failed = true;
                    $stats['dispo_posees']--;
                    if (count($samples['erreur']) < SAMPLES) {
                        $samples['erreur'][] = $refAdd.' (disponibilité) : '.$product->error;
                    }
                }
            }
        }
    }
    if (!$failed && $trackTarget > 0) {
        if ($curTrack !== $trackTarget) {
            $stats['suivi_poses']++;
            $touched = true;
            if ($confirm) {
                $product->array_options['options_aerotb_tracking'] = $trackTarget;
                if ($product->updateExtraField('aerotb_tracking') < 0) {
                    $failed = true;
                    $stats['suivi_poses']--;
                    if (count($samples['erreur']) < SAMPLES) {
                        $samples['erreur'][] = $refAdd.' (suivi) : '.$product->error;
                    }
                }
            }
        }
    }

    if ($failed) {
        $stats['erreur']++;
        continue;
    }
    if (!$touched) {
        $stats['deja']++;
        continue;
    }

    $writes++;
    if ($limit > 0 && $writes >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }
}
fclose($fh);
show_progress($stats, $total);
echo "
";

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
printf("Disponibilités %s : %s\n", $confirm ? 'posées ' : 'à poser', number_format($stats['dispo_posees'], 0, ',', ' '));
printf("Suivis %s         : %s  (suivi vide ou « -- », laissé : %s)\n", $confirm ? 'posés ' : 'à poser',
    number_format($stats['suivi_poses'], 0, ',', ' '), number_format($stats['suivi_vide'], 0, ',', ' '));
printf("Produits déjà conformes    : %s\n", number_format($stats['deja'], 0, ',', ' '));

echo "\nCas arbitrés par le client :\n";
printf("  %7s  emplacement(s) %s (aucun document ni stock ne les référence)\n",
    number_format($stats['supprimes'], 0, ',', ' '), $confirm ? 'supprimés' : 'à supprimer');
if ($stats['deja_supprimes'] > 0) {
    printf("  %7s  emplacement(s) déjà supprimé(s) par un passage précédent\n", number_format($stats['deja_supprimes'], 0, ',', ' '));
}
printf("  %7s  prestation(s) %s en service (+ %s déjà service(s))\n",
    number_format($stats['services'], 0, ',', ' '), $confirm ? 'passées' : 'à passer',
    number_format($stats['deja_services'], 0, ',', ' '));
if ($stats['stock_solde'] > 0) {
    printf("  %7s  solde(s) de stock avant bascule : %s\n", number_format($stats['stock_solde'], 0, ',', ' '),
        implode(', ', $samples['stock_solde']));
}
if ($stats['depotvente'] > 0) {
    printf("  %7s  dépôt(s)-vente signalé(s), rien d'écrit (fonctionnalité à venir) : %s\n",
        number_format($stats['depotvente'], 0, ',', ' '), implode(', ', $samples['depotvente']));
}
printf("  %7s  ligne(s) « CORRIGÉ SUR ADD », ignorées : la reprise fait foi\n", number_format($stats['corrige_add'], 0, ',', ' '));

$ecarts = array(
    'produit_absent' => 'référence sans produit repris',
    'ref_ambigue'    => 'référence canonique portée par plusieurs produits',
    'dispo_inconnue' => 'disponibilité absente du dictionnaire',
    'suivi_inconnu'  => 'suivi absent du dictionnaire',
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
