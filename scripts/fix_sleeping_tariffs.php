<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/scripts/fix_sleeping_tariffs.php
 * \ingroup aeromigration
 * \brief   Retire des niveaux de prix les dérogations que l'ancien ERP avait mises en sommeil.
 *
 * ------------------------------------------------------------------------------
 * CE QUI S'EST PASSÉ
 * ------------------------------------------------------------------------------
 *
 * La reprise des tarifs par catégorie (MigrationCustomerPrice) ne regardait que les dates de
 * validité des lignes de `z_tarifparticulier`, la colonne `statut` ayant été jugée
 * inexploitable sur la catégorie Comptoir. Or une ligne mise en sommeil (`statut = 'S'`)
 * garde des dates ouvertes : 75 dérogations en sommeil, sur 57 articles, sont ainsi passées
 * dans les niveaux 1 à 7 — le client l'a relevé sur `#10171` (935,75 € en Aéro-Clubs et
 * Revendeur, lignes de 2019 endormies en 2024). Le moteur exclut désormais ces lignes ; ce
 * script rattrape la base déjà tarifée.
 *
 * ## Ce que fait le script
 *
 * Il RÉUTILISE le moteur de reprise, borné aux articles concernés : la cible de chaque niveau
 * est recalculée comme au jour J — prix de base, ou règle de famille s'il y en a une — avec
 * la fusion des niveaux (site → 1, Aéro-Clubs → 2, Revendeur → 3, Airbus → 4, École → 5,
 * Enac → 6, FFA → 7 ; le Comptoir n'alimente plus rien), et le pilotage par le niveau 1 est
 * reposé en même temps que le prix (aerotb_price_follow / aerotb_price_pct). Écriture par
 * `updatePrice()`, comme la reprise ; niveau 1 en dernier, pour que `llx_product.price` reste
 * juste. L'article en catégorie site touche le niveau 1, donc le prix publié à la boutique.
 *
 * ## Garde : ce qui a été modifié à la main depuis la reprise est conservé
 *
 * Un article n'est corrigé que si TOUT ce qui y change est imputable au sommeil : le niveau
 * porte encore le prix de la ligne endormie, et aucun autre niveau ne diverge de la cible. Un
 * niveau déjà retouché à la main (prix ni égal à celui du sommeil, ni à la cible) ou un autre
 * niveau modifié depuis le 07/09 laissent l'article intact, listé à part au rapport et dans
 * le fichier — à arbitrer avec le client.
 *
 * ## Le fichier
 *
 * Avec `--csv=`, le passage écrit la liste — une ligne par (article, niveau) : réf, libellé,
 * catégorie, niveau, prix en sommeil, prix actuel, prix cible, action. Le fichier remis au
 * client (`tarifs_sommeil_20260914.csv`, racine du projet) a été produit sur la copie prod du
 * 09/09 : l'écart vient de la reprise, la prod n'a rien à y ajouter.
 *
 * Usage :
 *   php fix_sleeping_tariffs.php                 simulation, rien d'écrit
 *   php fix_sleeping_tariffs.php --confirm       applique
 *   php fix_sleeping_tariffs.php --csv=/chemin/liste.csv   écrit aussi la liste (article, niveau)
 *   php fix_sleeping_tariffs.php --source-db=aeroprod   base où sont les tables de l'ancien ERP
 *   php fix_sleeping_tariffs.php --user=LOGIN
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
dol_include_once('/aeromigration/class/migrationcustomerprice.class.php');
dol_include_once('/aeromigration/lib/aeromigration.lib.php');

$langs->loadLangs(array('admin', 'aeromigration@aeromigration'));


/*
 * Arguments
 */

$confirm   = false;
$userLogin = '';
$csvPath   = '';
$sourceDb  = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--user=(.+)$/', $arg, $m)) {
        $userLogin = $m[1];
    } elseif (preg_match('/^--csv=(.+)$/', $arg, $m)) {
        $csvPath = $m[1];
    } elseif (preg_match('/^--source-db=(.*)$/', $arg, $m)) {
        $sourceDb = trim($m[1]);
    } else {
        echo "Argument non reconnu : ".$arg."\n";
        echo "Usage: php ".$script_file." [--confirm] [--csv=FICHIER] [--source-db=BASE] [--user=LOGIN]\n";
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
 * Le moteur de reprise, borné aux articles dont un niveau porte une dérogation en sommeil,
 * et gardé contre les retouches manuelles.
 */
class FixSleepingTariffs extends MigrationCustomerPrice
{
    /** @var string */
    public $code = 'fixsleeping';

    /** @var array<string,array<int,object>> Réf source -> catégorie -> ligne en sommeil qui avait été retenue */
    protected $sleeping = array();

    /** @var array<int,string> Libellé des catégories source */
    protected $catLabels = array(2 => 'Site (niveau 1)', 3 => 'Aéro-Clubs', 4 => 'Revendeur', 5 => 'Airbus',
        6 => 'École de pilotage', 7 => 'Marché Enac', 8 => 'FFA');

    /** @var array<int,array> Lignes du fichier : une par (article, niveau) */
    public $csvRows = array();

    /** @var int Articles laissés intacts : retouchés à la main depuis la reprise */
    public $keptManual = 0;

    /** @var int Articles dont les niveaux sont déjà justes */
    public $alreadyRight = 0;

    /** @var int Niveaux corrigés (ou à corriger) */
    public $fixedLevels = 0;

    /**
     * Après la préparation du moteur (qui, depuis la correction, ignore les lignes S) :
     * retrouve les lignes en sommeil qui auraient été retenues par l'ancienne lecture — celles
     * pour lesquelles aucune ligne active ne prend la place — et borne le parcours à leurs
     * articles.
     *
     * @return int
     */
    protected function prepare()
    {
        if (parent::prepare() < 0) {
            return -1;
        }

        $now = $this->db->idate($this->resolveDate());

        $sql  = 'SELECT AR_Ref, N_CatTarif, DE_No, AR_PrixVen, AR_PrixTTC, remise, cbMarq';
        $sql .= ' FROM '.$this->src('z_tarifparticulier');
        $sql .= ' WHERE N_CatTarif IN ('.implode(', ', $this->sourceCategories()).')';
        $sql .= "   AND TRIM(COALESCE(AR_Ref, '')) <> ''";
        $sql .= '   AND AG_No1 = 0';
        $sql .= "   AND COALESCE(TRIM(CT_Num), '') = ''";
        $sql .= '   AND AR_aPartirDe <= 1';
        $sql .= "   AND statut = 'S'";
        $sql .= "   AND AR_DateDebut <= '".$now."' AND AR_DateFin >= '".$now."'";
        $sql .= ' ORDER BY AR_Ref ASC, N_CatTarif ASC, (DE_No = 1) DESC, AR_DateDebut DESC, cbMarq DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $ref = trim((string) $obj->AR_Ref);
            $cat = (int) $obj->N_CatTarif;
            // Une ligne active existe pour ce couple : l'ancienne lecture la préférait déjà
            // (tri par date), le sommeil n'a rien changé.
            if (isset($this->tariffs[$ref][$cat]) || isset($this->sleeping[$ref][$cat])) {
                continue;
            }
            $this->sleeping[$ref][$cat] = $obj;
        }
        $this->db->free($resql);

        if (empty($this->sleeping)) {
            $this->extraWhere = '1 = 0';
            return 1;
        }
        $refs = array();
        foreach (array_keys($this->sleeping) as $ref) {
            $refs[] = "'".$this->db->escape($ref)."'";
        }
        $this->extraWhere = 'TRIM(AR_Ref) IN ('.implode(', ', $refs).')';

        return 1;
    }

    /**
     * Le prix de la ligne en sommeil, dans la base de la ligne (celui que la reprise a posé).
     *
     * @param  object $line Ligne de z_tarifparticulier
     * @param  array  $base Prix de base de l'article
     * @return array{price:float,base:string}|null null si la ligne agissait par remise
     */
    protected function sleepingPrice($line, array $base)
    {
        $price = (float) $line->AR_PrixVen;
        if (abs($price) > 0) {
            return array('price' => $price, 'base' => !empty($line->AR_PrixTTC) ? 'TTC' : 'HT');
        }
        $rate = (float) $line->remise;
        if (abs($rate) > 0.0001) {
            $d = $this->applyDiscount($base, $rate, 'discount');
            return array('price' => $d['price'], 'base' => $d['base']);
        }

        return null;
    }

    /**
     * Analyse un article : pour chaque niveau, ce qui est en base, ce que valait le sommeil,
     * la cible — et le verdict. Remplit le fichier.
     *
     * @param  object $row Ligne de f_article
     * @return bool        Vrai si l'article peut être corrigé, faux s'il est retouché à la main
     */
    protected function analyse($row)
    {
        $ref       = trim((string) $row->AR_Ref);
        $refExt    = $this->buildRefExt($this->getSourceKey($row));
        $productId = $this->productIndex[$refExt]['id'];
        $vat       = $this->productIndex[$refExt]['tva'];
        $base      = $this->basePrice($row);
        $levels    = $this->computeLevels($row);

        $manual  = false;
        $pending = 0;
        $rows    = array();

        foreach ($levels as $level => $target) {
            $cat      = aeromigration_price_category($level);
            $sleep    = isset($this->sleeping[$ref][$cat]) ? $this->sleepingPrice($this->sleeping[$ref][$cat], $base) : null;
            $current  = isset($this->existingPrices[$productId][$level]) ? $this->existingPrices[$productId][$level] : null;
            $curAmt   = ($current === null) ? null : (($target['base'] === 'TTC') ? $current['price_ttc'] : $current['price']);
            $tgtAmt   = $target['price'];
            $isTarget = ($curAmt !== null && $current['base'] === $target['base'] && abs($curAmt - $tgtAmt) <= self::ROUNDING);

            if ($sleep === null) {
                // Niveau sans sommeil : il doit être à sa cible, sinon quelqu'un l'a retouché.
                if (!$isTarget) {
                    $manual = true;
                    $rows[] = array($level, $cat, null, $curAmt, $tgtAmt, $target['base'], 'retouché à la main, conservé');
                }
                continue;
            }

            $sleepAmt = $this->amountAs(array('price' => $sleep['price'], 'base' => $sleep['base'], 'origin' => ''), $target['base'], $vat);
            if ($isTarget) {
                $rows[] = array($level, $cat, $sleepAmt, $curAmt, $tgtAmt, $target['base'], 'déjà corrigé');
                continue;
            }
            $isSleep = ($curAmt !== null && abs($curAmt - $sleepAmt) <= self::ROUNDING);
            if (!$isSleep) {
                $manual = true;
                $rows[] = array($level, $cat, $sleepAmt, $curAmt, $tgtAmt, $target['base'], 'retouché à la main, conservé');
                continue;
            }
            $pending++;
            $rows[] = array($level, $cat, $sleepAmt, $curAmt, $tgtAmt, $target['base'], $this->dryrun ? 'à corriger' : 'corrigé');
        }

        foreach ($rows as $r) {
            list($level, $cat, $sleepAmt, $curAmt, $tgtAmt, $b, $action) = $r;
            if ($manual && $action !== 'retouché à la main, conservé') {
                $action = 'conservé (article retouché à la main)';
            }
            $this->csvRows[] = array(
                'ref'      => $refExt,
                'pid'      => $productId,
                'niveau'   => $level,
                'categorie' => isset($this->catLabels[$cat]) ? $this->catLabels[$cat] : (string) $cat,
                'sommeil'  => $sleepAmt,
                'actuel'   => $curAmt,
                'cible'    => $tgtAmt,
                'base'     => $b,
                'action'   => $action,
            );
        }

        if ($manual) {
            $this->keptManual++;
            return false;
        }
        if ($pending === 0) {
            $this->alreadyRight++;
        }
        $this->fixedLevels += $pending;

        return true;
    }

    /**
     * Simulation : analyse seule.
     *
     * @param  object $row        Ligne de f_article
     * @param  int    $existingId Toujours 0
     * @return string
     */
    protected function previewAction($row, $existingId)
    {
        $refExt = $this->buildRefExt($this->getSourceKey($row));
        if (!isset($this->productIndex[$refExt])) {
            $this->missingProducts++;
            return 'skipped';
        }
        if (!$this->analyse($row)) {
            return 'skipped';
        }

        return parent::previewAction($row, $existingId);
    }

    /**
     * Écriture : la garde d'abord, le moteur ensuite.
     *
     * @param  object $row        Ligne de f_article
     * @param  int    $existingId Toujours 0
     * @return array{action:string,id:int}
     */
    protected function migrateRow($row, $existingId)
    {
        $refExt = $this->buildRefExt($this->getSourceKey($row));
        if (!isset($this->productIndex[$refExt])) {
            $this->missingProducts++;
            return array('action' => 'skipped', 'id' => 0);
        }
        if (!$this->analyse($row)) {
            return array('action' => 'skipped', 'id' => $this->productIndex[$refExt]['id']);
        }

        return parent::migrateRow($row, $existingId);
    }
}


