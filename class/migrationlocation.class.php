<?php
/* Copyright (C) 2026 Progiseize */

/**
 * \file    custom/aeromigration/class/migrationlocation.class.php
 * \ingroup aeromigration
 * \brief   Reprise des emplacements de stockage dans le dictionnaire d'aerotoolbox.
 *
 * Un emplacement désigne un rangement physique — travée, niveau, tiroir — à l'intérieur
 * d'un entrepôt. Les premiers travaux en avaient fait des sous-entrepôts Dolibarr, ce qui
 * plaçait sept cents entrées dans chaque sélecteur d'entrepôt et transformait un
 * déplacement d'étagère en transfert de stock. Le client ne gère qu'un entrepôt réel ;
 * l'emplacement redevient ici ce qu'il est, une information de repérage, portée par le
 * dictionnaire `c_aerotoolbox_location`.
 *
 * ---------------------------------------------------------------------------
 * Trois particularités de ce script
 * ---------------------------------------------------------------------------
 *
 * **1. La cible est un dictionnaire, écrit en SQL.** La règle du module veut que les
 * écritures passent par les objets métier de Dolibarr. Un dictionnaire n'en a pas : le
 * coeur les administre lui-même en SQL depuis `admin/dict.php`, et aucune classe ne porte
 * `table_element = 'c_aerotoolbox_location'`. Le SQL direct est donc ici la seule voie, et
 * elle est celle du coeur — pas un contournement.
 *
 * **2. La source est fusionnée sur l'intitulé.** `f_depotempl` compte 1 007 lignes pour
 * 868 intitulés distincts : « BOUTIQUE » y figure huit fois, « S1-A11-2 » sept fois, sous
 * des numéros différents. Ce sont des saisies répétées du même endroit. Les reprendre
 * telles quelles donnerait huit entrées indiscernables dans la liste déroulante.
 *
 * **3. L'intitulé sert de clé, pas le code.** `DP_Code` est inexploitable dans les données
 * livrées : tronqué à treize caractères — « STOCK SECURIS » pour « STOCK SECURISE » — et
 * corrompu sur treize lignes, où il vaut « <input type= », vestige d'une saisie qui a mal
 * tourné dans l'ERP d'origine. `DP_Intitule` est intact et sert donc de code comme de
 * libellé.
 *
 * ---------------------------------------------------------------------------
 * Idempotence
 * ---------------------------------------------------------------------------
 * La table cible n'a pas de `ref_ext`, et les chemins génériques du socle qui s'appuient
 * dessus — `loadMigratedIndex()`, `countMigrated()`, `purge()` — sont tous surchargés.
 * L'identité d'un emplacement est son `code`, unique par entité : c'est sur lui que le
 * script se raccroche. `import_key` marque en plus ce qui vient de la reprise, ce qui
 * permet à la purge de ne défaire que son propre travail et de laisser intact un
 * emplacement créé à la main.
 */

dol_include_once('/aeromigration/class/aeromigrationrunner.class.php');

class MigrationLocation extends AeroMigrationRunner
{
    /** @var string Marqueur porté par les entrées créées par la reprise */
    const IMPORT_KEY = 'SAGE:EMPLACEMENT';

    /** @var string Table du dictionnaire, sans préfixe */
    const DICT_TABLE = 'c_aerotoolbox_location';

    public $code  = 'location';
    public $label = 'AeroMigScriptLocation';

    /**
     * L'export intégral de l'éditeur vit dans une base à part : `f_depotempl` n'a jamais
     * été importée à côté des `llx_*`. La valeur reste modifiable par --source-db.
     *
     * @var string
     */
    public $sourceDb = 'aeroprod';

    protected $srcTable       = 'f_depotempl';
    protected $srcFields      = 'DP_No, DE_No, DP_Code, DP_Intitule';
    protected $srcCursorField = 'DP_No';
    protected $srcCursorType  = 'int';
    protected $srcKeyField    = 'DP_No';

