<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/renumber_thirdparty_codes.php
 * \ingroup aeromigration
 * \brief   Renumérote les codes clients et fournisseurs au format des pièces : CL2627-000001.
 *
 * ------------------------------------------------------------------------------
 * CE SCRIPT RÉÉCRIT LE CODE DE ~158 500 TIERS — LISEZ TOUT AVANT
 * ------------------------------------------------------------------------------
 *
 * ## Pourquoi
 *
 * Les codes venaient du modèle natif Monkey, CU puis l'année et le mois de la création. Toute la
 * reprise porte « 2609 », le mois de la bascule : un client de 2015 et un client d'hier avaient
 * l'air contemporains, et le compteur sur cinq chiffres a débordé en route (CU2609-100000).
 * aerotoolbox 1.71.0 apporte le modèle « Aero » (mod_codeclient_aero) ; ce script met le parc
 * existant à sa règle, puis l'active.
 *
 * ## La règle (demande du client du 14/10/2026, corrigée le jour même après rendez-vous)
 *
 * `CL<millésime>-<compteur>` pour un client ou un prospect, `FOU<millésime>-<compteur>` pour un
 * fournisseur. Le millésime est celui de l'exercice fiscal (octobre → septembre) de la création du
 * tiers, **sauf avant l'exercice 2019-2020 : un seul bloc « 0219 »** (de 2002 à septembre 2019).
 * Le compteur, sur six chiffres, suit l'**ordre chronologique** et **ne repart pas à zéro** d'un
 * exercice à l'autre — la règle même du modèle, qui reprendra au dernier numéro attribué.
 *
 * ## La date de création, faute de mieux
 *
 * ADD n'a daté ses comptes qu'à partir de sa version SQL (28/10/2019) : les 110 000 comptes
 * antérieurs portent le 01/01/2000, et ses pièces ne remontent qu'au 01/10/2015. Aucune vraie date
 * n'existe donc avant fin 2019. Mais le **numéro de compte ADD** (CT_Num, gardé dans ref_ext
 * « SAGE:<n> ») a été attribué dans l'ordre de création : il sert d'horloge.
 *
 *   - Fiche datée (tout sauf le 01/01/2000) : la plus ancienne de sa date de création, de sa
 *     première facture et de sa première commande (pièces d'achat pour un fournisseur).
 *   - Fiche ADD au 01/01/2000 : date **estimée** sur sa série de numéros. Par paquets de 50 numéros
 *     consécutifs, le 1er décile des dates connues (premières pièces, dates des fiches datées) donne
 *     un repère, rendu croissant avec le numéro ; la date d'un compte s'interpole entre deux repères.
 *     Sa propre première pièce prime si elle précède l'estimation de moins d'un mois — plus tôt,
 *     c'est une pièce rattachée après coup (fusion de comptes) et elle est ignorée.
 *     Effet : les comptes jusque vers le n° 106 460 tombent avant octobre 2019 (bloc 0219), les
 *     ~6 000 suivants, créés d'octobre 2019 à décembre 2020 mais eux aussi au 01/01/2000, dans
 *     leurs exercices 1920 et 2021.
 *   - Fiche au 01/01/2000 sans aucun repère : bloc 0219 (le 01/01/2000 dit « avant ADD SQL »).
 *   - Fiche sans aucune date du tout : la date de la reprise, REPRISE_DATE.
 *
 * Ordre dans le bloc 0219 : le numéro ADD (la seule chronologie disponible). Dans les exercices
 * suivants : la date, puis le numéro ADD, puis la fiche (rowid).
 *
 * Périmètre : tout tiers client ou prospect, ou portant déjà un code client ; de même pour les
 * fournisseurs. Un client sans code en reçoit un.
 *
 * ## L'ancien code est conservé
 *
 * Dans l'extrafield `aerotb_old_code` (aerotoolbox 1.71.0), affiché en lecture seule sur la fiche
 * et lu par la recherche : un ancien code noté quelque part retrouve toujours sa fiche. Il n'est
 * posé qu'une fois — un second passage ne l'écrase pas.
 *
 * ## Écriture directe assumée
 *
 * UPDATE direct, sans passer par Societe::update() : 158 000 enregistrements « normaux »
 * déclencheraient autant de triggers (synchronisation boutique, journal des modifications). La date
 * de modification de la fiche (`tms`) est laissée intacte, pour que rien ne prenne ces fiches pour
 * des fiches modifiées. L'index unique `uk_societe_code_client` protège l'opération.
 *
 * ## Idempotence et sécurité
 *
 * Les cibles se calculent depuis des données stables (dates, rowid) : deux passages donnent les
 * mêmes codes, et un tiers déjà à sa cible est ignoré. Le script est donc rejouable — par exemple
 * pour rattraper un client créé pendant le passage. Avant toute écriture, les cibles sont vérifiées
 * uniques ; un tiers qui détient déjà la cible d'un autre est d'abord déplacé vers un code
 * temporaire « (RN<rowid>) », remplacé dans la passe principale.
 *
 * ## Activation du modèle
 *
 * À la fin d'un passage complet sans échec, SOCIETE_CODECLIENT_ADDON passe sur
 * mod_codeclient_aero : les créations suivantes continuent la série. Pas en lot d'essai (--limit).
 *
 * Prérequis : aerotoolbox 1.71.0 RÉACTIVÉ (modèle de codification et champ « Ancien code »).
 * À lancer magasin fermé, après une sauvegarde de la base.
 *
 * Usage :
 *   php renumber_thirdparty_codes.php                 simulation : ventilation, échantillons, contrôles
 *   php renumber_thirdparty_codes.php --confirm       applique, puis active le modèle
 *   php renumber_thirdparty_codes.php --limit=100 --confirm    lot d'essai (n'active pas le modèle)
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// 158 000 tiers tenus en mémoire avec leurs dates : la limite par défaut ne suffit pas.
@ini_set('memory_limit', '1024M');

