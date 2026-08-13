<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationproductlocation.class.php
 * \ingroup aeromigration
 * \brief   Range les produits dans les emplacements du dictionnaire d'aerotoolbox.
 *
 * L'extrafield `aerotb_location` du produit accepte plusieurs emplacements — un article se
 * range couramment en réserve et en façade. La source, elle, n'en connaît qu'un :
 * `f_artstock.DP_NoPrincipal` désigne l'emplacement principal, et rien dans le jeu de
 * données livré ne porte les autres. La reprise en écrit donc un seul par produit ; c'est
 * ensuite au client d'en ajouter, ce que le champ permet sans rien changer.
 *
 * ---------------------------------------------------------------------------
 * Ce que le script écrit, et ce qu'il ne touche pas
 * ---------------------------------------------------------------------------
 * Il n'écrit que `array_options['options_aerotb_location']`, par
 * `Product::insertExtraFields()`. Aucune quantité, aucun mouvement : l'emplacement dit où
 * trouver la marchandise, l'entrepôt dit combien il y en a. Confondre les deux ramènerait
 * au modèle en sous-entrepôts qu'on vient d'abandonner.
 *
 * Un produit qui porte déjà des emplacements n'est pas écrasé : la valeur reprise est
 * ajoutée à celles en place si elle en est absente. Relancer le script ne duplique donc
 * rien, et une saisie faite entre-temps par le client survit au passage.
 *
 * ---------------------------------------------------------------------------
 * Correspondance avec le dictionnaire
 * ---------------------------------------------------------------------------
 * `DP_NoPrincipal` renvoie à `f_depotempl.DP_No`, que la reprise des emplacements a
 * fusionné sur l'intitulé : plusieurs numéros source aboutissent à la même entrée. La
 * correspondance se reconstruit donc en deux temps — numéro vers intitulé, puis intitulé
 * vers code du dictionnaire — et non par un identifiant conservé, qui n'aurait pas survécu
 * à la fusion.
 *
 * ---------------------------------------------------------------------------
 * Idempotence
 * ---------------------------------------------------------------------------
 * La cible est un extrafield, sans `ref_ext` ni `import_key` : les chemins génériques du
 * socle qui s'y adossent sont surchargés. Le repère est la présence de l'emplacement dans
 * la valeur du produit — un état, non un marqueur. C'est suffisant ici, l'opération étant
 * par nature réentrante.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');
// Pour normalizeLabel() et locationKey() : le rapprochement doit appliquer exactement le
// traitement qui a servi à construire le dictionnaire.
dol_include_once('/aeromigration/class/migrationlocation.class.php');

class MigrationProductLocation extends AeroMigrationRunner
{
    /** @var string Nom de l'extrafield produit */
    const FIELD = 'aerotb_location';

    /** @var string Table du dictionnaire, sans préfixe */
    const DICT_TABLE = 'c_aerotoolbox_location';

    /** @var int Nombre d'exemples conservés pour le rapport */
    const SAMPLES = 10;

    public $code  = 'productlocation';
    public $label = 'AeroMigScriptProductLocation';

    /** @var string L'export intégral de l'éditeur vit dans une base à part */
    public $sourceDb = 'aeroprod';

    protected $srcTable       = 'f_artstock';
    protected $srcFields      = 'AR_Ref, DE_No, DP_NoPrincipal';
    protected $srcCursorField = 'AR_Ref';
    protected $srcCursorType  = 'string';
    protected $srcKeyField    = 'AR_Ref';

    // Seules les lignes qui désignent un emplacement ont quelque chose à ranger.
    protected $srcWhere = "DE_No = 1 AND DP_NoPrincipal > 0 AND TRIM(AR_Ref) <> ''";

    protected $dstTable   = 'product';
    protected $dstElement = 'product';

    /** @var array<string,int> Index ref article -> rowid produit */
    protected $productIndex = array();

    /** @var array<int,int> Index DP_No source -> rowid du dictionnaire */
    protected $locationIndex = array();

    /** @var array<int,string> Libellé de l'emplacement, pour le rapport */
    protected $locationLabel = array();

