<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/link_shop_addresses.php
 * \ingroup aeromigration
 * \brief   Relie les adresses de la boutique aux contacts Dolibarr qui les portent déjà.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI
 * ------------------------------------------------------------------------------
 *
 * Dolibarr ne connaît pas les adresses : une adresse de livraison y est un CONTACT. PrestaSync tient
 * la correspondance dans `llx_prestasync_address`, mais ne la pose qu'au passage d'une commande. Les
 * contacts repris de l'ancien ERP n'en ont donc aucune : **910 adresses de la boutique sont liées
 * sur 148 453**.
 *
 * Deux conséquences, et la seconde coûte tous les jours :
 *
 * - depuis aeropresta 0.36.0, une adresse que le client modifie en ligne est reportée sur son
 *   contact — à condition de savoir lequel. Sans liaison, la fiche passe en orange et rien n'est
 *   écrit ;
 * - PrestaSync, ne reconnaissant pas l'adresse, **crée un contact de plus à la première commande**
 *   qui l'utilise. Chaque ancien client qui recommande reçoit ainsi le doublon de son propre contact.
 *
 * ------------------------------------------------------------------------------
 * CE QUI EST RELIÉ, ET CE QUI NE L'EST PAS
 * ------------------------------------------------------------------------------
 *
 * Une adresse de la boutique est reliée quand, parmi les contacts actifs et encore libres du tiers,
 * **un seul porte la même rue et le même code postal** — et qu'elle est elle-même la seule adresse
 * du compte à les porter. Deux passes :
 *
 * 1. à l'espace et à la casse près — la règle qu'applique déjà la fenêtre de relecture ;
 * 2. puis, sur ce qui reste, en ignorant en plus les accents et la ponctuation : « 12, rue de
 *    l'Église » et « 12 RUE DE L EGLISE » sont la même adresse.
 *
 * Tout le reste est laissé tel quel, parce que le relier demanderait de deviner (arbitrage du 06/10) :
 *
 * - plusieurs contacts du tiers portent cette adresse, ou plusieurs adresses du compte sont
 *   identiques : on ne sait pas lequel choisir ;
 * - le tiers n'a qu'un contact et le compte qu'une adresse, mais elles diffèrent — un code postal
 *   tronqué, un « Default value » : les relier reporterait l'une sur l'autre à la prochaine
 *   modification ;
 * - aucun contact ne porte l'adresse : elle est inconnue de Dolibarr, et PrestaSync la créera.
 *
 * Une adresse sans rue n'est jamais reliée : deux contacts d'une même ville se ressembleraient.
 *
 * ------------------------------------------------------------------------------
 * LA SOURCE : LE WEBSERVICE DE LA BOUTIQUE
 * ------------------------------------------------------------------------------
 *
 * Les adresses se lisent par le canal qu'utilise déjà aeropresta, page par page (5 000 adresses en
 * moins de deux secondes, y compris en fin de table). **Tout est lu avant que rien ne soit écrit** :
 * une page en échec arrête le script, la base intacte.
 *
 * ------------------------------------------------------------------------------
 * ÉCRITURE
 * ------------------------------------------------------------------------------
 *
 * - une ligne par liaison dans `llx_prestasync_address` — l'INSERT direct est le seul chemin, comme
 *   pour `relink_prestasync.php` : le module n'offre aucune API pour peupler ses tables ;
 * - le libellé de l'adresse, sa société et son n° de TVA dans les trois extrafields du contact
 *   (`aerotb_ps_alias`, `aerotb_ps_company`, `aerotb_ps_vat`), **seulement là où ils sont vides** :
 *   ce que quelqu'un a saisi dans Dolibarr n'est jamais écrasé.
 *
 * Aucun contact n'est modifié : ni son adresse, ni son nom. On pose un lien, on ne recopie rien.
 *
 * Le tout dans une seule transaction — tout passe, ou rien. Chaque liaison porte l'horodatage de la
 * passe dans `date_creation`, et la trace CSV, obligatoire en écriture, les liste une par une. Pour
 * défaire les liaisons :
 *
 *     DELETE FROM llx_prestasync_address WHERE date_creation = '<horodatage affiché en fin de passe>';
 *
 * Rejouable : une adresse ou un contact déjà lié sort du périmètre de lui-même.
 *
 * Usage :
 *   php link_shop_addresses.php                              simulation : chiffres seuls
 *   php link_shop_addresses.php --csv=liaisons.csv           simulation + ce qui serait relié
 *   php link_shop_addresses.php --confirm --csv=liaisons.csv applique
 *   php link_shop_addresses.php --limit=100 --confirm --csv=essai.csv   lot d'essai
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