/** Date retenue pour un tiers sans aucune date connue : la bascule de l'ancien ERP. */
const REPRISE_DATE = '2026-09-07 00:00:00';

/** Date de création fictive posée par la reprise d'ADD. */
const FAKE_DATEC = '2000-01-01';

/** Tout tiers créé avant cette date va dans le bloc unique LEGACY_SEGMENT (décision du client). */
const LEGACY_BEFORE  = '2019-10-01';
const LEGACY_SEGMENT = '0219';

/** Estimation sur la série ADD : taille d'un paquet, dates connues minimales pour en tirer un repère. */
const EST_BLOCK     = 50;
const EST_MIN_KNOWN = 5;

/** Écart au-delà duquel une première pièce antérieure à l'estimation est tenue pour rattachée après coup. */
const EST_PIECE_SLACK = 31 * 86400;

/** Modèle de codification activé à la fin. */
const CODE_MODEL = 'mod_codeclient_aero';


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N]\n";
        exit(1);
    }
}


/*
 * Prérequis : aerotoolbox 1.71.0 réactivé.
 */

$problems = array();
if (!isModEnabled('aerotoolbox')) {
    $problems[] = 'le module aerotoolbox n\'est pas actif';
}
if (!dol_include_once('/aerotoolbox/lib/aeronum.lib.php') || !function_exists('aerotb_num_fiscal_year')) {
    $problems[] = 'aerotoolbox/lib/aeronum.lib.php introuvable';
}
if (!is_file(dol_buildpath('/aerotoolbox/core/modules/societe/'.CODE_MODEL.'.php', 0))) {
    $problems[] = 'modèle '.CODE_MODEL.' introuvable (aerotoolbox antérieur à 1.71.0)';
}
if (empty($conf->modules_parts['societe']['aerotoolbox'])) {
    $problems[] = 'emplacement des modèles de codification non déclaré : aerotoolbox 1.71.0 pas réactivé';
}
$resql = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."societe_extrafields LIKE 'aerotb_old_code'");
if (!$resql || $db->num_rows($resql) === 0) {
    $problems[] = 'champ « Ancien code » (aerotb_old_code) absent : aerotoolbox 1.71.0 pas réactivé';
}
if (getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1) <= 1) {
    $problems[] = 'exercice fiscal non décalé (SOCIETE_FISCAL_MONTH_START) : le millésime double n\'aurait pas de sens';
}
if ($problems) {
    echo "REFUS :\n";
    foreach ($problems as $p) {
        echo "  - ".$p."\n";
    }
    exit(1);
}