/*
 * Exécution
 */

$runner = new FixSleepingTariffs($db, $user);
$runner->dryrun = !$confirm;
if ($sourceDb !== null) {
    $runner->sourceDb = ($sourceDb === $db->database_name) ? '' : $sourceDb;
}

$sourceError = $runner->sourceError();
if ($sourceError !== '') {
    echo $sourceError."\n";
    exit(1);
}

echo "Script      : dérogations en sommeil retirées des niveaux de prix\n";
echo "Utilisateur : ".$user->login."\n";
if ($runner->sourceDb !== '') {
    echo "Base source : ".$runner->sourceDb."\n";
}
echo "Mode        : ".($confirm ? "ÉCRITURE" : "SIMULATION (aucune écriture)")."\n";
if ($csvPath !== '') {
    echo "Fichier     : ".$csvPath."\n";
}
echo str_repeat('-', 60)."\n";

$result = $runner->run();

// Libellés et réfs Dolibarr, pour le fichier.
$labels = array();
$ids = array_unique(array_column($runner->csvRows, 'pid'));
if ($ids) {
    $resql = $db->query('SELECT rowid, ref, label FROM '.MAIN_DB_PREFIX.'product WHERE rowid IN ('.implode(',', array_map('intval', $ids)).')');
    while ($resql && ($o = $db->fetch_object($resql))) {
        $labels[(int) $o->rowid] = array($o->ref, $o->label);
    }
}