    // Le dossier ne compte qu'un dépôt, et les emplacements des autres — s'il en
    // apparaissait — ne sauraient pas à quel entrepôt se rattacher.
    protected $srcWhere = "DE_No = 1 AND TRIM(COALESCE(DP_Intitule, '')) <> ''";

    protected $dstTable   = self::DICT_TABLE;
    protected $dstElement = self::DICT_TABLE;

    /** @var int rowid de l'entrepôt auquel tous les emplacements sont rattachés */
    protected $warehouseId = 0;

    /** @var string Référence de cet entrepôt, pour le rapport */
    protected $warehouseRef = '';

    /** @var array<string,int> Index code normalisé -> rowid des entrées du dictionnaire */
    protected $codeIndex = array();

    /** @var array<string,int> Entrées vues pendant ce passage, pour repérer les fusions */
    protected $seen = array();

    /** @var int Nombre de lignes source absorbées par une entrée déjà créée */
    protected $merged = 0;

    /** @var array<string,int> Intitulés ayant absorbé plus d'une ligne source */
    protected $mergeSamples = array();

    /** @var int Nombre d'entrées du dictionnaire présentes avant le passage */
    protected $existingBefore = 0;

    /** @var int Intitulés que le nettoyage a vidés, repris sous leur numéro d'origine */
    protected $unusable = 0;

    /** @var array<int,int> Échantillon de ces numéros */
    protected $unusableSamples = array();

    /** @var array<int,string> Intitulé assaini, mémorisé par numéro source */
    protected $cleanCache = array();

    /** @var int Intitulés portant un caractère de remplacement à la place d'un accent */
    protected $damaged = 0;

    /** @var array<int,string> Échantillon de ces intitulés, indexé par numéro source */
    protected $damagedSamples = array();

    /**
     * Prépare l'entrepôt de rattachement et l'index des codes déjà présents.
     *
     * @return int 1 si tout est en place, -1 sinon
     */
    protected function prepare()
    {
        if ($this->loadWarehouse() <= 0) {
            return -1;
        }
        $this->loadCodeIndex();

        return 1;
    }

    /**
     * Retrouve l'entrepôt principal, celui que la reprise des entrepôts a marqué.
     *
     * C'est la seule dépendance dure du script : sans entrepôt, les emplacements ne se
     * rattacheraient à rien.
     *
     * @return int 1 si trouvé, -1 sinon
     */
    protected function loadWarehouse()
    {
        $sql  = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'entrepot';
        $sql .= ' WHERE entity IN ('.getEntity('stock').')';
        $sql .= " AND import_key = '".$this->db->escape('SAGE:DEPOT1')."'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = array('key' => '', 'message' => $this->db->lasterror());
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            $this->errors[] = array(
                'key'     => '',
                'message' => "Entrepôt principal introuvable (import_key = SAGE:DEPOT1)."
                    ." Lancez d'abord : migrate.php warehouse",
            );
            return -1;
        }

        $this->warehouseId  = (int) $obj->rowid;
        $this->warehouseRef = (string) $obj->ref;