$prefixes = array(
    'client'      => getDolGlobalString('AEROTB_NUM_PREFIX_CUSTOMER', 'CL'),
    'fournisseur' => getDolGlobalString('AEROTB_NUM_PREFIX_SUPPLIER', 'FOU'),
);
$fields = array('client' => 'code_client', 'fournisseur' => 'code_fournisseur');
$len = aerotb_num_counter_length();


/*
 * Chargement : chaque tiers avec ses premières dates connues.
 */

$sql = 'SELECT s.rowid, s.client, s.fournisseur, s.code_client, s.code_fournisseur, s.datec, s.ref_ext,'
    .' e.rowid AS efid, e.aerotb_old_code,'
    .' fi.d AS first_inv, co.d AS first_ord, ff.d AS first_sinv, cf.d AS first_sord'
    .' FROM '.MAIN_DB_PREFIX.'societe AS s'
    .' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields AS e ON e.fk_object = s.rowid'
    .' LEFT JOIN (SELECT fk_soc, MIN(datef) AS d FROM '.MAIN_DB_PREFIX.'facture GROUP BY fk_soc) AS fi ON fi.fk_soc = s.rowid'
    .' LEFT JOIN (SELECT fk_soc, MIN(date_commande) AS d FROM '.MAIN_DB_PREFIX.'commande GROUP BY fk_soc) AS co ON co.fk_soc = s.rowid'
    .' LEFT JOIN (SELECT fk_soc, MIN(datef) AS d FROM '.MAIN_DB_PREFIX.'facture_fourn GROUP BY fk_soc) AS ff ON ff.fk_soc = s.rowid'
    .' LEFT JOIN (SELECT fk_soc, MIN(date_commande) AS d FROM '.MAIN_DB_PREFIX.'commande_fournisseur GROUP BY fk_soc) AS cf ON cf.fk_soc = s.rowid'
    .' WHERE s.entity IN ('.getEntity('societe').')';
$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}

/**
 * Plus ancienne des dates connues, ou '' s'il n'y en a aucune.
 *
 * @param  array<int,string|null> $dates Dates SQL, nulles ou fictives comprises
 * @return string
 */
function firstKnownDate($dates)
{
    $best = '';
    foreach ($dates as $d) {
        $d = (string) $d;
        if ($d === '' || strpos($d, '0000') === 0 || strpos($d, FAKE_DATEC) === 0) {
            continue;
        }
        if ($best === '' || strcmp($d, $best) < 0) {
            $best = $d;
        }
    }

    return $best;
}

$soc     = array();   // rowid -> le strict nécessaire : codes, extrafields, ancien code
// type -> rowid -> [date retenue ('' = à estimer ou inconnue), famille ADD, n° ADD, première pièce, source]
$entries = array('client' => array(), 'fournisseur' => array());
$sources = array('client' => array(), 'fournisseur' => array());   // type -> source -> n

