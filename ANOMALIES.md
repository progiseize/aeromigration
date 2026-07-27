# Anomalies et incohérences de la source

Relevé des problèmes rencontrés dans les données de l'ancien ERP au fil de l'écriture des
scripts de reprise. Objectif : garder trace de ce qui a été constaté, de la décision prise
et de ce qui reste à arbitrer — pour ne pas refaire deux fois le même diagnostic.

À compléter au fur et à mesure. Volumes mesurés le 27/07/2026 sur la base importée.

---

## Récapitulatif

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| 1 | Ligne technique entièrement vide (ni code, ni libellé) | 1 | Écartée à la source |
| 2 | Tiers sans pays renseigné | 22 803 | Pays laissé vide |
| 3 | Libellés de pays non reconnus | 22 | Pays laissé vide, listés en fin de passage |
| 4 | Numéros de téléphone manifestement faux | ~210 | Repris tels quels |
| 5 | E-mails syntaxiquement invalides | 34 | Repris tels quels |
| 6 | E-mails contenant un espace | 80 | Repris tels quels |
| 7 | E-mails en doublon | 527 | Repris tels quels |
| 8 | Doublons de libellé côté clients | ~13 500 | Pas de fusion |
| 9 | Fournisseurs homonymes d'un client | 22 | Pas de fusion |
| 10 | Fournisseurs en compte général 411 au lieu de 401 | 28 | Sans effet sur la reprise |
| 11 | Préfixes téléphoniques incohérents (`33` et `+33`) | 37 | Normalisés |
| 12 | `cbMarq` à 0 sur toute la table `f_comptet` | 157 103 | Curseur basé sur `CT_Num` |
| 13 | `CT_CodeRegion` quasi jamais renseigné | 81 / 157 103 | Champ abandonné |
| 14 | Codes postaux avec espace parasite | 6 | Nettoyés (`trim`) |
| 15 | Noms de colonnes aux accents perdus | — | Sans effet, à connaître |
| 16 | `CT_Identifiant` mélange TVA et identifiants internes | 89 / 133 | Seuls les vrais n° de TVA repris |
| 17 | Identifiants légaux quasi jamais renseignés | 41 SIRET, 16 APE | Repris, mais apport marginal |
| 18 | SIRET non conformes | 4 | Repris, sans déduction du SIREN |
| 19 | `Capital_social` jamais alimenté | 0 / 157 102 | Champ abandonné |
| 20 | `CT_Encours` jamais alimenté | 0 / 157 102 | Champ abandonné |
| 21 | `N_CatTarif` hors bornes Dolibarr | 69 | Repris tel quel |
| 22 | `CT_Commentaire` quasi jamais renseigné | 26 | Repris |
| 23 | Comptes en sommeil | 88 | Tiers désactivés |
| 24 | Qualités non reconnues (intitulés de fonction) | 9 | Tiers sans type |
| 25 | Colonnes d'apparence remplie mais sans information | ~10 | Non reprises |
| 26 | `datec` non corrigeable sur les tiers déjà migrés | — | Reprise à zéro nécessaire |
| 27 | `Unsubscribe_Newsletter` sans champ cible sur le tiers | 2 621 | À traiter avec les contacts |
| 28 | `RE_No` : table des représentants absente de l'import | 2 071 | Non repris, récupérable |
| 29 | `N_Devise` : table des devises absente de l'import | 97 | Codes déduits des pays, repris |

---

## Détail

### 1. Ligne technique vide

Une ligne de `f_comptet` n'a ni `CT_Num` ni `CT_Intitule`. Elle est exclue par le filtre
`TRIM(CT_Num) <> ''` du script, sans quoi elle remonterait en erreur à chaque passage et
masquerait les anomalies réelles.

### 2. Tiers sans pays

22 803 tiers sur 157 102 n'ont pas de `CT_Pays`. **Décision : on laisse vide**, pas de
France par défaut.