    /** @var array<int,string> Emplacements du produit déjà en place, indexés par rowid produit */
    protected $currentValues = array();

    /** @var int Produits absents de Dolibarr */
    protected $missingProduct = 0;

    /** @var array<int,string> Échantillon de ces références */
    protected $missingSamples = array();

    /** @var int Emplacements source sans correspondance dans le dictionnaire */
    protected $missingLocation = 0;

    /** @var array<int,string> Échantillon de ces numéros */
    protected $missingLocationSamples = array();

    /** @var int Produits qui portaient déjà l'emplacement */
    protected $alreadySet = 0;

    /** @var int Produits qui portaient déjà d'autres emplacements, complétés */
    protected $appended = 0;

    /** @var array<string,int> Nombre de produits par emplacement, pour le rapport */
    protected $perLocation = array();

    /**
     * Charge les trois index nécessaires.
     *
     * @return int 1 si tout est en place, -1 sinon
     */
    protected function prepare()
    {
        if ($this->loadLocationIndex() <= 0) {
            return -1;
        }
        if ($this->loadProductIndex() < 0) {
            return -1;
        }
        $this->loadCurrentValues();

        return 1;
    }

    /**
     * Clé de rapprochement d'un intitulé d'emplacement.
     *
     * Déléguée à MigrationLocation, qui a construit les codes du dictionnaire. Les
     * redéfinir ici les ferait diverger : c'est arrivé, et les articles rangés dans les
     * emplacements à l'intitulé abîmé s'étaient retrouvés sans correspondance.
     *
     * @param  string $label Intitulé ou code
     * @return string        Clé normalisée
     */
    protected function indexKey($label)
    {
        return MigrationLocation::locationKey($label);
    }

    /**
     * Reconstruit la correspondance entre les numéros d'emplacement source et le
     * dictionnaire.
     *
     * Le rapprochement se fait sur l'intitulé, seule donnée commune aux deux côtés après
     * la fusion des doublons, et il est fait en PHP plutôt qu'en SQL. Une jointure sur
     * `UPPER(TRIM(DP_Intitule))` paraissait plus directe, mais elle est fausse : `TRIM()`
     * de MySQL ne retire que les espaces, quand `trim()` de PHP retire aussi les
     * tabulations. Or quatre intitulés de la source commencent par une tabulation —
     * « \tS1-B4-3 » — et le dictionnaire les a enregistrés nettoyés. La jointure SQL les
     * manquait, et les articles rangés là restaient sans emplacement.
     *
     * @return int Nombre de correspondances, -1 en cas d'erreur
     */
    protected function loadLocationIndex()
    {
        $this->locationIndex = array();
        $this->locationLabel = array();

        // Le dictionnaire, indexé sur la clé normalisée de son code.
        $byKey = array();

        $sql  = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' WHERE entity IN ('.getEntity(self::DICT_TABLE).')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $byKey[$this->indexKey($obj->code)]      = (int) $obj->rowid;
            $this->locationLabel[(int) $obj->rowid]  = (string) $obj->label;
        }
        $this->db->free($resql);

