<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/merge_suppliers.php
 * \ingroup aeromigration
 * \brief   Fusionne les fournisseurs en double : l'ancien tiers boutique dans son tiers repris.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI DES DOUBLONS
 * ------------------------------------------------------------------------------
 *
 * La boutique tournait déjà quand la reprise des tiers est passée, et la table de
 * correspondance de la passerelle (`llx_prestasync_supplier`) n'avait pas été alimentée :
 * `migrate.php thirdparty` n'avait donc aucun moyen de reconnaître les fournisseurs déjà
 * présents, et les a recréés depuis ADD. Résultat : chaque fournisseur existe deux fois —
 * l'ancien (né dans la boutique, sans `ref_ext`, porteur des 317 lignes de correspondance
 * PrestaShop et des tarifs d'achat historiques) et le repris (`ref_ext` = `SAGE:Fxxx`,
 * porteur des commandes fournisseur reprises et rejouable par les scripts).
 *
 * ## Le tiers SAGE survit
 *
 * C'est lui qui porte l'identité de la reprise : `ref_ext` est la clé d'idempotence de tous
 * les scripts — garder l'ancien et supprimer le SAGE ferait renaître le doublon au prochain
 * passage de `thirdparty`. L'ancien lui apporte ses liens (tarifs, commandes, contacts,
 * catégories, fichiers) via `Societe::mergeCompany()`, qui complète aussi les champs vides
 * du survivant et concatène les notes, puis supprime l'ancien.
 *
 * ## Ce que le coeur ne fait pas, et que ce script ajoute
 *
 * 1. `llx_prestasync_supplier` : aucun module custom n'implémente le hook
 *    `replaceThirdparty` — les lignes de correspondance PrestaShop pointeraient des tiers
 *    supprimés. Elles sont repointées ici, AVANT chaque fusion : c'est précisément la table
 *    dont l'oubli a causé les doublons, la fusion la reconnecte aux bons tiers.
 *
 * 2. Les tarifs d'achat REMPLACÉS par la reprise : après fusion, l'ancienne ligne de tarif
 *    et celle que `supplierprice` crée (ou créera) coexisteraient sur le même fournisseur,
 *    et le bloc « Données fournisseur » montrerait deux générations de prix. L'ancienne
 *    ligne est supprimée UNIQUEMENT si le couple (article repris, fournisseur SAGE cible)
 *    figure dans `f_artfourniss` — l'ADD fait foi, comme pour les factures. Une ligne sans
 *    équivalent ADD (article hors reprise, ou couple inconnu d'ADD) est conservée : elle
 *    reste la seule information d'achat disponible. Suppression en SQL direct, avec le
 *    journal (`_log`) et les extrafields de la ligne — remise en cohérence d'environnement,
 *    même statut que les purges documentées.
 *
 * ## L'appariement : trois passes, le reste à la main
 *
 * ADD tronque les intitulés à 35 caractères (« Editions de L'Archipel / Presse du ») : le
 * nom seul ne suffit pas, et aucun ancien fournisseur ne porte d'email. Trois passes
 * automatiques, chacune n'acceptant qu'un candidat UNIQUE :
 *
 *   1. nom identique (majuscules, espaces réduits) ;
 *   2. nom compacté identique (alphanumérique seul, sans accents) — « E.T.A.I. » = « ETAI » ;
 *   3. troncature ADD : le nom SAGE compacté est un préfixe du nom ancien compacté, et
 *      compte au moins 20 caractères — assez long pour exclure toute coïncidence.
 *
 * Les ambigus (plusieurs SAGE de même nom — pour l'essentiel les fournisseurs « poubelle »
 * d'ADD en série F990000xxx : NAZE, annulé, LIBRE) et les sans-correspondance sortent au
 * rapport, et se tranchent avec `--map=fichier.csv` : une ligne `rowid_ancien;Fxxx` par cas
 * (le `SAGE:` est facultatif, `#` commente).
 *
 * ## Les doublons internes à ADD : `Fxxx;Fyyy`
 *
 * ADD a lui-même des fournisseurs en plusieurs exemplaires (SODIS en F208 et F990000004,
 * Alpha Industries en F990000029/30/31) : le fichier `--map` accepte aussi une réf SAGE en
 * premier champ pour fusionner un tiers repris dans un autre. Mêmes opérations, une garde
 * en plus : si le tiers absorbé porte des tarifs dans `f_artfourniss` mais aucune ligne de
 * prix dans Dolibarr, `supplierprice` n'est pas encore passé — la fusion est différée
 * (sinon ses tarifs seraient définitivement écartés, le tiers ayant disparu). D'où l'ordre :
 * ces fusions-là se font APRÈS `supplierprice`. Et comme la réf absorbée reste dans
 * `f_comptet`, un rejeu de `thirdparty` recrée le tiers : au jour J, repasser ce script
 * avec son `--map` après le rejeu.
 *
 * ## Rejouable
 *
 * Un ancien déjà absorbé n'existe plus : il ne réapparaît ni dans l'appariement ni au
 * rapport. Un second passage ne trouve que ce qui reste à faire.
 *
 * Usage :
 *   php merge_suppliers.php                     simulation : appariement et volumes, rien d'écrit
 *   php merge_suppliers.php --confirm           applique
 *   php merge_suppliers.php --map=fusions.csv   correspondances manuelles (rowid_ancien;Fxxx
 *                                               ou Fxxx;Fyyy pour les doublons internes à ADD)
 *   php merge_suppliers.php --user=LOGIN
 *   php merge_suppliers.php --source-db=NOM     base des tables f_* (« aeroprod » par défaut,
 *                                               « --source-db= » : la base de Dolibarr elle-même)
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

/** Préfixe des ref_ext posés par la reprise. */
const REF_EXT_PREFIX = 'SAGE:';

/** Longueur minimale (compactée) pour accepter un appariement par troncature. */
const TRUNC_MIN_LEN = 20;


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';
$mapFile   = '';
$sourceDb  = 'aeroprod';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--map=(.+)$/', $arg, $m)) {
        $mapFile = $m[1];
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
        if ($sourceDb !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $sourceDb)) {
            echo "Nom de base non exploitable : ".$sourceDb."\n";
            exit(1);
        }
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--map=FICHIER] [--user=LOGIN] [--source-db=NOM]\n";
        exit(1);
    }
}