@ini_set('memory_limit', '2G');
@set_time_limit(0);

dol_include_once('/aeropresta/lib/aeropresta_state.lib.php');
dol_include_once('/aeropresta/lib/aeropresta_profile.lib.php');
if (!function_exists('aeropresta_ps_ws') || !function_exists('aeropresta_prof_norm')) {
    echo "Le module aeropresta est requis (lecture de la boutique).\n";
    exit(1);
}

/** Adresses lues par appel au webservice. */
const PAGE = 5000;

/** Lignes écrites par requête. */
const CHUNK = 500;

/** Nombre d'exemples listés au rapport. */
const SAMPLES = 6;


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;
$csv     = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--csv=(.+)$/', $arg, $m)) {
        $csv = $m[1];
        if (strpos($csv, '~') === 0) {
            $csv = getenv('HOME').substr($csv, 1);
        }
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--limit=N] [--csv=FICHIER]\n";
        exit(1);
    }
}

// La trace est le seul inventaire de ce qui a été posé : pas d'écriture sans elle.
if ($confirm && $csv === '') {
    echo "--confirm exige --csv=FICHIER : la trace liste chaque liaison posée, et sert à la défaire.\n";
    exit(1);
}


/*
 * Les deux formes de comparaison
 */

/**
 * La clé d'une adresse, à l'espace et à la casse près : la règle de la fenêtre de relecture.
 *
 * @param  string $street Rue
 * @param  string $zip    Code postal
 * @return string         Vide si la rue l'est
 */
function ls_key_exact($street, $zip)
{
    $rue = aeropresta_prof_norm(preg_replace('/\s+/', ' ', (string) $street));

    return ($rue === '') ? '' : $rue.'|'.aeropresta_prof_norm($zip);
}

/**
 * La même clé, en ignorant en plus les accents et la ponctuation.
 *
 * @param  string $street Rue
 * @param  string $zip    Code postal
 * @return string         Vide si la rue l'est
 */
function ls_key_loose($street, $zip)
{
    $f = function ($v) {
        $v = strtolower(dol_string_unaccent((string) $v));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $v)));
    };
    $rue = $f($street);

    return ($rue === '') ? '' : $rue.'|'.$f($zip);
}


/*
 * Chargement de Dolibarr
 */

$shop = aeropresta_ps_shop($db);
if (!$shop) {
    echo "Boutique non configurée.\n";
    exit(1);
}
$fkPresta = (int) $shop->rowid;

echo "Base : ".$conf->db->name."   Boutique : ".$shop->shop_url."   Mode : ".($confirm ? 'ÉCRITURE' : 'simulation')."\n\n";

// Compte boutique => tiers.
$socOf = array();
$resql = $db->query('SELECT fk_customer_presta, fk_soc_doli FROM '.MAIN_DB_PREFIX.'prestasync_customer'
    .' WHERE fk_presta = '.$fkPresta);
while ($resql && ($o = $db->fetch_object($resql))) {
    $socOf[(int) $o->fk_customer_presta] = (int) $o->fk_soc_doli;
}

// Ce qui est déjà lié, dans les deux sens : ni l'adresse ni le contact ne se relient deux fois.
$linkedAddr    = array();
$linkedContact = array();
$resql = $db->query('SELECT fk_address_presta, fk_socpeople_doli FROM '.MAIN_DB_PREFIX.'prestasync_address'
    .' WHERE fk_presta = '.$fkPresta);
while ($resql && ($o = $db->fetch_object($resql))) {
    $linkedAddr[(int) $o->fk_address_presta]    = true;
    $linkedContact[(int) $o->fk_socpeople_doli] = true;
}