### 3. Libellés de pays non reconnus

`CT_Pays` est saisi en texte libre : 166 valeurs distinctes, dont des variantes de forme
(`Grande-Bretagne`, `ANGLETERRE`, `Royaume uni`, `UK`, `ENGLAND`) et des libellés en
langue étrangère (`Tunisia`, `España`, `Nederland`, `Norge`). Le référentiel Dolibarr
(`llx_c_country`) stocke ses libellés en anglais.

Une table de correspondance couvre l'ensemble sauf 22 tiers :

| Libellé | Tiers | Motif |
|---|---:|---|
| `Antilles françaises` | 18 | Ambigu : Guadeloupe ou Martinique |
| `ARMEES` | 2 | Hors référentiel |
| `SP 55047` | 1 | Valeur aberrante |
| `CÃTE D` | 1 | Libellé corrompu dans la source |

**Décision : pays laissé vide.** Ces libellés restent listés en fin de chaque passage.

### 4. Numéros de téléphone faux

| Numéro | Occurrences |
|---|---:|
| `555-666-0606` | 165 |
| `0` | 18 |
| `0000000000` | 16 |
| `05 31 22 43 53` | 15 |
| `0606060606` | 7 |

**Décision : repris tels quels**, la source fait foi.

À noter : `05 31 22 43 53` est un numéro valide mais partagé par 15 tiers — probablement
un standard, pas une anomalie.

### 5 à 7. E-mails

`CT_EMail` est le champ le mieux rempli de la table (156 375 tiers, 99,5 %), mais :

- **34 sont syntaxiquement invalides** : pas d'arobase, ou domaine sans point
  (`corinne_dm@yahoofr`, `alain.buret@wanadoofr`, `dhdhd@gjhk`, `109864`)
- **80 contiennent un espace**
- **527 sont en doublon** (156 375 valeurs pour 155 848 distinctes)
- l'un d'eux contient `${@print(md5(31337))}`, un payload de test d'injection PHP saisi
  dans le champ. Sans danger : Dolibarr échappe les valeurs et n'évalue rien.

**Décision : repris tels quels.**

### 8 et 9. Doublons de tiers

Côté clients : **156 711 comptes pour 143 171 libellés distincts**, soit environ 13 500
doublons de libellé. Les plus fréquents : `anonymized client` (102), `particulier` (44),
`omrylmsv omrylmsv omrylmsv` (39), `airbus` (35), `testing` (34), `monsieur` (25).

Par ailleurs, 22 fournisseurs portent le même nom qu'un client (69 comptes clients
concernés) : `Air France` existe comme fournisseur `F187` et comme une quinzaine de
comptes clients distincts.

**Décision : pas de fusion.** Un compte source donne un tiers Dolibarr, et un seul.
L'homonymie ne prouve pas l'identité dans une source aussi bruitée. Le dédoublonnage
relève du métier et se traitera après coup avec l'outil de fusion de tiers de Dolibarr.

### 10. Fournisseurs en compte général client