$csvWritten = false;
$fh = ($csvPath !== '') ? @fopen($csvPath, 'w') : false;
if ($csvPath !== '' && !$fh) {
    echo "\nFichier inaccessible en écriture : ".$csvPath." — relancez avec --csv=/un/chemin/inscriptible.csv\n";
} elseif ($fh) {
    $csvWritten = true;
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('Référence', 'Libellé', 'Niveau', 'Catégorie tarifaire', 'Prix en sommeil (appliqué par erreur)', 'Prix actuel', 'Prix corrigé', 'Base', 'Action'), ';', '"', '\\');
    $fmt = function ($v) {
        return ($v === null) ? '' : number_format((float) $v, 2, ',', '');
    };
    foreach ($runner->csvRows as $r) {
        $l = isset($labels[$r['pid']]) ? $labels[$r['pid']] : array($r['ref'], '');
        fputcsv($fh, array($l[0], $l[1], $r['niveau'], $r['categorie'], $fmt($r['sommeil']), $fmt($r['actuel']), $fmt($r['cible']), $r['base'], $r['action']), ';', '"', '\\');
    }
    fclose($fh);
}

echo "\n".str_repeat('-', 60)."\n";
printf("Articles examinés            : %d\n", $runner->stats['read']);
printf("Articles %s : %d\n", $confirm ? 'corrigés          ' : 'à corriger        ', $runner->stats['updated'] + $runner->stats['created']);
printf("  niveaux %s : %d\n", $confirm ? 'corrigés ' : 'à corriger', $runner->fixedLevels);
printf("Articles déjà justes         : %d\n", $runner->alreadyRight);
printf("Articles retouchés à la main : %d (conservés, à arbitrer — voir le fichier)\n", $runner->keptManual);
printf("Erreurs                      : %d\n", $runner->stats['error']);
foreach ($runner->errors as $e) {
    echo "  - ".$e['message']."\n";
}
if ($csvWritten) {
    echo "\nFichier écrit : ".$csvPath." (".count($runner->csvRows)." lignes)\n";
}

if (!$confirm) {
    echo "\nSimulation : rien n'a été écrit. Relancez avec --confirm pour appliquer.\n";
}

$db->close();
exit(($result < 0 || $runner->stats['error'] > 0) ? 1 : 0);