// Les trois extrafields existent-ils ? Ils viennent d'AeroToolbox ; sans eux on relie quand même.
$hasEf = true;
foreach (array('aerotb_ps_alias', 'aerotb_ps_company', 'aerotb_ps_vat') as $col) {
    $resql = $db->query('SHOW COLUMNS FROM '.MAIN_DB_PREFIX."socpeople_extrafields LIKE '".$col."'");
    if (!$resql || $db->num_rows($resql) === 0) {
        $hasEf = false;
    }
}

// Les contacts actifs et encore libres, par tiers, avec leurs deux clés.
$free  = array();
$sql   = 'SELECT c.rowid, c.fk_soc, c.address, c.zip, c.town, c.lastname, c.firstname';
$sql  .= $hasEf ? ', ef.aerotb_ps_alias as alias, ef.aerotb_ps_company as company, ef.aerotb_ps_vat as vat' : '';
$sql  .= ' FROM '.MAIN_DB_PREFIX.'socpeople as c';
$sql  .= $hasEf ? ' LEFT JOIN '.MAIN_DB_PREFIX.'socpeople_extrafields as ef ON ef.fk_object = c.rowid' : '';
$sql  .= ' WHERE c.fk_soc IS NOT NULL AND c.statut = 1';
$resql = $db->query($sql);
if (!$resql) {
    echo "Lecture des contacts impossible : ".$db->lasterror()."\n";
    exit(1);
}
$nbFree = 0;
while ($o = $db->fetch_object($resql)) {
    if (isset($linkedContact[(int) $o->rowid])) {
        continue;
    }
    $free[(int) $o->fk_soc][(int) $o->rowid] = array(
        'e'       => ls_key_exact($o->address, $o->zip),
        'l'       => ls_key_loose($o->address, $o->zip),
        'name'    => trim($o->lastname.' '.$o->firstname),
        'addr'    => trim(preg_replace('/\s+/', ' ', (string) $o->address).' '.$o->zip.' '.$o->town),
        'alias'   => $hasEf ? trim((string) $o->alias) : '',
        'company' => $hasEf ? trim((string) $o->company) : '',
        'vat'     => $hasEf ? trim((string) $o->vat) : '',
    );
    $nbFree++;
}
$db->free($resql);

printf("Comptes boutique liés à un tiers          : %7d\n", count($socOf));
printf("Liaisons adresse ↔ contact existantes     : %7d\n", count($linkedAddr));
printf("Contacts actifs encore libres             : %7d\n", $nbFree);


/*
 * Lecture de la boutique — tout, avant d'écrire quoi que ce soit
 */

$byCustomer = array();   // compte => adresses vivantes non liées
$count = array('read' => 0, 'deleted' => 0, 'unlinked_customer' => 0, 'already' => 0, 'candidates' => 0);
$fields = '[id,id_customer,alias,company,address1,address2,postcode,city,vat_number,deleted]';

$offset = 0;
while (true) {
    list($code, $body) = aeropresta_ps_ws($shop, 'GET',
        'addresses?output_format=JSON&display='.$fields.'&sort=[id_ASC]&limit='.$offset.','.PAGE);
    if ($code !== 200) {
        echo "\nLa boutique a répondu HTTP ".$code." à l'adresse n° ".$offset." : lecture interrompue, rien n'a été écrit.\n";
        exit(1);
    }
    $decoded = json_decode($body, true);
    $rows    = (is_array($decoded) && isset($decoded['addresses'])) ? $decoded['addresses'] : array();
    if (!is_array($decoded)) {
        echo "\nRéponse illisible de la boutique à l'adresse n° ".$offset." : lecture interrompue, rien n'a été écrit.\n";
        exit(1);
    }

    foreach ($rows as $a) {
        $count['read']++;
        $id   = isset($a['id']) ? (int) $a['id'] : 0;
        $cust = isset($a['id_customer']) ? (int) $a['id_customer'] : 0;
        if ($id <= 0 || !empty($a['deleted'])) {
            $count['deleted']++;
            continue;
        }
        if ($cust <= 0 || !isset($socOf[$cust])) {
            $count['unlinked_customer']++;
            continue;
        }
        if (isset($linkedAddr[$id])) {
            $count['already']++;
            continue;
        }
        $count['candidates']++;
        $rue = trim((string) (isset($a['address1']) ? $a['address1'] : ''));
        if (!empty($a['address2']) && trim((string) $a['address2']) !== '') {
            $rue = trim($rue.' '.trim((string) $a['address2']));
        }
        $zip = isset($a['postcode']) ? (string) $a['postcode'] : '';
        $byCustomer[$cust][$id] = array(
            'e'       => ls_key_exact($rue, $zip),
            'l'       => ls_key_loose($rue, $zip),
            'addr'    => trim($rue.' '.$zip.' '.(isset($a['city']) ? $a['city'] : '')),
            'alias'   => trim((string) (isset($a['alias']) ? $a['alias'] : '')),
            'company' => trim((string) (isset($a['company']) ? $a['company'] : '')),
            'vat'     => trim((string) (isset($a['vat_number']) ? $a['vat_number'] : '')),
        );
    }

    if (count($rows) < PAGE) {
        break;
    }
    $offset += PAGE;
    echo "  … ".$count['read']." adresses lues\r";
}