/**
 * Préfixe une table source du nom de sa base, s'il y en a une.
 *
 * @param string $table Nom de table (f_artfourniss…)
 * @return string       Nom qualifié
 */
function src($table)
{
    global $sourceDb;
    return ($sourceDb !== '' ? $sourceDb.'.' : '').$table;
}

/**
 * Forme normalisée d'un nom : majuscules sans accents, espaces réduits.
 *
 * @param string $name Nom brut
 * @return string      Nom normalisé
 */
function norm_name($name)
{
    $s = dol_string_unaccent((string) $name);
    $s = mb_strtoupper($s, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Forme compactée d'un nom : la normalisée réduite à l'alphanumérique.
 *
 * « E.T.A.I. » et « ETAI » se rejoignent ici, « IDEES + / LE FUN EN BULLE » et
 * « IDEES   / LE FUN EN BULLE » aussi.
 *
 * @param string $name Nom brut
 * @return string      Nom compacté
 */
function compact_name($name)
{
    return preg_replace('/[^A-Z0-9]/', '', norm_name($name));
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
 * Chargement : les deux populations de fournisseurs.
 */

echo "Script : fusion des fournisseurs en double (ancien tiers boutique -> tiers repris)\n";
echo "Mode   : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
echo "Source : ".($sourceDb !== '' ? $sourceDb : "base Dolibarr")."\n";
echo str_repeat('-', 60)."\n\n";

$sageByRef  = array();  // 'F208'   => obj (rowid, nom)
$sageByNorm = array();  // nom normalisé => array de refs
$sageByComp = array();  // nom compacté  => array de refs

$sql   = 'SELECT rowid, nom, ref_ext FROM '.MAIN_DB_PREFIX.'societe'
    .' WHERE entity IN ('.getEntity('societe').') AND fournisseur = 1'
    ." AND ref_ext LIKE '".$db->escape(REF_EXT_PREFIX)."%'";
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des fournisseurs repris impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $ref = substr($obj->ref_ext, strlen(REF_EXT_PREFIX));
    $sageByRef[$ref] = $obj;
    $sageByNorm[norm_name($obj->nom)][] = $ref;
    $sageByComp[compact_name($obj->nom)][] = $ref;
}
$db->free($resql);

$oldRows = array();
$sql   = 'SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe'
    .' WHERE entity IN ('.getEntity('societe').') AND fournisseur = 1'
    ." AND (ref_ext IS NULL OR ref_ext NOT LIKE '".$db->escape(REF_EXT_PREFIX)."%')"
    .' ORDER BY nom, rowid';
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des anciens fournisseurs impossible : ".$db->lasterror()."\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $oldRows[] = $obj;
}
$db->free($resql);

echo "Fournisseurs repris (SAGE) : ".count($sageByRef)."\n";
echo "Anciens fournisseurs       : ".count($oldRows)."\n\n";

if (empty($oldRows) && $mapFile === '') {
    echo "Aucun ancien fournisseur : rien à fusionner. La fusion est faite (ou il n'y a jamais eu de doublon).\n";
    exit(0);
}


/*
 * Correspondances manuelles (--map).
 */

$manual     = array();  // rowid ancien => ref SAGE cible
$sageMerges = array();  // ref SAGE absorbée => ref SAGE cible
if ($mapFile !== '') {
    if (!is_readable($mapFile)) {
        echo "Fichier de correspondances illisible : ".$mapFile."\n";
        exit(1);
    }
    foreach (file($mapFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $n => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = array_map('trim', explode(';', $line));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            echo "Ligne ".($n + 1)." du fichier --map non exploitable : « ".$line." » (attendu : rowid_ancien;Fxxx ou Fxxx;Fyyy)\n";
            exit(1);
        }
        $ref = preg_replace('/^'.preg_quote(REF_EXT_PREFIX, '/').'/', '', $parts[1]);
        if (!isset($sageByRef[$ref])) {
            echo "Ligne ".($n + 1)." du fichier --map : aucun fournisseur repris « ".REF_EXT_PREFIX.$ref." »\n";
            exit(1);
        }
        if (ctype_digit($parts[0])) {
            $manual[(int) $parts[0]] = $ref;
            continue;
        }
        // Doublon interne à ADD : une réf SAGE absorbée dans une autre.
        $from = preg_replace('/^'.preg_quote(REF_EXT_PREFIX, '/').'/', '', $parts[0]);
        if ($from === $ref) {
            echo "Ligne ".($n + 1)." du fichier --map : ".$from." fusionné dans lui-même.\n";
            exit(1);
        }
        if (!isset($sageByRef[$from])) {
            // Déjà absorbé par un passage précédent : c'est l'idempotence, pas une erreur.
            echo "Note : « ".REF_EXT_PREFIX.$from." » n'existe plus (déjà fusionné ?) — ligne ".($n + 1)." ignorée.\n";
            continue;
        }
        $sageMerges[$from] = $ref;
    }
    // Une réf ne peut pas être à la fois absorbée et cible : l'ordre des fusions
    // deviendrait significatif, et une erreur de saisie passerait inaperçue.
    foreach ($sageMerges as $from => $to) {
        if (isset($sageMerges[$to]) || in_array($from, $manual, true) || in_array($from, $sageMerges, true)) {
            echo "Fichier --map : « ".$from." » ou « ".$to." » est à la fois absorbé et cible d'une fusion.\n";
            exit(1);
        }
    }
    echo "Correspondances manuelles chargées : ".count($manual)." ancien(s), ".count($sageMerges)." doublon(s) ADD\n\n";
}


/*
 * Appariement.
 */

$matches   = array();  // array de (old => obj, ref => 'F208', tier => libellé)
$ambiguous = array();  // array de (old => obj, refs => array)
$unmatched = array();  // array de obj
$tiers     = array('manuel' => 0, 'nom identique' => 0, 'ponctuation/accents' => 0, 'nom ADD tronqué' => 0);

foreach ($oldRows as $old) {
    $oldId = (int) $old->rowid;

    if (isset($manual[$oldId])) {
        $matches[] = array('old' => $old, 'ref' => $manual[$oldId], 'tier' => 'manuel');
        $tiers['manuel']++;
        continue;
    }

    $norm = norm_name($old->nom);
    $comp = compact_name($old->nom);

    // Passe 1 : nom identique.
    $cands = isset($sageByNorm[$norm]) ? array_values(array_unique($sageByNorm[$norm])) : array();
    $tier  = 'nom identique';

    // Passe 2 : nom compacté identique.
    if (empty($cands)) {
        $cands = isset($sageByComp[$comp]) ? array_values(array_unique($sageByComp[$comp])) : array();
        $tier  = 'ponctuation/accents';
    }

    // Passe 3 : troncature ADD — le nom SAGE compacté, assez long, est un préfixe du nôtre.
    if (empty($cands) && strlen($comp) >= TRUNC_MIN_LEN) {
        foreach ($sageByComp as $sageComp => $refs) {
            if (strlen($sageComp) >= TRUNC_MIN_LEN && strlen($sageComp) < strlen($comp)
                && strncmp($comp, $sageComp, strlen($sageComp)) === 0) {
                foreach ($refs as $r) {
                    $cands[] = $r;
                }
            }
        }
        $cands = array_values(array_unique($cands));
        $tier  = 'nom ADD tronqué';
    }

    if (count($cands) === 1) {
        $matches[] = array('old' => $old, 'ref' => $cands[0], 'tier' => $tier);
        $tiers[$tier]++;
    } elseif (count($cands) > 1) {
        sort($cands);
        $ambiguous[] = array('old' => $old, 'refs' => $cands);
    } else {
        $unmatched[] = $old;
    }
}

echo "Appariement\n";
foreach ($tiers as $tier => $nb) {
    if ($nb > 0) {
        printf("   %-22s : %d\n", $tier, $nb);
    }
}
printf("   %-22s : %d\n", 'ambigus (laissés)', count($ambiguous));
printf("   %-22s : %d\n\n", 'sans correspondance', count($unmatched));

// Les appariements non littéraux sont les seuls où le rapprochement s'interprète :
// ils s'affichent en clair, pour contrôle avant le --confirm.
$loose = array();
foreach ($matches as $m) {
    if ($m['tier'] !== 'nom identique') {
        $loose[] = $m;
    }
}
if (!empty($loose)) {
    echo "Appariements interprétés — à contrôler avant d'appliquer :\n";
    foreach ($loose as $m) {
        printf("   %-7d %-45s -> %-12s %-35s (%s)\n", $m['old']->rowid, dol_trunc($m['old']->nom, 43),
            $m['ref'], dol_trunc($sageByRef[$m['ref']]->nom, 33), $m['tier']);
    }
    echo "\n";
}

if (!empty($ambiguous)) {
    echo "Ambigus — plusieurs fournisseurs repris portent ce nom, à trancher via --map :\n";
    foreach ($ambiguous as $a) {
        printf("   %-7d %-45s -> %s\n", $a['old']->rowid, dol_trunc($a['old']->nom, 43), implode(', ', $a['refs']));
    }
    echo "\n";
}
if (!empty($unmatched)) {
    echo "Sans correspondance — inconnus d'ADD, ou nom trop différent (via --map le cas échéant) :\n";
    foreach ($unmatched as $o) {
        printf("   %-7d %s\n", $o->rowid, $o->nom);
    }
    echo "\n";
}

/*
 * Ce que chaque fusion emporte : correspondances PrestaShop et tarifs d'achat.
 *
 * Les couples (article, fournisseur) connus d'ADD sont chargés une fois : une ancienne
 * ligne de tarif dont le couple y figure est REMPLACÉE par la reprise (supprimée), les
 * autres suivent la fusion.
 */

$addCouples = array();  // 'CT_Num|AR_Ref' => true
$addByCt    = array();  // 'CT_Num' => nombre de tarifs ADD du fournisseur
$sql   = 'SELECT TRIM(CT_Num) as ct, TRIM(AR_Ref) as ar FROM '.src('f_artfourniss')
    ." WHERE TRIM(COALESCE(CT_Num, '')) <> '' AND TRIM(COALESCE(AR_Ref, '')) <> ''";
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture de ".src('f_artfourniss')." impossible : ".$db->lasterror()."\n";
    echo "Vérifiez --source-db.\n";
    exit(1);
}
while ($obj = $db->fetch_object($resql)) {
    $addCouples[$obj->ct.'|'.$obj->ar] = true;
    $addByCt[$obj->ct] = (isset($addByCt[$obj->ct]) ? $addByCt[$obj->ct] : 0) + 1;
}
$db->free($resql);