        return 1;
    }

    /**
     * Charge l'index des codes déjà présents dans le dictionnaire.
     *
     * Toutes les entrées sont indexées, pas seulement celles de la reprise : un
     * emplacement saisi à la main porte le même code que celui qui viendrait de la source,
     * et le recréer violerait l'unicité. Il est adopté.
     *
     * @return int Nombre d'entrées chargées
     */
    protected function loadCodeIndex()
    {
        $this->codeIndex      = array();
        $this->existingBefore = 0;

        $sql  = 'SELECT rowid, code FROM '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' WHERE entity IN ('.getEntity(self::DICT_TABLE).')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // MySQL compare sans égard à la casse, PHP compare les clés à l'identique :
            // sans normalisation, « BOUTIQUE » et « Boutique » seraient deux entrées ici
            // et une seule en base, et l'insertion échouerait sur l'index unique.
            $this->codeIndex[$this->indexKey($obj->code)] = (int) $obj->rowid;
            $this->existingBefore++;
        }
        $this->db->free($resql);

        return $this->existingBefore;
    }

    /**
     * Clé d'indexation d'un code.
     *
     * Elle doit reproduire la comparaison que fera l'index unique, sans quoi le script
     * croirait créer une entrée neuve là où MySQL verra un doublon. La collation de la
     * base ignore la casse ET les accents : « STOCK F.F.A. A L ÉTAGE » et
     * « STOCK F.F.A. A L ETAGE » y sont le même code, la source contenant les deux
     * graphies. Retirer les accents en plus de la casse est donc indispensable.
     *
     * Seule la clé d'index est ainsi rabotée ; le code écrit en base garde ses accents.
     *
     * @param  string $code Code
     * @return string       Clé normalisée
     */
    protected function indexKey($code)
    {
        return self::locationKey($code);
    }

    /**
     * Intitulé assaini d'un emplacement.
     *
     * La source porte trois défauts qu'il faut traiter avant d'écrire quoi que ce soit :
     *
     * - **du HTML**. Trois emplacements ont pour intitulé « <input type= », vestige d'une
     *   saisie qui a mal tourné dans l'ERP d'origine — ce sont ceux qu'ADD affiche vides.
     *   Recopié tel quel, ce fragment ouvre un champ de saisie dans l'écran des
     *   dictionnaires, qui n'échappe pas ce qu'il affiche. `strip_tags()` et non
     *   `dol_string_nohtmltag()` : la seconde laisse passer une balise non refermée, ce
     *   qui est précisément le cas ici.
     *
     * - **des tabulations**, en tête comme au milieu — « 2V\tMIGNOTTE SYLVAIN ». Elles
     *   deviennent des espaces, et les espaces multiples se réduisent : sans quoi deux
     *   graphies du même endroit resteraient deux emplacements.
     *
     * - **un intitulé qui ne survit pas au nettoyage.** Le repli reprend alors le numéro
     *   d'origine, ce qui garde les emplacements distincts. Un libellé de repli commun les
     *   fusionnerait, et les articles rangés dans trois endroits différents se
     *   retrouveraient au même — c'est le piège que ce repli évite.
     *
     * @param  stdClass $row Ligne source
     * @return string        Intitulé exploitable, jamais vide
     */
    protected function cleanLabel($row)
    {
        // buildCode() et buildLabel() appellent tous deux cette méthode pour la même
        // ligne : sans mémorisation, les compteurs du rapport seraient doublés et le
        // dry-run annoncerait deux fois plus d'intitulés abîmés qu'il n'y en a.
        $no = (int) $row->DP_No;
        if (isset($this->cleanCache[$no])) {
            return $this->cleanCache[$no];
        }

        $clean = self::labelFor($row->DP_Intitule, $no);

        if ($clean !== self::sanitize($row->DP_Intitule)) {
            $this->unusable++;
            if (count($this->unusableSamples) < 10) {
                $this->unusableSamples[] = $no;
            }
        }

        // Un point d'interrogation dans un libellé d'emplacement n'est jamais une
        // question : c'est un accent qu'ADD n'a pas su écrire. La table n'en compte
        // qu'un seul correct sur mille sept, signe qu'elle a été saisie par une
        // interface qui ignorait l'UTF-8. On ne rétablit rien — « COMPL?MENT » se
        // devine, « S5-B3-?4 » non, et inventer une donnée est pire que la signaler.
        if (strpos($clean, '?') !== false) {
            $this->damaged++;
            if (count($this->damagedSamples) < 10) {
                $this->damagedSamples[$no] = $clean;
            }
        }

        $this->cleanCache[$no] = $clean;

        return $clean;
    }

    /**
     * Assainit un intitulé, sans repli.
     *
     * Statique et publique : la reprise qui range les produits doit appliquer exactement
     * le même traitement pour retrouver ses emplacements. Deux définitions séparées
     * finiraient par diverger, et les articles des intitulés abîmés resteraient sans
     * rangement — ce qui s'est produit avant que ce nettoyage soit mis en commun.
     *
     * @param  string $raw Intitulé brut
     * @return string      Intitulé assaini, éventuellement vide
     */
    public static function sanitize($raw)
    {
        $clean = strip_tags((string) $raw);
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);

        return trim((string) $clean);
    }

    /**
     * Intitulé définitif d'un emplacement, repli compris.
     *
     * @param  string $raw Intitulé brut
     * @param  int    $no  Numéro source, qui sert de repli
     * @return string      Intitulé exploitable, jamais vide
     */
    public static function labelFor($raw, $no)
    {
        $clean = self::sanitize($raw);

        if ($clean === '') {
            return 'Emplacement ADD n°'.((int) $no);
        }

        return $clean;
    }

    /**
     * Clé de rapprochement d'un intitulé ou d'un code.
     *
     * Statique pour la même raison que sanitize() : c'est la clé qui décide si deux
     * graphies désignent le même endroit, et elle doit être unique dans le module.
     *
     * @param  string $value Intitulé ou code
     * @return string        Clé normalisée
     */
    public static function locationKey($value)
    {
        return strtolower(dol_string_unaccent(trim((string) $value)));
    }

    /**
     * Code retenu pour un emplacement.
     *
     * L'intitulé fait au plus trente-cinq caractères dans la source et la colonne en
     * accepte soixante-quatre : la troncature, qui rapprocherait des emplacements
     * distincts, ne peut pas se produire.
     *
     * @param  stdClass $row Ligne source
     * @return string        Code
     */
    protected function buildCode($row)
    {
        return dol_strtoupper($this->cleanLabel($row));
    }

    /**
     * Libellé affiché.
     *
     * @param  stdClass $row Ligne source
     * @return string        Libellé
     */
    protected function buildLabel($row)
    {
        return $this->cleanLabel($row);
    }

    /**
     * Description, qui conserve la trace de l'origine.
     *
     * Le code d'ADD y figure quand il apporte quelque chose : il est le repère du
     * magasinier sur les étiquettes existantes. Les valeurs corrompues sont écartées.
     *
     * @param  stdClass $row Ligne source
     * @return string        Description
     */
    protected function buildDescription($row)
    {
        $parts = array('Emplacement ADD n°'.((int) $row->DP_No));

        $code = trim((string) $row->DP_Code);
        if ($code !== '' && strpos($code, '<') === false && $code !== trim((string) $row->DP_Intitule)) {
            $parts[] = 'code d\'origine : '.$code;
        }

        return implode(', ', $parts);
    }

    /**
     * L'index d'idempotence du socle ne s'applique pas : la cible n'a pas de ref_ext.
     *
     * @return int 0
     */
    protected function loadMigratedIndex()
    {
        $this->migratedIndex = array();
        return 0;
    }

    /**
     * Nombre d'emplacements déjà repris.
     *
     * @return int Nombre d'entrées portant le marqueur, -1 en cas d'erreur
     */
    public function countMigrated()
    {
        $sql  = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' WHERE entity IN ('.getEntity(self::DICT_TABLE).')';
        $sql .= " AND import_key = '".$this->db->escape(self::IMPORT_KEY)."'";

        $resql = $this->db->query($sql, 1);
        if (!$resql) {
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->nb;
    }

    /**
     * Vérifie qu'une ligne source est exploitable.
     *
     * @param  stdClass $row Ligne source
     * @return void
     * @throws Exception Si l'intitulé est vide
     */
    protected function validateRow($row)
    {
        if ($this->buildCode($row) === '') {
            throw new Exception('Intitulé vide');
        }
    }

    /**
     * Action que produirait cette ligne, en simulation.
     *
     * La fusion est simulée à l'identique : `$this->seen` est alimenté ici comme il le
     * serait à l'écriture, sans quoi le passage à blanc annoncerait 1 007 créations là où
     * la reprise n'en fera que 868.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Inutilisé, la cible n'ayant pas de ref_ext
     * @return string               created, adopted ou skipped
     */
    protected function previewAction($row, $existingId)
    {
        $key = $this->indexKey($this->buildCode($row));

        if (isset($this->seen[$key])) {
            $this->merged++;
            $this->noteMerge($this->buildLabel($row));
            return 'skipped';
        }
        $this->seen[$key] = 1;

        if (isset($this->codeIndex[$key])) {
            return 'adopted';
        }

        return 'created';
    }

    /**
     * Crée ou adopte l'entrée du dictionnaire.
     *
     * @param  stdClass $row        Ligne source
     * @param  int      $existingId Inutilisé
     * @return array{action:string,id:int}
     * @throws Exception En cas d'échec SQL
     */
    protected function migrateRow($row, $existingId)
    {
        $code  = $this->buildCode($row);
        $label = $this->buildLabel($row);
        $key   = $this->indexKey($code);

        // Deuxième ligne source portant le même intitulé : elle est absorbée par l'entrée
        // déjà écrite. C'est la fusion, et elle n'a rien d'une anomalie.
        if (isset($this->seen[$key])) {
            $this->merged++;
            $this->noteMerge($label);
            return array('action' => 'skipped', 'id' => $this->seen[$key]);
        }

        if (isset($this->codeIndex[$key])) {
            $id = $this->codeIndex[$key];
            $this->seen[$key] = $id;

            // Entrée déjà là : on ne complète que le rattachement s'il manque, et jamais
            // le libellé — le client a pu le retoucher, ce n'est pas à la reprise de
            // revenir dessus.
            $sql  = 'UPDATE '.MAIN_DB_PREFIX.self::DICT_TABLE;
            $sql .= ' SET fk_entrepot = '.((int) $this->warehouseId);
            $sql .= ' WHERE rowid = '.((int) $id);
            $sql .= ' AND (fk_entrepot IS NULL OR fk_entrepot = 0)';

            if (!$this->db->query($sql)) {
                throw new Exception('Rattachement impossible : '.$this->db->lasterror());
            }

            return array('action' => 'adopted', 'id' => $id);
        }

        $sql  = 'INSERT INTO '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' (entity, code, label, description, fk_entrepot, position, import_key, active)';
        $sql .= " VALUES (".((int) $this->getEntityForDict()).",";
        $sql .= " '".$this->db->escape($code)."',";
        $sql .= " '".$this->db->escape($label)."',";
        $sql .= " '".$this->db->escape($this->buildDescription($row))."',";
        $sql .= " ".((int) $this->warehouseId).",";
        $sql .= " 0,";
        $sql .= " '".$this->db->escape(self::IMPORT_KEY)."',";
        $sql .= " 1)";

        if (!$this->db->query($sql)) {
            throw new Exception('Création impossible : '.$this->db->lasterror());
        }

        $id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.self::DICT_TABLE);
        if ($id <= 0) {
            throw new Exception('Identifiant non récupéré après insertion');
        }

        $this->codeIndex[$key] = $id;
        $this->seen[$key]      = $id;

        return array('action' => 'created', 'id' => $id);
    }

    /**
     * Entité sous laquelle écrire les entrées du dictionnaire.
     *
     * @return int
     */
    protected function getEntityForDict()
    {
        global $conf;
        return (int) $conf->entity;
    }

    /**
     * Retient un exemple de fusion, pour le rapport.
     *
     * @param  string $label Intitulé concerné
     * @return void
     */
    protected function noteMerge($label)
    {
        if (!isset($this->mergeSamples[$label])) {
            $this->mergeSamples[$label] = 1;
        }
        $this->mergeSamples[$label]++;
    }

    /**
     * Description de la purge.
     *
     * @return string
     */
    public function getPurgeDescription()
    {
        $n = $this->countMigrated();

        $out  = "Supprime les emplacements du dictionnaire portant le marqueur "
            .self::IMPORT_KEY." (".($n < 0 ? '?' : $n).").\n";
        $out .= "Les emplacements saisis à la main ne sont pas touchés.\n\n";
        $out .= "Attention : les produits qui désignent un emplacement supprimé garderont\n";
        $out .= "dans leur extrafield un identifiant sans correspondance. Purgez l'affectation\n";
        $out .= "d'abord : purge.php productlocation";

        return $out;
    }

    /**
     * Supprime les entrées créées par la reprise.
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
            $result['errors'][] = $this->db->lasterror();
            return $result;
        }
        $result['count'] = $n;

        if (!$confirm || $n === 0) {
            return $result;
        }

        $sql  = 'DELETE FROM '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' WHERE entity IN ('.getEntity(self::DICT_TABLE).')';
        $sql .= " AND import_key = '".$this->db->escape(self::IMPORT_KEY)."'";

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

        $lines[] = 'Entrepôt de rattachement : '.$this->warehouseRef
            .' (#'.$this->warehouseId.')';

        if ($this->existingBefore > 0) {
            $lines[] = 'Dictionnaire avant le passage : '.$this->existingBefore.' emplacement(s)';
        }

        if ($this->merged > 0) {
            $lines[] = '';
            $lines[] = 'Fusion des doublons';
            $lines[] = '  '.$this->merged.' ligne(s) source absorbée(s) par un intitulé déjà repris.';
            $lines[] = '  La source répète le même endroit sous plusieurs numéros ; une seule';
            $lines[] = '  entrée est créée, et les produits des deux numéros la désigneront.';

            arsort($this->mergeSamples);
            $shown = 0;
            foreach ($this->mergeSamples as $label => $count) {
                $lines[] = '     '.str_pad($label, 32).$count.' occurrence(s)';
                if (++$shown >= 10) {
                    $remaining = count($this->mergeSamples) - $shown;
                    if ($remaining > 0) {
                        $lines[] = '     … et '.$remaining.' autre(s) intitulé(s)';
                    }
                    break;
                }
            }
        }

        if ($this->unusable > 0) {
            $lines[] = '';
            $lines[] = 'Intitulés inexploitables dans la source : '.$this->unusable;
            $lines[] = '  Leur intitulé ne contient que du HTML — « <input type= » — laissé par';
            $lines[] = '  une saisie ratée dans l\'ancien ERP, qui les affiche vides. Ils sont repris';
            $lines[] = '  sous leur numéro d\'origine, ce qui les garde distincts les uns des autres.';
            $lines[] = '  À faire corriger chez le client : ces emplacements sont abîmés dans ADD.';
            if (!empty($this->unusableSamples)) {
                $lines[] = '     n° '.implode(', ', $this->unusableSamples);
            }
        }

        if ($this->damaged > 0) {
            $lines[] = '';
            $lines[] = 'Accents perdus dans la source : '.$this->damaged;
            $lines[] = '  Un « ? » y remplace une lettre accentuée. La perte date de la saisie';
            $lines[] = '  dans ADD, l\'export la reproduit fidèlement : rien n\'est récupérable ici.';
            $lines[] = '  Corrigez le LIBELLÉ dans le dictionnaire — c\'est lui qui s\'affiche sur';
            $lines[] = '  les fiches produit. Laissez le code tel quel : il sert de repère à la';
            $lines[] = '  reprise, et le changer ferait recréer l\'emplacement au prochain passage.';
            foreach ($this->damagedSamples as $no => $label) {
                $lines[] = '     n°'.str_pad($no, 6).$label;
            }
        }

        $this->reportSimilar($lines);

        $total = $this->countMigrated();
        if ($total >= 0) {
            $lines[] = '';
            $lines[] = 'Dictionnaire : '.$total.' emplacement(s) issu(s) de la reprise';
        }

        return $lines;
    }

    /**
     * Signale les emplacements qui ne diffèrent que par leur ponctuation.
     *
     * La fusion opérée par la reprise ne rapproche que les intitulés identiques. Restent
     * des graphies concurrentes du même endroit — « STOCK SECURISE » et
     * « STOCK-SECURISE », « B BOUTIQUE », « B- BOUTIQUE » et « B-BOUTIQUE ».
     *
     * Elles ne sont pas fusionnées automatiquement, et c'est délibéré : ignorer tirets et
     * espaces confondrait « M1-A2-3 » avec « M1-A23 », ou « BC-A0-4 » avec « BC-A04 ».
     * Sur un code d'allée, rien ne dit si le second désigne le même rayonnage ou l'allée
     * 23 — seul le magasinier le sait. Une fusion à tort déplacerait des articles vers un
     * emplacement erroné sans laisser de trace, quand deux entrées en trop se corrigent
     * d'un clic dans le dictionnaire.
     *
     * Le rapport sépare donc les deux familles : ce qui se lit en clair et se tranche à
     * vue, et ce qui porte un code structuré et demande l'avis du client.
     *
     * @param  array $lines Lignes du rapport, complétées
     * @return void
     */
    protected function reportSimilar(array &$lines)
    {
        $sql  = 'SELECT rowid, code FROM '.MAIN_DB_PREFIX.self::DICT_TABLE;
        $sql .= ' WHERE entity IN ('.getEntity(self::DICT_TABLE).')';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return;
        }

        $groups = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower(dol_string_unaccent($obj->code)));
            if ($key === '') {
                continue;
            }
            $groups[$key][] = (string) $obj->code;
        }
        $this->db->free($resql);

        $plain = array();
        $coded = array();
        foreach ($groups as $key => $codes) {
            if (count($codes) < 2) {
                continue;
            }
            // Un code d'allée : quelques lettres, des chiffres, des séparateurs. C'est là
            // que la ponctuation porte du sens et que la fusion serait hasardeuse.
            $structured = false;
            foreach ($codes as $code) {
                if (preg_match('/^[A-Z]{1,3}[0-9]*[- ][A-Z]?[0-9]+([- ][0-9]+)?$/i', trim($code))) {
                    $structured = true;
                    break;
                }
            }
            if ($structured) {
                $coded[] = $codes;
            } else {
                $plain[] = $codes;
            }
        }

        if (empty($plain) && empty($coded)) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Emplacements qui se ressemblent : '.(count($plain) + count($coded)).' groupe(s)';
        $lines[] = '  Mêmes lettres et chiffres, ponctuation différente. Rien n\'est fusionné :';
        $lines[] = '  sur un code d\'allée, « M1-A2-3 » et « M1-A23 » ne désignent pas forcément';
        $lines[] = '  le même rayonnage. À trancher avec le client, puis à corriger dans le';
        $lines[] = '  dictionnaire — une fusion à tort égarerait des articles sans laisser de trace.';

        if (!empty($plain)) {
            $lines[] = '';
            $lines[] = '  Libellés en clair, sans ambiguïté ('.count($plain).') :';
            foreach (array_slice($plain, 0, 15) as $codes) {
                $lines[] = '     '.implode('  |  ', $codes);
            }
            if (count($plain) > 15) {
                $lines[] = '     … et '.(count($plain) - 15).' autre(s)';
            }
        }

        if (!empty($coded)) {
            $lines[] = '';
            $lines[] = '  Codes structurés, à faire confirmer ('.count($coded).') :';
            foreach (array_slice($coded, 0, 15) as $codes) {
                $lines[] = '     '.implode('  |  ', $codes);
            }
            if (count($coded) > 15) {
                $lines[] = '     … et '.(count($coded) - 15).' autre(s)';
            }
        }
    }
}