printf("Adresses lues sur la boutique             : %7d\n", $count['read']);
printf("  supprimées                              : %7d\n", $count['deleted']);
printf("  d'un compte sans tiers Dolibarr         : %7d\n", $count['unlinked_customer']);
printf("  déjà liées                              : %7d\n", $count['already']);
printf("  à examiner                              : %7d\n", $count['candidates']);


/*
 * Appariement
 */

$links = array();
$why   = array('exact' => 0, 'loose' => 0, 'no_street' => 0, 'many_contacts' => 0, 'many_addresses' => 0,
    'one_one_differ' => 0, 'unknown' => 0);
$samples = array('many_contacts' => array(), 'one_one_differ' => array(), 'unknown' => array());

/**
 * Relie, pour un compte, les adresses et les contacts qui se répondent un à un sur une clé.
 *
 * @param  array  $addrs    Adresses restantes (par référence : celles qui sont reliées en sortent)
 * @param  array  $contacts Contacts restants (idem)
 * @param  string $k        'e' pour la clé exacte, 'l' pour la tolérante
 * @return array            adresse => contact
 */
function ls_pair(&$addrs, &$contacts, $k)
{
    $byKeyA = array();
    foreach ($addrs as $ida => $a) {
        if ($a[$k] !== '') {
            $byKeyA[$a[$k]][] = $ida;
        }
    }
    $byKeyC = array();
    foreach ($contacts as $idc => $c) {
        if ($c[$k] !== '') {
            $byKeyC[$c[$k]][] = $idc;
        }
    }

    $out = array();
    foreach ($byKeyA as $key => $ids) {
        // Une seule adresse du compte ET un seul contact du tiers : sinon il faudrait choisir.
        if (count($ids) !== 1 || !isset($byKeyC[$key]) || count($byKeyC[$key]) !== 1) {
            continue;
        }
        $out[$ids[0]] = $byKeyC[$key][0];
    }
    foreach ($out as $ida => $idc) {
        unset($addrs[$ida], $contacts[$idc]);
    }

    return $out;
}

foreach ($byCustomer as $cust => $addrs) {
    $soc      = $socOf[$cust];
    $contacts = isset($free[$soc]) ? $free[$soc] : array();
    $all      = $addrs;
    $allCt    = $contacts;

    foreach (array('e' => 'exact', 'l' => 'loose') as $k => $mode) {
        foreach (ls_pair($addrs, $contacts, $k) as $ida => $idc) {
            $why[$mode]++;
            $links[] = array('addr' => $ida, 'contact' => $idc, 'cust' => $cust, 'soc' => $soc, 'mode' => $mode,
                'shop' => $all[$ida], 'doli' => $allCt[$idc]);
        }
    }

    // Ce qui reste, et pourquoi.
    foreach ($addrs as $ida => $a) {
        if ($a['e'] === '') {
            $why['no_street']++;
            continue;
        }
        $nbC = 0;
        foreach ($contacts as $c) {
            if ($c['e'] === $a['e'] || $c['l'] === $a['l']) {
                $nbC++;
            }
        }
        $nbA = 0;
        foreach ($addrs as $b) {
            if ($b['e'] === $a['e'] || $b['l'] === $a['l']) {
                $nbA++;
            }
        }
        if ($nbC > 1) {
            $why['many_contacts']++;
            if (count($samples['many_contacts']) < SAMPLES) {
                $samples['many_contacts'][] = 'compte '.$cust.' : « '.$a['addr'].' » portée par '.$nbC.' contacts';
            }
        } elseif ($nbC === 1 && $nbA > 1) {
            $why['many_addresses']++;
        } elseif (count($all) === 1 && count($allCt) === 1) {
            $why['one_one_differ']++;
            if (count($samples['one_one_differ']) < SAMPLES) {
                $c = reset($allCt);
                $samples['one_one_differ'][] = 'boutique « '.$a['addr'].' » / Dolibarr « '.$c['addr'].' »';
            }
        } else {
            $why['unknown']++;
            if (count($samples['unknown']) < SAMPLES) {
                $samples['unknown'][] = 'compte '.$cust.' : « '.$a['addr'].' » — '.count($allCt).' contact(s) libre(s)';
            }
        }
    }
}