while ($o = $db->fetch_object($resql)) {
    $rowid = (int) $o->rowid;
    // Pas l'objet entier : 158 000 objets de douze champs ne tiennent pas dans la mémoire courante.
    $soc[$rowid] = (object) array(
        'code_client'      => (string) $o->code_client,
        'code_fournisseur' => (string) $o->code_fournisseur,
        'efid'             => (int) $o->efid,
        'aerotb_old_code'  => (string) $o->aerotb_old_code,
    );

    $isClient = ((int) $o->client > 0) || trim((string) $o->code_client) !== '';
    $isFourn  = ((int) $o->fournisseur > 0) || trim((string) $o->code_fournisseur) !== '';

    // Numéro de compte ADD : « SAGE:106460 » (clients), « SAGE:F296 » (fournisseurs). La famille
    // (lettres de tête) sépare les séries, chacune numérotée dans son propre ordre.
    $family = null;
    $num    = 0;
    if (preg_match('/^SAGE:([A-Z]*)(\d+)$/', trim((string) $o->ref_ext), $m)) {
        $family = $m[1];
        $num    = (int) $m[2];
    }
    $fake = (strpos((string) $o->datec, FAKE_DATEC) === 0);

    foreach (array('client' => $isClient, 'fournisseur' => $isFourn) as $type => $in) {
        if (!$in) {
            continue;
        }
        $datec = $fake ? '' : (string) $o->datec;
        $docs  = ($type === 'client') ? array($o->first_inv, $o->first_ord) : array($o->first_sinv, $o->first_sord);
        $piece = firstKnownDate($docs);
        if ($datec !== '') {
            $ref = firstKnownDate(array($datec, $piece));
            $src = ($ref === $datec) ? 'création de la fiche' : 'première pièce';
        } elseif ($fake && $family !== null && $type === 'client') {
            $ref = '';   // estimée plus bas, sur la série ADD
            $src = '';
        } elseif ($fake) {
            // Le 01/01/2000 d'ADD dit « avant sa version SQL ». Pour un fournisseur, pas d'estimation
            // possible : ses achats ne remontent qu'à avril 2019, bien après la création de la
            // plupart, et la série datée ne commence qu'à F1000 (20/12/2019).
            $ref = '';
            $src = 'au 01/01/2000 : avant 2019';
        } elseif ($piece !== '') {
            $ref = $piece;
            $src = 'première pièce';
        } else {
            $ref = REPRISE_DATE;
            $src = 'aucune date : reprise';
        }
        $entries[$type][$rowid] = array($ref, $family, $num, $piece, $src);
    }
}
$db->free($resql);


/*
 * Estimation des dates de création sur la série des numéros ADD.
 */

/**
 * Estime la date de création des comptes ADD au 01/01/2000, famille par famille.
 *
 * Repères : par paquets de EST_BLOCK numéros consécutifs, le 1er décile des dates connues du
 * paquet (date des fiches datées, sinon première pièce) — un compte est créé au plus tard à sa
 * première pièce, et le plus souvent le jour même. Les repères sont rendus croissants en partant
 * du haut (un repère ne peut dépasser celui des numéros suivants), puis interpolés au numéro.
 *
 * @param  array<int,array> $list Entrées d'un type (rowid -> entrée), modifiées en place
 * @return void
 */
function estimateFromAddSeries(&$list)
{
    $families = array();   // famille -> [n° ADD, rowid]
    foreach ($list as $rowid => $e) {
        if ($e[1] !== null) {
            $families[$e[1]][] = array($e[2], $rowid);
        }
    }

    foreach ($families as $members) {
        usort($members, function ($a, $b) {
            return ($a[0] !== $b[0]) ? ($a[0] - $b[0]) : ($a[1] - $b[1]);
        });

        // Repères par paquet : [n° ADD médian, horodatage].
        $anchors = array();
        foreach (array_chunk($members, EST_BLOCK) as $block) {
            $known = array();
            foreach ($block as $mb) {
                $e = $list[$mb[1]];
                $d = ($e[0] !== '') ? $e[0] : $e[3];
                if ($d !== '') {
                    $known[] = $d;
                }
            }
            if (count($known) < EST_MIN_KNOWN) {
                continue;
            }
            sort($known);
            $anchors[] = array($block[(int) floor(count($block) / 2)][0], strtotime($known[(int) floor((count($known) - 1) / 10)]));
        }
        for ($i = count($anchors) - 2; $i >= 0; $i--) {
            $anchors[$i][1] = min($anchors[$i][1], $anchors[$i + 1][1]);
        }

        $a = 0;
        foreach ($members as $mb) {
            list($num, $rowid) = $mb;
            if ($list[$rowid][0] !== '' || $list[$rowid][4] !== '') {
                continue;   // fiche datée : sa date fait foi
            }
            if (!$anchors) {
                $list[$rowid][4] = 'au 01/01/2000 : avant 2019';
                continue;
            }
            while ($a < count($anchors) - 1 && $anchors[$a + 1][0] <= $num) {
                $a++;
            }
            if ($num <= $anchors[0][0]) {
                $ts = $anchors[0][1];
            } elseif ($a >= count($anchors) - 1) {
                $ts = $anchors[count($anchors) - 1][1];
            } else {
                list($x0, $y0) = $anchors[$a];
                list($x1, $y1) = $anchors[$a + 1];
                $ts = (int) round($y0 + ($y1 - $y0) * ($num - $x0) / max(1, $x1 - $x0));
            }
            $piece = $list[$rowid][3];
            if ($piece !== '') {
                $pts = strtotime($piece);
                if ($pts < $ts && $ts - $pts <= EST_PIECE_SLACK) {
                    $ts = $pts;
                }
            }
            $list[$rowid][0] = date('Y-m-d H:i:s', $ts);
            $list[$rowid][4] = ($list[$rowid][0] < LEGACY_BEFORE) ? 'estimée : avant 2019' : 'estimée : après 2019';
        }
    }
}

