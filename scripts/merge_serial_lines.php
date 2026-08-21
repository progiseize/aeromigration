<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/merge_serial_lines.php
 * \ingroup aeromigration
 * \brief   Fusionne les lignes « N° SERIE » des factures dans la description de leur article.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CES LIGNES EXISTENT, ET POURQUOI ELLES DOIVENT DISPARAÎTRE
 * ------------------------------------------------------------------------------
 *
 * Dans l'ancien ERP, les vendeurs saisissaient le numéro de série vendu en LIGNE DE TEXTE
 * sous l'article — souvent EN DOUBLE, la source elle-même duplique (vérifié pièce en main sur
 * F990063289 : chaque numéro y figure deux fois). La reprise a été fidèle : ces annotations
 * sont devenues des lignes de facture à quantité nulle, qui polluent les PDF (« 0 / 0,00 /
 * C0 » sous chaque casque).
 *
 * Leur place est dans la DESCRIPTION de la ligne d'article qu'elles suivent. Ce script les y
 * range : texte annexé à la ligne produit la plus proche AU-DESSUS, doublons exacts fusionnés
 * en un, puis la ligne d'annotation est supprimée.
 *
 * ## Le périmètre est volontairement étroit — motif série uniquement
 *
 * Seules les lignes SANS ARTICLE, à QUANTITÉ NULLE, aux MONTANTS NULS, et dont la description
 * COMMENCE par un motif de numéro de série (« N° SERIE », « N°SERIE », « N° DE SERIE »,
 * « NO SERIE », « SN  », « S/N ») sont touchées — 5 556 lignes mesurées au 21/08/2026. Les
 * autres lignes libres (« Composant 1 », « Franco de port », mentions de salon, consignes
 * d'expédition…) ne sont PAS des annotations d'article : elles restent intactes. Une ligne
 * série sans aucune ligne produit au-dessus est conservée et comptée — on ne devine jamais un
 * rattachement.
 *
 * ## Écriture directe assumée, et sans risque sur les montants
 *
 * Les factures sont validées ou payées : l'API du coeur refuse d'en modifier les lignes.
 * L'UPDATE de description et le DELETE des annotations se font donc en SQL direct — exception
 * documentée, de la même famille que la renumérotation. Aucun total n'est affecté : toutes
 * les lignes du périmètre ont des montants STRICTEMENT nuls (vérifié globalement, et
 * re-vérifié ligne à ligne par le script, qui écarte et signale toute exception).
 *
 * Rejouable : une annotation déjà fusionnée n'existe plus, une relance ne trouve rien.
 * Les PDF déjà générés des factures touchées sont à régénérer (génération à la demande).
 *
 * Usage :
 *   php merge_serial_lines.php                       simulation sur tout le parc
 *   php merge_serial_lines.php --ref=FA2526-015038   une seule facture (test)
 *   php merge_serial_lines.php --confirm             applique
 *   php merge_serial_lines.php --limit=100 --confirm par lots
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

/** Motifs de début de description qui désignent une annotation de numéro de série. */
const SERIAL_PATTERN = '/^(N°\s*SERIE|N°\s*DE\s*SERIE|NO\s*SERIE|SN\s|S\/N)/iu';

/** Nombre d'exemples listés par catégorie. */
const SAMPLES = 8;


/*
 * Arguments
 */

$confirm = false;
$limit   = 0;
$refOnly = '';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--ref=(.+)$/', $arg, $m)) {
        $refOnly = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--ref=REF] [--limit=N]\n";
        exit(1);
    }
}

/**
 * La ligne est-elle une annotation de numéro de série fusionnable ?
 *
 * @param  stdClass $l Ligne de facture
 * @return bool
 */
function is_serial_line($l)
{
    if (!empty($l->fk_product)) {
        return false;
    }
    if ((float) $l->qty != 0.0) {
        return false;
    }
    // Ceinture et bretelles : le périmètre mesuré est intégralement à zéro, mais une ligne
    // qui porterait un montant est écartée plutôt que supprimée.
    foreach (array('total_ht', 'total_tva', 'total_ttc') as $f) {
        if (abs((float) $l->$f) > 0.000001) {
            return false;
        }
    }

    return (bool) preg_match(SERIAL_PATTERN, trim(strip_tags((string) $l->description)));
}


/*
 * Repérage des factures candidates : au moins une ligne au motif.
 */

$sql = 'SELECT DISTINCT f.rowid, f.ref FROM '.MAIN_DB_PREFIX.'facture as f'
    .' INNER JOIN '.MAIN_DB_PREFIX.'facturedet as fd ON fd.fk_facture = f.rowid'
    .' WHERE f.entity IN ('.getEntity('facture').')'
    ." AND (fd.fk_product IS NULL OR fd.fk_product = 0) AND fd.qty = 0"
    ." AND UPPER(fd.description) LIKE '%SERIE%'";
if ($refOnly !== '') {
    $sql .= " AND f.ref = '".$db->escape($refOnly)."'";
}
$sql .= ' ORDER BY f.rowid';

$resql = $db->query($sql);
if (!$resql) {
    echo "Erreur SQL : ".$db->lasterror()."\n";
    exit(1);
}
$invoices = array();
while ($obj = $db->fetch_object($resql)) {
    $invoices[] = $obj;
}
$db->free($resql);