        if (empty($byKey)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => "Le dictionnaire des emplacements est vide."
                    ." Lancez d'abord : migrate.php location",
            );
            return -1;
        }

        // La source, rapprochée par la même clé. L'intitulé passe d'abord par le même
        // assainissement que lors de la création du dictionnaire — HTML retiré,
        // tabulations ramenées à des espaces, repli sur le numéro quand il ne reste
        // rien — sans quoi les emplacements réécrits n'auraient plus d'antécédent.
        $sql  = 'SELECT DP_No, DP_Intitule FROM '.$this->src('f_depotempl');
        $sql .= ' WHERE DE_No = 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $label = MigrationLocation::labelFor($obj->DP_Intitule, (int) $obj->DP_No);
            $key   = $this->indexKey($label);
            if (isset($byKey[$key])) {
                $this->locationIndex[(int) $obj->DP_No] = $byKey[$key];
            }
        }
        $this->db->free($resql);

        if (empty($this->locationIndex)) {
            $this->errors[] = array(
                'key'     => '',
                'message' => "Aucun emplacement source ne correspond au dictionnaire."
                    ." Le dictionnaire a-t-il été repris depuis la même source ?",
            );
            return -1;
        }

        return count($this->locationIndex);
    }

    /**
     * Charge l'index des produits repris, par référence source.
     *
     * @return int Nombre de produits, -1 en cas d'erreur
     */
    protected function loadProductIndex()
    {
        $this->productIndex = array();

        $sql  = 'SELECT rowid, ref_ext FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE entity IN ('.getEntity('product').')';
        $sql .= " AND ref_ext LIKE '".$this->db->escape($this->refExtPrefix)."%'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $prefixLength = strlen($this->refExtPrefix);
        while ($obj = $this->db->fetch_object($resql)) {
            // Normalisation en minuscules : MySQL a rendu ces valeurs sans égard à la
            // casse, PHP indexerait « EMBALLAGE » et « Emballage » séparément.
            $this->productIndex[strtolower(trim(substr($obj->ref_ext, $prefixLength)))] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return count($this->productIndex);
    }

    /**
     * Charge les emplacements déjà portés par les produits.
     *
     * Une requête pour tous plutôt qu'une lecture par ligne : la table des extrafields
     * produit se parcourt en une fois, quand 6 455 fetch() coûteraient des minutes.
     *
     * @return int Nombre de produits déjà renseignés
     */
    protected function loadCurrentValues()
    {
        $this->currentValues = array();

        $sql  = 'SELECT fk_object, '.self::FIELD.' as val';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_extrafields';
        $sql .= ' WHERE '.self::FIELD." IS NOT NULL AND ".self::FIELD." <> ''";

        // La colonne n'existe pas tant que l'extrafield n'a pas été créé : on préfère un
        // index vide à un arrêt, le script sachant écrire dans une table encore vierge.
        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return 0;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $this->currentValues[(int) $obj->fk_object] = (string) $obj->val;
        }
        $this->db->free($resql);

        return count($this->currentValues);
    }

    /**
     * L'index d'idempotence du socle ne s'applique pas : la cible est un extrafield.
     *
     * @return int 0
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();
        return 0;
    }

    /**
     * Nombre de produits déjà rangés.
     *
     * @return int Nombre de produits portant un emplacement, -1 en cas d'erreur
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'product_extrafields';
        $sql .= ' WHERE '.self::FIELD." IS NOT NULL AND ".self::FIELD." <> ''";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Découpe une valeur d'extrafield en liste d'identifiants.
     *
     * @param  string $value Valeur stockée
     * @return array<int,int> Identifiants
     */
    protected function splitValue($value)
    {
        $out = array();
        foreach (explode(',', (string) $value) as $part) {
            $part = (int) trim($part);
            if ($part > 0) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Vérifie qu'une ligne source est exploitable.
     *
     * Un produit ou un emplacement introuvable n'est pas une erreur : la ligne est écartée
     * et comptée comme telle, ici comme à l'écriture. Les faire lever une exception
     * rendrait la simulation plus sévère que le passage réel — elle annoncerait des
     * erreurs là où rien ne se passerait mal — et ces cas sont déjà détaillés dans le
     * rapport, avec leur remède.
     *
     * @param  stdClass $row Ligne source
     * @return void
     * @throws Exception Si la référence est vide
     */
    protected function validateRow($row)
    {
        if (trim((string) $row->AR_Ref) === '') {
            throw new Exception('Référence article vide');
        }
    }

    /**
     * Action que produirait cette ligne, en simulation.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Inutilisé
     * @return string               created, updated ou skipped
     */
    protected function previewAction($row, $existingId)
    {
        $ref = strtolower(trim((string) $row->AR_Ref));

        if (!isset($this->productIndex[$ref])) {
            $this->noteMissingProduct((string) $row->AR_Ref);
            return 'skipped';
        }
        $productId = $this->productIndex[$ref];

        if (!isset($this->locationIndex[(int) $row->DP_NoPrincipal])) {
            $this->noteMissingLocation((int) $row->DP_NoPrincipal);
            return 'skipped';
        }
        $locationId = $this->locationIndex[(int) $row->DP_NoPrincipal];

        $this->countLocation($locationId);

        $current = isset($this->currentValues[$productId])
            ? $this->splitValue($this->currentValues[$productId]) : array();

        if (in_array($locationId, $current, true)) {
            $this->alreadySet++;
            return 'skipped';
        }
        if (!empty($current)) {
            $this->appended++;
            return 'updated';
        }

        return 'created';
    }

    /**
     * Écrit l'emplacement sur le produit.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Inutilisé
     * @return array{action:string,id:int}
     * @throws Exception En cas d'échec d'écriture
     */
    protected function migrateRow($row, $existingId)
    {
        $ref = strtolower(trim((string) $row->AR_Ref));

        if (!isset($this->productIndex[$ref])) {
            $this->noteMissingProduct((string) $row->AR_Ref);
            return array('action' => 'skipped', 'id' => 0);
        }
        $productId = $this->productIndex[$ref];

        if (!isset($this->locationIndex[(int) $row->DP_NoPrincipal])) {
            $this->noteMissingLocation((int) $row->DP_NoPrincipal);
            return array('action' => 'skipped', 'id' => 0);
        }
        $locationId = $this->locationIndex[(int) $row->DP_NoPrincipal];

        $this->countLocation($locationId);

        $current = isset($this->currentValues[$productId])
            ? $this->splitValue($this->currentValues[$productId]) : array();

        // Déjà rangé là : rien à faire, et surtout pas réécrire la valeur.
        if (in_array($locationId, $current, true)) {
            $this->alreadySet++;
            return array('action' => 'skipped', 'id' => $productId);
        }

        $wasEmpty = empty($current);
        $current[] = $locationId;
        $value = implode(',', $current);

        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            throw new Exception('Produit #'.$productId.' illisible');
        }

        // insertExtraFields() et non update() : seul l'extrafield change, et faire passer
        // le produit entier par update() déclencherait ses déclencheurs et rejouerait des
        // contrôles sans objet ici.
        $product->array_options['options_'.self::FIELD] = $value;
        if ($product->insertExtraFields() <= 0) {
            throw new Exception('Écriture de l\'emplacement impossible : '.$this->objectErrors($product));
        }

        $this->currentValues[$productId] = $value;

        if (!$wasEmpty) {
            $this->appended++;
            return array('action' => 'updated', 'id' => $productId);
        }

        return array('action' => 'created', 'id' => $productId);
    }

    /**
     * Compte les produits rangés dans un emplacement.
     *
     * @param  int $locationId Identifiant du dictionnaire
     * @return void
     */
    protected function countLocation($locationId)
    {
        $label = isset($this->locationLabel[$locationId])
            ? $this->locationLabel[$locationId] : ('#'.$locationId);

        if (!isset($this->perLocation[$label])) {
            $this->perLocation[$label] = 0;
        }
        $this->perLocation[$label]++;
    }

    /**
     * Retient une référence de produit introuvable.
     *
     * @param  string $ref Référence source
     * @return void
     */
    protected function noteMissingProduct($ref)
    {
        $this->missingProduct++;
        if (count($this->missingSamples) < self::SAMPLES) {
            $this->missingSamples[] = $ref;
        }
    }

    /**
     * Retient un emplacement sans correspondance.
     *
     * @param  int $no Numéro source
     * @return void
     */
    protected function noteMissingLocation($no)
    {
        $this->missingLocation++;
        if (count($this->missingLocationSamples) < self::SAMPLES) {
            $this->missingLocationSamples[] = (string) $no;
        }
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        $n = $this->countMigrated();

        $out  = "Vide l'emplacement de tous les produits qui en portent un ("
            .($n < 0 ? '?' : $n).").\n";
        $out .= "Le dictionnaire n'est pas touché, les quantités non plus.\n\n";
        $out .= "Attention : la reprise n'a pas laissé de marqueur sur cette valeur — un\n";
        $out .= "extrafield n'en accueille pas. Un emplacement saisi à la main sera donc\n";
        $out .= "effacé lui aussi.";

        return $out;
    }

    /**
     * Vide l'extrafield sur tous les produits.
     *
     * @param  bool          $confirm  Faux pour un simple décompte
     * @param  callable|null $progress Rappel de progression
     * @return array{count:int,deleted:int,failed:int,errors:array}
     */
    public function purge($confirm = false, $progress = null)
    {
        $result = array('count' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => array());

        $n = $this->countMigrated();
        if ($n < 0) {
            $result['errors'][] = "Extrafield ".self::FIELD." absent : rien à purger.";
            return $result;
        }
        $result['count'] = $n;

        if (!$confirm || $n === 0) {
            return $result;
        }

        // Mise à NULL de la seule colonne concernée : les autres extrafields du produit
        // n'ont pas à disparaître avec elle.
        $sql  = 'UPDATE '.MAIN_DB_PREFIX.'product_extrafields';
        $sql .= ' SET '.self::FIELD.' = NULL';
        $sql .= ' WHERE '.self::FIELD." IS NOT NULL AND ".self::FIELD." <> ''";

        if (!$this->db->query($sql)) {
            $result['failed']   = $n;
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }

        $result['deleted'] = $n;

        if (is_callable($progress)) {
            call_user_func($progress, $result['deleted'], $result['count']);
        }

        return $result;
    }

    /**
     * Rapport de fin de passage.
     *
     * @return array<int,string>
     */
    public function getReport()
    {
        $lines = array();

        $lines[] = 'Dictionnaire : '.count($this->locationIndex)
            .' numéro(s) source rattaché(s) à '.count(array_unique($this->locationIndex))
            .' emplacement(s)';

        if (!empty($this->perLocation)) {
            $lines[] = 'Emplacements servis : '.count($this->perLocation);
            arsort($this->perLocation);
            $shown = 0;
            foreach ($this->perLocation as $label => $count) {
                $lines[] = '     '.str_pad($label, 32).$count.' produit(s)';
                if (++$shown >= self::SAMPLES) {
                    $remaining = count($this->perLocation) - $shown;
                    if ($remaining > 0) {
                        $lines[] = '     … et '.$remaining.' autre(s)';
                    }
                    break;
                }
            }
        }

        if ($this->alreadySet > 0) {
            $lines[] = '';
            $lines[] = $this->alreadySet.' produit(s) portaient déjà cet emplacement.';
        }
        if ($this->appended > 0) {
            $lines[] = $this->appended.' produit(s) en portaient d\'autres : l\'emplacement '
                .'repris s\'y est ajouté sans les remplacer.';
        }

        if ($this->missingProduct > 0) {
            $lines[] = '';
            $lines[] = 'Produits introuvables dans Dolibarr : '.$this->missingProduct;
            $lines[] = '  Ces articles ont un emplacement dans la source mais n\'ont pas été repris.';
            $lines[] = '  Relancer migrate.php product les ferait entrer — à condition qu\'ils';
            $lines[] = '  existent dans f_article. Certains n\'y figurent pas : ce sont des lignes';
            $lines[] = '  de stock orphelines, un rangement pour un article disparu, et aucune';
            $lines[] = '  reprise ne les fera apparaître. Vérifiez avant de relancer :';
            $lines[] = '     SELECT AR_Ref FROM '.$this->src('f_article').' WHERE AR_Ref IN (…);';
            if (!empty($this->missingSamples)) {
                $lines[] = '     '.implode(', ', $this->missingSamples)
                    .($this->missingProduct > count($this->missingSamples) ? ', …' : '');
            }
        }

        if ($this->missingLocation > 0) {
            $lines[] = '';
            $lines[] = 'Emplacements absents du dictionnaire : '.$this->missingLocation;
            $lines[] = '  Le numéro désigné par l\'article n\'a pas d\'équivalent repris.';
            if (!empty($this->missingLocationSamples)) {
                $lines[] = '     n° '.implode(', ', $this->missingLocationSamples)
                    .($this->missingLocation > count($this->missingLocationSamples) ? ', …' : '');
            }
        }

        return $lines;
    }
}