$boundary = array();   // type -> famille -> [dernier n° ADD avant 2019, premier après]
foreach ($entries as $type => &$list) {
    estimateFromAddSeries($list);
    foreach ($list as $e) {
        $sources[$type][$e[4]] = isset($sources[$type][$e[4]]) ? $sources[$type][$e[4]] + 1 : 1;
        if (strpos($e[4], 'estimée') === 0) {
            $k = ($e[0] < LEGACY_BEFORE) ? 0 : 1;
            $b = isset($boundary[$type][$e[1]]) ? $boundary[$type][$e[1]] : array(null, null);
            if ($k === 0 && ($b[0] === null || $e[2] > $b[0])) {
                $b[0] = $e[2];
            } elseif ($k === 1 && ($b[1] === null || $e[2] < $b[1])) {
                $b[1] = $e[2];
            }
            $boundary[$type][$e[1]] = $b;
        }
    }
}
unset($list);


/*
 * Calcul des cibles : ordre chronologique, compteur continu par type.
 */

$targets   = array('client' => array(), 'fournisseur' => array());   // type -> rowid -> code
$already   = array('client' => 0, 'fournisseur' => 0);
$perSeries = array();   // série -> [n, premier, dernier]
$lastCode  = array('client' => '', 'fournisseur' => '');

foreach ($entries as $type => $list) {
    // Clé de tri : le millésime d'abord ('0219' passe avant '1920'), puis dans le bloc 0219 le
    // numéro ADD, ailleurs la date ; la fiche départage.
    $keys = array();
    $segs = array();
    foreach ($list as $rowid => $e) {
        list($ref, $family, $num) = $e;
        $seg  = ($ref === '' || $ref < LEGACY_BEFORE) ? LEGACY_SEGMENT : aerotb_num_fiscal_year($db->jdate($ref));
        $add  = ($family !== null) ? '0'.str_pad($family, 4).str_pad((string) $num, 12, '0', STR_PAD_LEFT) : '1';
        $keys[$rowid] = ($seg === LEGACY_SEGMENT)
            ? $seg.'|'.$add.'|'.$ref
            : $seg.'|'.$ref.'|'.$add;
        $segs[$rowid] = $seg;
    }
    uksort($keys, function ($r1, $r2) use ($keys) {
        $c = strcmp($keys[$r1], $keys[$r2]);
        return ($c !== 0) ? $c : ($r1 - $r2);
    });
    $n = 0;
    foreach ($keys as $rowid => $unused) {
        $n++;
        $fy     = $segs[$rowid];
        $code   = $prefixes[$type].$fy.'-'.str_pad((string) $n, $len, '0', STR_PAD_LEFT);
        $serie  = $prefixes[$type].$fy;
        if (!isset($perSeries[$serie])) {
            $perSeries[$serie] = array('n' => 0, 'first' => $code, 'last' => $code);
        }
        $perSeries[$serie]['n']++;
        $perSeries[$serie]['last'] = $code;
        $lastCode[$type] = $code;

        if ((string) $soc[$rowid]->{$fields[$type]} === $code) {
            $already[$type]++;
            continue;
        }
        $targets[$type][$rowid] = $code;
    }
}


/*
 * Contrôles : unicité des cibles, et détenteurs actuels d'une cible à déplacer d'abord.
 */