28 fournisseurs (`CT_Type = 1`) portent un compte général `411…` (compte client) au lieu
de `401…`. Il s'agit de doublons internes de la source (`OPO 10 SARL` ×4,
`Florent Peraudeau` ×4). Sans effet sur la reprise : le script s'appuie sur `CT_Type`, dont
la fiabilité est confirmée par la codification (tout fournisseur a un `CT_Num` en `F…`,
aucun client n'en porte).

### 11. Préfixes téléphoniques

`prefixe_telephone` n'est renseigné que sur 23 lignes, `prefixe_portable` sur 14,
`prefixe_telecopie` sur aucune. Les valeurs mélangent les formes `33` et `+33`.

**Décision : normalisés** en notation internationale (`+33 671834495`), le zéro national
de tête étant retiré.

### 12. Colonne `cbMarq` inutilisable

`cbMarq` vaut 0 sur les 157 103 lignes de `f_comptet` : la colonne n'y est pas
auto-incrémentée, contrairement à d'autres tables de la source. Elle ne peut donc pas
servir de curseur de pagination. Le script parcourt la table sur `CT_Num`, sa clé
primaire.

**Vérifier ce point table par table** avant d'écrire un nouveau script de reprise.

### 13. `CT_CodeRegion`

Renseigné sur 81 lignes seulement, sans correspondance avec le référentiel Dolibarr.
**Champ abandonné.** Le département est déduit du code postal pour les adresses
françaises.

### 14. Codes postaux avec espace

6 codes postaux comportent un espace de tête ou de fin (` 1050`). Nettoyés par `trim()`.

À signaler : 61 tiers ont une ville sans code postal, et 173 une ville sans adresse.

### 15. Noms de colonnes aux accents perdus

Plusieurs colonnes de `f_comptet` ont perdu leurs caractères accentués à la création de
la table : `Points_fidlit_utiliss`, `Total_points_fidlit`, `Points_fidlit_restants`,
`Fin_validit_carte_fidlit`, `Date_cration_socit`. Sans conséquence, mais il faut écrire
ces noms tels quels dans les requêtes.

### 16 à 19. Identifiants légaux

Les champs légaux de la source sont presque vides — sur 157 102 tiers :

| Champ | Renseignés |
|---|---:|
| `CT_Identifiant` | 133 |
| `CT_Siret` | 41 |
| `CT_Ape` | 16 |
| `Capital_social` | **0** |

**`CT_Identifiant` mélange deux natures de données.** Seules 44 valeurs sont de vrais
numéros de TVA intracommunautaire (`FR40180089013`, `BE0425.648.074`, `PT509269338`) ;
les 89 autres sont des identifiants internes numériques (`171`, `550`, `4048`, `13250`).
**Décision :** seules les valeurs commençant par un code pays sur deux lettres alimentent
`tva_intra`. Verser les autres y créerait des numéros de TVA inexistants.

Les séparateurs sont panachés d'une ligne à l'autre (`BE0425.648.074`,
`BE 0 430 246 468`, `FR 11 702 044 710`) : ils sont supprimés à la reprise.

**SIRET :** 37 valeurs sur 41 sont bien à 14 chiffres. Les 4 restantes sont non conformes
(longueur ou caractères) : elles sont reprises telles quelles dans `siret`, mais aucun
SIREN n'en est déduit. Pour les SIRET valides, le SIREN est déduit des 9 premiers
chiffres et alimente `siren`.

**Code APE :** formats mixtes (`5811Z` mais aussi `63.12Z`, `46.49Z`), plus une valeur
manifestement fausse (`WAPIAAA`) et une hors nomenclature (`1812ZA`). Le point est retiré
pour aligner sur la forme attendue ; les valeurs restent reprises telles quelles pour le
reste.

**`Capital_social`** n'est alimenté sur aucune ligne : champ non repris.

### 20 à 23. Statut et gestion commerciale

**`CT_Encours`** vaut 0 sur la totalité des lignes, comme `Capital_social` : rien à
reprendre dans `outstanding_limit`.

**`CT_Sommeil`** marque 88 comptes mis en sommeil. La logique est inversée par rapport à
Dolibarr (`status = 1` y signifie « en activité ») : ces 88 tiers sont donc créés
désactivés.

**`N_CatTarif`** se répartit ainsi : `2` → 145 966, `1` → 10 493, `3` → 522, `0` → 52,
et `4` à `8` → 69. Deux réserves :

- le module multiprix est désactivé (`PRODUIT_MULTIPRICES = 0`), donc `price_level` n'a
  aujourd'hui aucun effet fonctionnel ;
- Dolibarr est configuré pour 5 niveaux (`PRODUIT_MULTIPRICES_LIMIT`), alors que la
  source monte à 8 : **69 tiers porteront un niveau hors bornes**, à retraiter si le
  module est activé un jour.

**Décision : repris tel quel**, pour conserver l'information.

Point technique : `price_level` n'est écrit ni par `create()` ni par `update()`. Il faut
passer par `setPriceLevel()`, qui journalise chaque changement dans
`llx_societe_prices` — la reprise ne l'appelle donc que si le niveau change réellement.

**`CT_Commentaire`** n'est renseigné que sur 26 lignes ; repris dans `note_private`.

### 24. Qualité et type de tiers

`CT_Qualite` (155 721 lignes) sert de civilité aux particuliers et de nature juridique aux
personnes morales. C'est le seul champ permettant de typer les tiers, et il révèle que
**94 % d'entre eux sont des particuliers**.

| `CT_Qualite` | Volume | Type Dolibarr |
|---|---:|---|
| Monsieur, M, Mme, Madame, Mademoiselle, Mr. | 147 932 | `TE_PRIVATE` |
| Société, Entreprise | 4 784 | `TE_SOCIETE` * |
| Aéro-club, Aéroclub | 1 544 | `TE_AEROCLUB` * |
| Administration | 749 | `TE_ADMIN` |
| Association | 598 | `TE_OTHER` |
| Etablissement | 105 | `TE_ETAB` * |

\* Codes absents du socle Dolibarr, ajoutés au dictionnaire à l'activation du module
(voir `modAeroMigration::createTypentEntries`). Leurs identifiants sont attribués
au-delà de 200000 pour ne jamais entrer en conflit avec ceux d'une future version de
Dolibarr. L'opération est idempotente et ces entrées ne sont pas supprimées à la
désactivation, des tiers y faisant référence.

**9 valeurs ne sont pas décidables** (1 occurrence chacune) : ce sont des intitulés de
fonction saisis dans le champ qualité — `Head of Training`, `Chef Unité Météo`,
`Directeur des étu`, `Laboratoire compo`, `Président`, `Direction des Ach`,
`Technicienne Amén`, `EATIS`, `Aér`. Ces tiers restent sans type et sont signalés en fin
de passage.

### 25. Colonnes trompeuses

Plusieurs colonnes paraissent massivement remplies mais ne portent aucune information
exploitable. Vérifié avant d'écarter :

| Colonne | Remplissage apparent | Réalité |
|---|---:|---|
| `CG_NumPrinc` | 157 102 | `41100000` pour tous les clients, `40100000` pour tous les fournisseurs |
| `N_Condition` | 156 992 | vaut `1` partout |
| `date_naissance` | 157 102 | **136 vraies dates**, le reste à `0000-00-00` |
| `CT_NumPayeur` | 155 961 | code calculé, sans contrepartie en base — voir ci-dessous |
| `id_externe` | 155 562 | recopie de `CT_Num`, déjà couvert par `ref_ext` |
| `indexation`, `CT_Classement`, `CT_Lettrage`, `CT_Saut`, `CT_Facture`, `N_Period`, `CT_BLFact` | ~157 000 | champs techniques internes à Sage |

**Le cas `CT_NumPayeur`** mérite un mot, car il ressemble à un lien vers un autre tiers
sans en être un. Ses 155 961 valeurs se décomposent ainsi :

| Motif | Volume |
|---|---:|
| `CT_NumPayeur` = `CT_Num` (le tiers est son propre payeur) | 44 688 |
| `CT_NumPayeur` = `99` + `CT_Num` | 110 927 |
| `WEB` + numéro sur 6 chiffres (fournisseurs : `F128` → `WEB000128`) | 346 |

Sur les 110 927 comptes en `99…`, **809 seulement existent réellement** dans
`f_comptet` : c'est un code de compte payeur calculé par Sage, sans contrepartie en base.
Rien à en tirer.

Ce champ n'a par ailleurs **aucun rapport avec `f_contactt`**, malgré une correspondance
apparente sur 45 369 valeurs : `f_contactt.CT_Num` est la clé étrangère vers le *tiers*,
pas un identifiant de contact, et beaucoup de tiers portent un `CT_Num` commençant par
`99` — d'où la collision de forme.

Reste un cas non traité : **`Unsubscribe_Newsletter`** (2 621 désinscrits). Donnée RGPD
qu'il serait dommage de perdre, mais `llx_societe` n'a pas de champ `no_email` — Dolibarr
gère la désinscription au niveau des contacts et des listes de diffusion. **À traiter
avec le script contacts.**

### 28 et 29. Données présentes mais indécodables

Deux colonnes portent une information réelle qu'on ne peut pas exploiter, faute de la
table de référence correspondante dans le périmètre importé :

- **`RE_No`** (2 071 tiers, 49 représentants distincts, le n° 1 en couvrant 1 325) est le
  commercial affecté au tiers. La table des représentants Sage n'a pas été importée : on
  dispose des numéros, pas des noms. **Récupérable** en réimportant cette table ; `RE_No`
  alimenterait alors les commerciaux de Dolibarr (`llx_societe_commerciaux`, via
  `Societe::add_commercial()`). C'est la reprise la plus intéressante des trois restantes.

- **`N_Devise`** (97 tiers) contient des codes internes Sage sans table de
  correspondance. **Résolu autrement** : les codes ont été déduits des pays des tiers
  concernés, la répartition ne laissant pas de place au doute.

  | `N_Devise` | Tiers | Pays rencontrés | Code ISO |
  |---:|---:|---|---|
  | 1 | 37 | Allemagne, Autriche, Belgique, France, Italie, Luxembourg, Pologne, Portugal, Suisse | `EUR` |
  | 2 | 43 | Australia, Canada, China, Chine, Etats-Unis, Hong-Kong | `USD` |
  | 4 | 16 | ANGLETERRE, Grande-Bretagne, Royaume uni, Royaume-Uni | `GBP` |
  | 11 | 1 | Canada | `CAD` |

  `1` est la devise de tenue de comptabilité — la présence de la France le confirme —,
  donc ces 37 tiers n'ont en réalité aucune devise étrangère.

  **Piège Dolibarr :** `Societe::create()` vide `multicurrency_code` sans rien signaler
  lorsque le code n'est pas déclaré dans `llx_multicurrency` (societe.class.php:1035). Le
  script vérifie donc les devises déclarées au démarrage et signale en fin de passage
  celles qu'il n'a pas pu reprendre, plutôt que de laisser l'information disparaître.

  Il suffit de déclarer la devise dans `Configuration > Multidevise` (avec son taux de
  change) puis de rejouer la reprise en `--update` : le mapping les attend.

### 30. Table `f_comptet_crm`

Table annexe de paramétrage CRM : 377 lignes au format clé/valeur (`CT_Num`, `id_param`,
`valeur_param`) ne concernant que **13 tiers**, avec des booléens (`false` 365 fois,
`true` 12 fois). Sans intérêt pour la reprise.

`f_comptet_prepa_import` est quant à elle entièrement vide.

### 26. Date de création et tiers déjà migrés

`CT_DateCreate` est renseigné sur 100 % des lignes (du 01/01/2000 au 26/06/2026) et la
date d'origine est bien reprise : Dolibarr ne la remplace par la date du jour que si elle
est vide.

**Limite à connaître :** `update()` ne touche jamais `datec`. Les tiers créés avant
l'ajout de ce mapping conservent donc la date de leur migration, et un passage en
`--update` ne les corrigera pas. Seule une reprise à zéro (`purge.php` puis
`migrate.php`) rétablit les dates d'origine.

À noter également : une part importante des tiers porte la date `2000-01-01 00:00:00`,
qui correspond visiblement à une valeur par défaut de l'ancien ERP plutôt qu'à une date
de création réelle.