// Doublons internes à ADD (--map « Fxxx;Fyyy ») : mêmes fusions, une garde en plus.
// Un tiers absorbé qui a des tarifs dans f_artfourniss mais aucune ligne de prix dans
// Dolibarr attend encore « supplierprice » : le fusionner maintenant écarterait ses tarifs
// pour toujours (le script ne retrouverait plus son ref_ext). La fusion est différée.
$deferredSage = array();
foreach ($sageMerges as $from => $to) {
    $origin  = $sageByRef[$from];
    $couples = isset($addByCt[$from]) ? $addByCt[$from] : 0;
    if ($couples > 0) {
        $sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product_fournisseur_price'
            .' WHERE fk_soc = '.((int) $origin->rowid);
        $resql = $db->query($sql);
        $obj   = $resql ? $db->fetch_object($resql) : null;
        if ($resql) {
            $db->free($resql);
        }
        if (!$obj || (int) $obj->nb === 0) {
            $deferredSage[] = array('from' => $from, 'to' => $to, 'couples' => $couples);
            continue;
        }
    }
    $matches[] = array('old' => $origin, 'ref' => $to, 'tier' => 'doublon ADD');
}

$sageDone = array();
foreach ($matches as $m) {
    if ($m['tier'] === 'doublon ADD') {
        $sageDone[] = $m;
    }
}
if (!empty($sageDone)) {
    echo "Doublons internes à ADD à fusionner :\n";
    foreach ($sageDone as $m) {
        printf("   %-14s %-38s -> %-12s %s\n", substr($m['old']->ref_ext, strlen(REF_EXT_PREFIX)),
            dol_trunc($m['old']->nom, 36), $m['ref'], dol_trunc($sageByRef[$m['ref']]->nom, 33));
    }
    echo "\n";
}
if (!empty($deferredSage)) {
    echo "Doublons ADD DIFFÉRÉS — leurs tarifs (f_artfourniss) ne sont pas encore repris,\n";
    echo "passer « migrate.php supplierprice » puis relancer ce script avec le même --map :\n";
    foreach ($deferredSage as $d) {
        printf("   %-14s -> %-12s (%d tarif(s) ADD en attente)\n", $d['from'], $d['to'], $d['couples']);
    }
    echo "\n";
}