$collisions = array();
$preRenames = array('client' => array(), 'fournisseur' => array());   // type -> rowid -> temporaire
foreach ($fields as $type => $field) {
    $final = array();   // code final -> rowid
    foreach ($soc as $rowid => $o) {
        $code = isset($targets[$type][$rowid]) ? $targets[$type][$rowid] : trim((string) $o->$field);
        if ($code === '') {
            continue;
        }
        if (isset($final[$code])) {
            $collisions[] = $field.' '.$code.' (tiers '.$final[$code].' et '.$rowid.')';
        }
        $final[$code] = $rowid;
    }
    $targetSet = array_flip($targets[$type]);
    foreach ($targets[$type] as $rowid => $code) {
        if (isset($targetSet[(string) $soc[$rowid]->$field])) {
            $preRenames[$type][$rowid] = '(RN'.$rowid.')';
        }
    }
}


/*
 * Rapport
 */

echo "Script      : renumérotation des codes tiers (modèle ".CODE_MODEL.")\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
foreach ($fields as $type => $field) {
    printf("%-12s: %s à renuméroter, %s déjà conforme(s)\n", ucfirst($type).'s',
        number_format(count($targets[$type]), 0, ',', ' '), number_format($already[$type], 0, ',', ' '));
    ksort($sources[$type]);
    foreach ($sources[$type] as $src => $n) {
        printf("              date retenue — %-27s : %s\n", $src, number_format($n, 0, ',', ' '));
    }
    if (!empty($boundary[$type])) {
        foreach ($boundary[$type] as $family => $b) {
            printf("              série ADD « %s » : dernier n° avant octobre 2019 %s, premier après %s\n",
                ($family === '') ? 'numérique' : $family.'…', ($b[0] === null) ? '-' : $family.$b[0], ($b[1] === null) ? '-' : $family.$b[1]);
        }
    }
}

echo "\nVentilation par exercice :\n";
ksort($perSeries);
foreach ($perSeries as $serie => $s) {
    printf("  %-7s : %7s  de %s à %s\n", $serie, number_format($s['n'], 0, ',', ' '), $s['first'], $s['last']);
}

if ($collisions) {
    echo "\nCOLLISIONS (".count($collisions).") — RIEN ne sera écrit :\n";
    foreach (array_slice($collisions, 0, 20) as $c) {
        echo "  ".$c."\n";
    }
    $db->close();
    exit(1);
}
printf("\nContrôles   : codes tous uniques — %d code(s) actuel(s) occupant une cible, déplacé(s)\n"
    ."              en temporaire avant la passe principale.\n",
    count($preRenames['client']) + count($preRenames['fournisseur']));

echo "\nÉchantillons :\n";
foreach ($fields as $type => $field) {
    $shown = 0;
    foreach ($targets[$type] as $rowid => $code) {
        $o = $soc[$rowid];
        printf("  %-14s -> %s  (fiche %s)\n", trim((string) $o->$field) !== '' ? $o->$field : '(sans code)', $code, $rowid);
        if (++$shown >= 4) {
            break;
        }
    }
}
echo "\nDerniers codes : ".$lastCode['client']." / ".$lastCode['fournisseur']
    ." — le modèle reprendra après eux.\n";

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
    $db->close();
    exit(0);
}


/*
 * Écriture, par lots transactionnels.
 *
 * `tms = tms` : la date de modification de la fiche reste celle d'avant, comme si rien n'avait
 * changé pour tout ce qui la surveille.
 */

$done   = 0;
$failed = 0;
$start  = microtime(true);

/**
 * Exécute une écriture, compte l'échec éventuel.
 *
 * @param  DoliDB $db     Base
 * @param  string $sql    Requête
 * @param  string $what   Libellé pour le message d'échec
 * @param  int    $failed Compteur d'échecs
 * @return bool
 */
function runWrite($db, $sql, $what, &$failed)
{
    if ($db->query($sql)) {
        return true;
    }
    $failed++;
    echo "  ÉCHEC ".$what." : ".$db->lasterror()."\n";

    return false;
}

// Le lot d'essai ne prend que les premiers tiers à renuméroter, dans l'ordre chronologique.
$todo = array();   // rowid -> [type -> code]
foreach ($targets as $type => $list) {
    foreach ($list as $rowid => $code) {
        $todo[$rowid][$type] = $code;
    }
}
if ($limit > 0 && count($todo) > $limit) {
    $todo = array_slice($todo, 0, $limit, true);
}