echo "Script      : fusion des lignes « N° SERIE » dans la description des articles\n";
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
if ($refOnly !== '') {
    echo "Périmètre   : la seule facture ".$refOnly."\n";
}
printf("Candidates  : %s facture(s)\n", number_format(count($invoices), 0, ',', ' '));
echo str_repeat('-', 60)."\n";

$stats = array('factures' => 0, 'fusionnees' => 0, 'doublons' => 0, 'orphelines' => 0, 'echecs' => 0);
$orphanSamples = array();
$done = 0;

foreach ($invoices as $inv) {
    if ($limit > 0 && $done >= $limit) {
        echo "Limite atteinte (".$limit.").\n";
        break;
    }

    $resql = $db->query('SELECT fd.rowid, fd.fk_product, fd.qty, fd.total_ht, fd.total_tva, fd.total_ttc,'
        .' fd.description, fd.rang, p.label AS product_label'
        .' FROM '.MAIN_DB_PREFIX.'facturedet as fd'
        .' LEFT JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = fd.fk_product'
        .' WHERE fd.fk_facture = '.((int) $inv->rowid)
        .' ORDER BY fd.rang ASC, fd.rowid ASC');
    if (!$resql) {
        $stats['echecs']++;
        continue;
    }
    $lines = array();
    while ($obj = $db->fetch_object($resql)) {
        $lines[] = $obj;
    }
    $db->free($resql);

    // Parcours dans l'ordre du document : chaque annotation rejoint la dernière ligne
    // PRODUIT rencontrée. Les textes sont dédoublonnés — la source duplique.
    $anchorId   = 0;
    $additions  = array();   // rowid d'ancre -> textes à annexer (dédoublonnés)
    $anchorDesc = array();   // rowid d'ancre -> description actuelle
    $toDelete   = array();

    foreach ($lines as $l) {
        if (!empty($l->fk_product)) {
            $anchorId = (int) $l->rowid;
            // La reprise a posé la désignation ADD en description — souvent identique au
            // libellé du produit, que le modèle PDF imprime déjà. La garder ferait sortir le
            // libellé deux fois : la description repart alors de zéro, les numéros seuls.
            $desc = (string) $l->description;
            if (trim($desc) === trim((string) $l->product_label)) {
                $desc = '';
            }
            $anchorDesc[$anchorId] = $desc;
            continue;
        }

        if (!is_serial_line($l)) {
            continue;
        }

        if ($anchorId <= 0) {
            $stats['orphelines']++;
            if (count($orphanSamples) < SAMPLES) {
                $orphanSamples[] = $inv->ref;
            }
            continue;
        }

        $text = trim((string) $l->description);
        $already = isset($additions[$anchorId]) && in_array($text, $additions[$anchorId], true);
        $inDesc  = (strpos($anchorDesc[$anchorId], $text) !== false);

        if ($already || $inDesc) {
            $stats['doublons']++;
        } else {
            $additions[$anchorId][] = $text;
            $stats['fusionnees']++;
        }
        $toDelete[] = (int) $l->rowid;
    }

    if (empty($toDelete)) {
        continue;
    }

    $stats['factures']++;
    $done++;

    if (!$confirm) {
        if ($refOnly !== '' || count($invoices) <= 3) {
            echo $inv->ref." :\n";
            foreach ($additions as $aid => $texts) {
                echo "  ligne produit ".$aid." recevrait : ".implode(' | ', $texts)."\n";
            }
            echo "  ".count($toDelete)." ligne(s) d'annotation supprimée(s)\n";
        }
        continue;
    }

    $db->begin();
    $ok = true;

    foreach ($additions as $aid => $texts) {
        $newDesc = $anchorDesc[$aid];
        foreach ($texts as $t) {
            $newDesc .= ($newDesc !== '' ? "\n" : '').$t;
        }
        if (!$db->query('UPDATE '.MAIN_DB_PREFIX."facturedet SET description = '".$db->escape($newDesc)."'"
            .' WHERE rowid = '.((int) $aid))) {
            $ok = false;
            break;
        }
    }

    if ($ok && !$db->query('DELETE FROM '.MAIN_DB_PREFIX.'facturedet WHERE rowid IN ('.implode(',', $toDelete).')')) {
        $ok = false;
    }

    if ($ok) {
        $db->commit();
    } else {
        $db->rollback();
        $stats['echecs']++;
        echo "  ÉCHEC sur ".$inv->ref." : ".$db->lasterror()."\n";
    }
}

echo str_repeat('-', 60)."\n";
printf("Factures %s : %s\n", $confirm ? 'modifiées' : 'à modifier', number_format($stats['factures'], 0, ',', ' '));
printf("Numéros fusionnés en description : %s\n", number_format($stats['fusionnees'], 0, ',', ' '));
printf("Doublons de la source absorbés   : %s\n", number_format($stats['doublons'], 0, ',', ' '));
if ($stats['orphelines'] > 0) {
    printf("Orphelines laissées en place     : %s — %s\n", $stats['orphelines'], implode(', ', $orphanSamples));
}
if ($stats['echecs'] > 0) {
    printf("Échecs : %d\n", $stats['echecs']);
}
if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();

exit($stats['echecs'] > 0 ? 1 : 0);