echo "\n";
printf("À RELIER — un seul contact porte la même rue et le même code postal : %7d\n", $why['exact']);
printf("À RELIER — en ignorant accents et ponctuation                       : %7d\n", $why['loose']);
echo "\nLaissées telles quelles :\n";
printf("  plusieurs contacts portent l'adresse                 : %7d\n", $why['many_contacts']);
printf("  plusieurs adresses du compte sont identiques         : %7d\n", $why['many_addresses']);
printf("  une adresse, un contact, mais adresses différentes   : %7d\n", $why['one_one_differ']);
printf("  inconnue de Dolibarr                                 : %7d\n", $why['unknown']);
printf("  sans rue                                             : %7d\n", $why['no_street']);

foreach (array('many_contacts' => 'plusieurs contacts', 'one_one_differ' => 'une adresse, un contact, différentes',
    'unknown' => 'inconnue de Dolibarr') as $key => $title) {
    if ($samples[$key]) {
        echo "\nExemples — ".$title." :\n   ".implode("\n   ", $samples[$key])."\n";
    }
}

if ($limit > 0) {
    $links = array_slice($links, 0, $limit);
}

// Ce que chaque contact recevra dans ses extrafields : seulement là où il n'a rien.
$efRows = array();
$efCount = array('alias' => 0, 'company' => 0, 'vat' => 0);
foreach ($links as $i => $l) {
    $set = array();
    foreach (array('alias', 'company', 'vat') as $f) {
        if ($hasEf && $l['shop'][$f] !== '' && $l['doli'][$f] === '') {
            $set[$f] = $l['shop'][$f];
            $efCount[$f]++;
        }
    }
    $links[$i]['ef'] = $set;
    if ($set) {
        $efRows[$l['contact']] = $set;
    }
}

echo "\n";
printf("Liaisons à poser%s : %d\n", $limit > 0 ? ' (lot limité)' : '', count($links));
if ($hasEf) {
    printf("Contacts qui recevront un libellé : %d — une société : %d — un n° de TVA : %d\n",
        $efCount['alias'], $efCount['company'], $efCount['vat']);
} else {
    echo "Extrafields aerotb_ps_* absents : les libellés ne seront pas posés (réactiver AeroToolbox).\n";
}


/*
 * La trace
 */

if ($csv !== '') {
    $fh = fopen($csv, 'w');
    if (!$fh) {
        echo "Impossible d'écrire ".$csv."\n";
        exit(1);
    }
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('id_adresse_boutique', 'id_compte_boutique', 'id_tiers', 'id_contact', 'rapprochement',
        'adresse_boutique', 'contact', 'adresse_dolibarr', 'libelle_pose', 'societe_posee', 'tva_posee'), ';');
    foreach ($links as $l) {
        fputcsv($fh, array($l['addr'], $l['cust'], $l['soc'], $l['contact'],
            $l['mode'] === 'exact' ? 'exact' : 'accents et ponctuation',
            $l['shop']['addr'], $l['doli']['name'], $l['doli']['addr'],
            isset($l['ef']['alias']) ? $l['ef']['alias'] : '',
            isset($l['ef']['company']) ? $l['ef']['company'] : '',
            isset($l['ef']['vat']) ? $l['ef']['vat'] : ''), ';');
    }
    fclose($fh);
    echo "Trace : ".$csv." (".count($links)." lignes)\n";
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit.\n";
    exit(0);
}
if (!$links) {
    echo "\nRien à relier.\n";
    exit(0);
}