if (empty($matches)) {
    echo "Aucune fusion à faire.\n";
    exit(0);
}

$totalPresta = 0;
$totalPurge  = 0;
$totalMoved  = 0;

foreach ($matches as $k => $m) {
    $oldId = (int) $m['old']->rowid;

    // Correspondances PrestaShop à repointer.
    $sql   = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'prestasync_supplier WHERE fk_soc = '.$oldId;
    $resql = $db->query($sql);
    $obj   = $resql ? $db->fetch_object($resql) : null;
    $matches[$k]['presta'] = $obj ? (int) $obj->nb : 0;
    if ($resql) {
        $db->free($resql);
    }
    $totalPresta += $matches[$k]['presta'];

    // Tarifs d'achat : remplacés par la reprise (à purger) ou uniques (suivent la fusion).
    $purge = array();
    $moved = 0;
    $sql   = 'SELECT pfp.rowid, p.ref_ext FROM '.MAIN_DB_PREFIX.'product_fournisseur_price as pfp'
        .' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pfp.fk_product'
        .' WHERE pfp.fk_soc = '.$oldId;
    $resql = $db->query($sql);
    if (!$resql) {
        echo "Lecture des tarifs du tiers ".$oldId." impossible : ".$db->lasterror()."\n";
        exit(1);
    }
    while ($obj = $db->fetch_object($resql)) {
        $isSage = (strpos((string) $obj->ref_ext, REF_EXT_PREFIX) === 0);
        $arRef  = $isSage ? substr($obj->ref_ext, strlen(REF_EXT_PREFIX)) : '';
        if ($isSage && isset($addCouples[$m['ref'].'|'.$arRef])) {
            $purge[] = (int) $obj->rowid;
        } else {
            $moved++;
        }
    }
    $db->free($resql);
    $matches[$k]['purge'] = $purge;
    $matches[$k]['moved'] = $moved;
    $totalPurge += count($purge);
    $totalMoved += $moved;
}