// Pré-phase : libérer les cibles occupées. En lot d'essai, seuls les détenteurs qui font partie du
// lot sont déplacés : un détenteur hors du lot garderait sinon un code temporaire jusqu'au passage
// suivant (et son éventuel conflit se lit alors en échec, sans rien casser).
$db->begin();
$batch = 0;
foreach ($preRenames as $type => $list) {
    foreach ($list as $rowid => $tmp) {
        if (!isset($todo[$rowid][$type])) {
            continue;
        }
        runWrite($db, 'UPDATE '.MAIN_DB_PREFIX.'societe SET '.$fields[$type]." = '".$db->escape($tmp)."', tms = tms"
            .' WHERE rowid = '.((int) $rowid), 'pré-phase tiers '.$rowid, $failed);
        if (++$batch >= 500) {
            $db->commit();
            $db->begin();
            $batch = 0;
        }
    }
}
$db->commit();

$db->begin();
$batch = 0;
foreach ($todo as $rowid => $codes) {
    $o = $soc[$rowid];

    // L'ancien code, une seule fois : les codes actuels qui vont disparaître.
    if (trim((string) $o->aerotb_old_code) === '') {
        $old = array();
        foreach ($codes as $type => $code) {
            $cur = trim((string) $o->{$fields[$type]});
            if ($cur !== '' && $cur !== $code) {
                $old[] = $cur;
            }
        }
        if ($old) {
            $val = $db->escape(dol_trunc(implode(' ', $old), 64, 'right', 'UTF-8', 1));
            $sql = !empty($o->efid)
                ? 'UPDATE '.MAIN_DB_PREFIX."societe_extrafields SET aerotb_old_code = '".$val."' WHERE rowid = ".((int) $o->efid)
                : 'INSERT INTO '.MAIN_DB_PREFIX."societe_extrafields (fk_object, aerotb_old_code) VALUES (".((int) $rowid).", '".$val."')";
            if (!runWrite($db, $sql, 'ancien code du tiers '.$rowid, $failed)) {
                continue;
            }
        }
    }

    $sets = array();
    foreach ($codes as $type => $code) {
        $sets[] = $fields[$type]." = '".$db->escape($code)."'";
    }
    if (runWrite($db, 'UPDATE '.MAIN_DB_PREFIX.'societe SET '.implode(', ', $sets).', tms = tms WHERE rowid = '.((int) $rowid),
        'tiers '.$rowid, $failed)) {
        $done++;
    }

    if (++$batch >= 500) {
        $db->commit();
        $db->begin();
        $batch = 0;
        if ($done % 20000 === 0) {
            printf("  %s/%s tiers renuméroté(s)  (%.0f/s)\n", number_format($done, 0, ',', ' '),
                number_format(count($todo), 0, ',', ' '), $done / max(1, microtime(true) - $start));
        }
    }
}
$db->commit();

printf("\nRenumérotés : %s  En échec : %d  Durée : %.0f s\n",
    number_format($done, 0, ',', ' '), $failed, microtime(true) - $start);

// Le modèle, seulement après un passage complet et sans échec : activé plus tôt, il aurait
// numéroté depuis 000001 des tiers que la renumérotation aurait ensuite heurtés.
if ($failed > 0) {
    echo "Des échecs : le modèle n'est PAS activé. Corrigez, puis relancez (le script est rejouable).\n";
} elseif ($limit > 0) {
    echo "Lot d'essai : le modèle n'est pas activé. Lancez le passage complet pour terminer.\n";
} elseif (getDolGlobalString('SOCIETE_CODECLIENT_ADDON') !== CODE_MODEL) {
    dolibarr_set_const($db, 'SOCIETE_CODECLIENT_ADDON', CODE_MODEL, 'chaine', 0, '', $conf->entity);
    echo "Modèle de codification activé : ".CODE_MODEL." — les créations reprennent après "
        .$lastCode['client']." / ".$lastCode['fournisseur'].".\n";
} else {
    echo "Modèle de codification déjà actif : ".CODE_MODEL.".\n";
}

$db->close();
exit($failed > 0 ? 1 : 0);