/*
 * Écriture — une seule transaction
 */

// L'horodatage de la passe marque chaque liaison : c'est lui qui permet de les retrouver.
$stamp = $db->idate(dol_now());
$error = '';

$db->begin();

foreach (array_chunk($links, CHUNK) as $chunk) {
    $values = array();
    foreach ($chunk as $l) {
        $values[] = '('.$fkPresta.', '.((int) $l['contact']).', '.((int) $l['addr']).", '".$db->escape($stamp)."')";
    }
    if (!$db->query('INSERT INTO '.MAIN_DB_PREFIX.'prestasync_address'
        .' (fk_presta, fk_socpeople_doli, fk_address_presta, date_creation) VALUES '.implode(',', $values))) {
        $error = $db->lasterror();
        break;
    }
}

if ($error === '' && $efRows) {
    // Une valeur n'est posée que sur un champ vide : `IF(…, VALUES(col), col)` laisse intact ce que
    // quelqu'un aurait saisi entre la lecture et l'écriture.
    // Chaque valeur est ramenée à la taille de sa colonne — garde-fou : une base en mode strict
    // refuse l'excédent, et la passe du 08/10/2026 s'est arrêtée dessus (« Data too long »). La
    // société est à 255 depuis aerotoolbox 1.63.11, comme sur la boutique ; sur une base qui n'a
    // pas encore été réactivée, elle serait encore à 128 et la passe échouerait de même.
    $sizes = array('alias' => 128, 'company' => 255, 'vat' => 32);
    $quote = function ($set, $f) use ($db, $sizes) {
        return isset($set[$f]) ? "'".$db->escape(dol_substr(trim((string) $set[$f]), 0, $sizes[$f]))."'" : 'NULL';
    };
    $keep = function ($col) {
        return $col." = IF(COALESCE(".$col.", '') = '' AND VALUES(".$col.") IS NOT NULL, VALUES(".$col."), ".$col.")";
    };
    foreach (array_chunk($efRows, CHUNK, true) as $chunk) {
        $values = array();
        foreach ($chunk as $cid => $set) {
            $values[] = '('.((int) $cid).', '.$quote($set, 'alias').', '.$quote($set, 'company').', '.$quote($set, 'vat').')';
        }
        if (!$db->query('INSERT INTO '.MAIN_DB_PREFIX.'socpeople_extrafields'
            .' (fk_object, aerotb_ps_alias, aerotb_ps_company, aerotb_ps_vat) VALUES '.implode(',', $values)
            .' ON DUPLICATE KEY UPDATE '.$keep('aerotb_ps_alias').', '.$keep('aerotb_ps_company').', '.$keep('aerotb_ps_vat'))) {
            $error = $db->lasterror();
            break;
        }
    }
}

if ($error !== '') {
    $db->rollback();
    echo "\nÉCHEC, rien n'a été écrit : ".$error."\n";
    exit(1);
}
$db->commit();

$written = 0;
$resql   = $db->query('SELECT COUNT(*) as n FROM '.MAIN_DB_PREFIX."prestasync_address WHERE date_creation = '".$db->escape($stamp)."'");
if ($resql && ($o = $db->fetch_object($resql))) {
    $written = (int) $o->n;
}
if ($written !== count($links)) {
    echo "\nATTENTION : ".count($links)." liaison(s) envoyée(s) à l'écriture, ".$written." retrouvée(s) sous l'horodatage ".$stamp.".\n";
}

echo "\n".$written." liaison(s) posée(s).\n";
echo "Horodatage de la passe : ".$stamp."\n";
echo "Pour défaire les liaisons :\n";
echo "   DELETE FROM ".MAIN_DB_PREFIX."prestasync_address WHERE date_creation = '".$stamp."';\n";
echo "Les libellés, sociétés et n° de TVA posés sont listés dans la trace ; ils n'ont écrasé aucune valeur.\n";