echo "Fusions à faire : ".count($matches)."\n";
echo "   correspondances PrestaShop repointées : ".$totalPresta."\n";
echo "   tarifs d'achat remplacés par la reprise, purgés : ".number_format($totalPurge, 0, ',', ' ')."\n";
echo "   tarifs d'achat sans équivalent ADD, déplacés    : ".number_format($totalMoved, 0, ',', ' ')."\n\n";


/*
 * Application
 */

if (!$confirm) {
    echo "SIMULATION terminée, rien n'a été écrit. Relancer avec --confirm pour appliquer.\n";
    exit(0);
}

// Les fusions déclenchent COMPANY_MODIFY sur chaque survivant : le trigger d'aerotoolbox y
// diffère une propagation des profils vers la boutique. Sans objet pour des fournisseurs, et
// plusieurs centaines de tiers d'un coup : on coupe pour la durée du script.
dol_include_once('/aeropresta/lib/aeropresta_interest.lib.php');
if (function_exists('aeropresta_int_silent')) {
    aeropresta_int_silent(true);
}

$db->begin();

$done    = 0;
$errors  = array();
$total   = count($matches);
$started = microtime(true);

foreach ($matches as $m) {
    $oldId = (int) $m['old']->rowid;
    $sage  = $sageByRef[$m['ref']];

    // Chaque fusion coûte une à deux secondes (la suppression du tiers balaie des tables
    // non indexées) : sans trace d'avancement, le script semble bloqué.
    printf("   %3d/%d  %-45s -> %-12s  (%d s)\n", $done + 1, $total,
        dol_trunc($m['old']->nom, 43), $m['ref'], (int) (microtime(true) - $started));
    flush();

    // 1. La passerelle d'abord : le coeur ne connaît pas cette table, et l'ancien tiers
    //    disparaît à la fusion.
    if ($m['presta'] > 0) {
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'prestasync_supplier SET fk_soc = '.((int) $sage->rowid)
            .' WHERE fk_soc = '.$oldId;
        if (!$db->query($sql)) {
            $errors[] = 'tiers '.$oldId.' ('.$m['old']->nom.') : repointage prestasync_supplier — '.$db->lasterror();
            break;
        }
    }

    // 2. Les tarifs remplacés par la reprise, avec leur journal et leurs extrafields :
    //    supprimés avant la fusion, pour ne pas les déplacer d'abord et les chercher ensuite.
    if (!empty($m['purge'])) {
        $in = implode(', ', $m['purge']);
        foreach (array(
            'DELETE FROM '.MAIN_DB_PREFIX.'product_fournisseur_price_log WHERE fk_product_fournisseur IN ('.$in.')',
            'DELETE FROM '.MAIN_DB_PREFIX.'product_fournisseur_price_extrafields WHERE fk_object IN ('.$in.')',
            'DELETE FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE rowid IN ('.$in.')',
        ) as $sql) {
            if (!$db->query($sql)) {
                $errors[] = 'tiers '.$oldId.' ('.$m['old']->nom.') : purge des tarifs remplacés — '.$db->lasterror();
                break 2;
            }
        }
    }

    // 3. La fusion elle-même. mergeCompany() rend 0 en succès, et gère sa propre
    //    sous-transaction — imbriquée dans la nôtre, le SGBD ne validant qu'au commit final.
    $target = new Societe($db);
    if ($target->fetch((int) $sage->rowid) <= 0) {
        $errors[] = 'tiers repris '.$sage->rowid.' ('.REF_EXT_PREFIX.$m['ref'].') : relecture impossible';
        break;
    }
    if ($target->mergeCompany($oldId) < 0) {
        $errors[] = 'tiers '.$oldId.' ('.$m['old']->nom.') -> '.REF_EXT_PREFIX.$m['ref'].' : '.$target->error;
        break;
    }

    $done++;
}

if (!empty($errors)) {
    $db->rollback();
    echo "ÉCHEC après ".$done." fusion(s) — tout est annulé (transaction) :\n";
    foreach ($errors as $e) {
        echo "  - ".$e."\n";
    }
    exit(1);
}

$db->commit();

echo "Fusion appliquée.\n";
echo "  fournisseurs fusionnés : ".$done."\n";
echo "  correspondances PrestaShop repointées : ".$totalPresta."\n";
echo "  tarifs remplacés purgés : ".number_format($totalPurge, 0, ',', ' ')."\n";
echo "  tarifs déplacés         : ".number_format($totalMoved, 0, ',', ' ')."\n";
if (!empty($ambiguous) || !empty($unmatched)) {
    echo "\nRestent ".(count($ambiguous) + count($unmatched))." ancien(s) non fusionné(s) — voir le rapport ci-dessus, --map pour les trancher.\n";
}
