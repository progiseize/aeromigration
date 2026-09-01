# Anomalies et incohérences de la source

Relevé des problèmes rencontrés dans les données de l'ancien ERP au fil de l'écriture des
scripts de reprise. Objectif : garder trace de ce qui a été constaté, de la décision prise
et de ce qui reste à arbitrer — pour ne pas refaire deux fois le même diagnostic.

À compléter au fur et à mesure. Volumes mesurés le 27/07/2026 sur la base importée.

---

## Catalogue (`f_catalogue`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| K1 | Libellés en double sous un même parent | 7 | Fusionnés |
| K2 | Hiérarchie non ordonnée par le parcours | 14 | Parents créés à la demande |
| K3 | Racines du catalogue vs arborescence boutique | 60 | Greffées sous « Accueil » |

`f_catalogue` porte le classement commercial de l'ancien ERP : **504 rubriques sur quatre
niveaux** (61 racines, 175, 254, 14). C'est lui que les articles référencent par `CL_No1`,
`CL_No2` et `CL_No3` — à ne pas confondre avec `FA_CodeFamille`, simple regroupement de
TVA.

### K1. Doublons de libellé

Sept rubriques portent le même nom qu'une autre sous le même parent : `Tintin`,
`Girouettes`, `Cartes aéronautiques 2025`, `Accessoires APcom`, `Cartes postales Benjamin
FREUDENTHA`, `Cartes d'aérodromes et d'approches`, et un septième qui ne diffère que par
la casse et ses points de suspension — `CD-Rom (formation, logiciels...)` contre
`CD-ROM (formation, logiciels…)`.

Dolibarr impose l'unicité du couple (libellé, parent). **Décision : la catégorie existante
est réutilisée**, les deux entrées désignant la même rubrique ; les articles des deux s'y
retrouvent.

Un cas résiduel : `Tintin` figure deux fois **à la racine**. L'une ayant déjà été créée,
le déplacement de l'autre sous « Accueil » est abandonné et signalé — elle reste où elle
est plutôt que de faire échouer le passage.

### K2. Une hiérarchie à créer dans l'ordre

Le parcours suit `CL_No`, pas la profondeur : une rubrique de niveau 2 se présente parfois
avant son parent. Celui-ci est alors **créé à la demande, récursivement**, avec un
garde-fou contre les cycles. Quatorze rubriques ont ainsi été créées avant leur tour.

### K3. Deux racines qui ne coïncident pas

La boutique impose son arborescence — `Boutique Aero : Racine` puis `Accueil` — sous
laquelle vivent ses catégories. Or `f_catalogue` déclare 60 rubriques à la racine
(`CL_NoParent = 0`).

**Décision : les rubriques racines sont greffées sous « Accueil »**, pour rejoindre
l'arbre existant au lieu de vivre à côté.

**La catégorie est retrouvée par son libellé**, « Accueil », identique sur toutes les
instances — contrairement à son identifiant, qui vaut 2 chez le client et 504 sur la base
de développement. Aucune configuration préalable n'est donc nécessaire.

Trois cas de figure :

| Situation | Comportement |
|---|---|
| Une seule « Accueil » | retenue automatiquement |
| Plusieurs homonymes | **arrêt immédiat**, avant tout traitement |
| Aucune | les rubriques racines le restent, sans blocage |

L'ambiguïté ne peut pas se trancher toute seule : le script s'arrête et invite à supprimer
ou renommer les doublons. Le libellé retenu est affiché en fin de passage
(`Rubriques racines greffées sous : « Accueil » (rowid 504)`), ce qui rend toute erreur
immédiatement visible.

**Ne jamais modifier le parent d'une catégorie adoptée** : la hiérarchie de la boutique
fait autorité. Le script ne pose le rattachement que s'il est vide.

---

## Articles (`f_article`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| A1 | Origines mêlées : boutique et ancien ERP | 15 811 | Adoption ou création selon le cas |
| A2 | Champs libres doublement encodés en UTF-8 | 321 | Décodés à la reprise |
| A3 | Colonnes `disponibilite_origine` / `suivi_origine` incohérentes | 828 | Écartées |
| A4 | Taux de TVA absent de la table | 828 | Déduit de la famille |
| A5 | Non-renseignés déguisés en « #N/A » et « undefined » | ~20 100 | Écartés |
| A6 | Références orphelines dans les tables liées | 618 | Écartées et signalées |
| A9 | Déclinaisons créées en articles indépendants | 371 groupes | Constaté, hors périmètre |
| A7 | Prix de revient périmé, inférieur au prix d'achat | 2 767 | Coût standard retenu à sa place |
| A8 | `f_consigne` : stock en dépôt-vente, pas des consignes | 279 | À traiter avec les stocks |
| A10 | Articles achetés plus cher qu'ils ne sont vendus | 54 | Signalés, non traités |

### A1. Trois traitements selon l'origine de l'article

`f_article` contient les 15 811 articles de l'ancien ERP, mais tous ne relèvent pas du
même traitement :

| Cas | Traitement |
|---|---|
| `ref_ext` déjà posé | déjà repris — ignoré, ou mis à jour avec `--update` |
| `id_externe` connu de `llx_prestasync_product` | **adoption** — champs vides complétés |
| Référence déjà portée par un produit | **adoption** — garde-fou contre les doublons |
| Aucun des trois | **création**, puis déclaration à la boutique |

Le troisième cas n'est pas théorique : lorsqu'un lien boutique manque, l'article serait
recréé alors que le produit existe. Sans ce rapprochement de secours, un passage sur la
source complète avec une table de liaison incomplète crée près de 15 000 doublons — ce qui
est arrivé une fois en développement.

Un fichier `prod.csv` a servi un temps à délimiter un premier périmètre de 828 références.
Il n'est **plus utilisé par le script** depuis que le rapprochement couvre tous les cas, et
a été retiré du module.

**L'adoption ne remplace rien** : référence, libellé, prix, statut et type du produit
restent ceux de la boutique. La reprise n'apporte que ce qui manque — poids, code-barres,
coût de revient, code douanier, auteur, éditeur, garantie, disponibilité, suivi,
catégories —, ce que la synchronisation ne renseigne pas.

Une sauvegarde de la table reste disponible dans `f_article_backup` :

```sql
TRUNCATE f_article;
INSERT INTO f_article SELECT * FROM f_article_backup;
```

**Piège d'environnement.** Importer `llx_prestasync_product` depuis la production sur une
base de développement produit de faux rapprochements : ses `fk_product_doli` désignent des
`rowid` de production, qui correspondent localement à d'autres produits. Constaté ici — le
script s'apprêtait à compléter « Heure de maintenance atelier » avec les données de
« Casque AC-200 ». Vider la table et reconstituer un scénario cohérent avant de tester.

### A2. Champs libres doublement encodés

`PA_ChampLibre_Intitule_2` stocke `ArrÃªt Ã  Ã©puisement du stock` au lieu de
`Arrêt à épuisement du stock` : l'UTF-8 a été encodé une seconde fois. Concernés : 306
valeurs sur le champ 2, 13 sur le champ 3, 2 sur le champ 4. `AR_Design` et le champ 1 en
sont **indemnes**, tout comme `f_comptet`.

Sans correction, le rapprochement avec les dictionnaires échoue : les 306 suivis
ressortaient en « non reconnus ».

La détection ne repose pas sur un motif — une expression régulière sur ces octets s'est
révélée peu fiable — mais sur la conversion elle-même : un texte doublement encodé
redevient de l'UTF-8 valide une fois reconverti en latin-1, un texte sain non. Validé sur
les deux formes, accentuées et ASCII.

### A3. Disponibilité et suivi : se fier aux champs libres

> ⚠️ **Démenti le 18/08/2026 — cette conclusion était fausse, correctif à venir.** La
> comparaison de deux exports à trois semaines d'écart (31/07 et 18/08) montre que les champs
> libres sont un **cliché figé** : leurs distributions sont identiques au produit près entre
> les deux (5 493 DISPONIBLE, 2 801 ARRETEE, 1 853 « Arrêt »… mêmes comptes exacts), alors que
> le catalogue vit — le double encodage (A2) et les `#N/A` (A5) signaient déjà un import
> tableur ancien. Le statut vivant est bien dans `disponibilite_origine` / `suivi_origine`
> (avec `disponibilite_rupture` / `suivi_rupture`), pilotés par la table de couples
> `z_disponibilite` — le modèle même qu'aerotoolbox a repris. Les ids ADD 1 à 7 sont
> identiques à nos rowids de dictionnaire (vérifié : 6→6 sur 2 731 produits, 7→7 sur 107…),
> **ADD 8 = notre 10** (En cours de référencement, Prestation et Composant ayant été insérés
> avant chez nous). Réserve : 7 846 articles ont ces colonnes à zéro — jamais qualifiés par la
> surcouche ; leur cas est à trancher avec le client. L'« incohérence » relevée ci-dessous
> (ARRETEE en 0 ou en 6) était l'écart entre le cliché et le vivant, lu à l'envers.

Les colonnes `disponibilite_origine` et `suivi_origine` semblent prévues pour cet usage,
mais **ne sont pas cohérentes avec les libellés** : le même `COMMERCIALISATION_ARRETEE`
y apparaît tantôt en `0` (304 fois), tantôt en `6` (13 fois). Ce sont des champs de travail
du connecteur boutique.

La donnée métier est dans les champs libres, et correspond exactement aux dictionnaires du
module `aerotoolbox` :

- `PA_ChampLibre_Intitule_1` → `c_aerotoolbox_availability` (extrafield `aerotb_availability`)
- `PA_ChampLibre_Intitule_2` → `c_aerotoolbox_tracking` (extrafield `aerotb_tracking`)

347 articles sur 828 les renseignent. La reprise dépend donc du module `aerotoolbox` et
s'interrompt avec un message explicite si ses dictionnaires sont absents.

### A4. Taux de TVA déduit de la famille

`f_article` ne porte aucun taux : il découle de `FA_CodeFamille`, dont le référentiel Sage
n'a pas été importé. Correspondance arrêtée avec le client :

| Famille | Articles | Taux |
|---|---:|---:|
| `V20` | 733 | 20 % |
| `V5` | 85 | 5,5 % |
| `AREAFFECTER` | 9 | 20 % |
| `DIVERS` | 1 | 20 % |

L'hypothèse est confirmée par les données : une carte aéronautique en V5 ressort à
`11,85 HT + 5,5 % = 12,50 TTC`, soit un prix TTC rond — cohérent avec un tarif saisi TTC au
taux du livre.

`AR_PrixTTC` indique si le prix a été saisi toutes taxes comprises (437 articles) ; le
montant complémentaire est recalculé.

### A5. Des valeurs vides déguisées

La source ne laisse pas ses champs vides : elle y écrit des marqueurs venus de tableurs ou
de scripts, qu'il faut écarter comme s'ils étaient nuls.

| Champ | Marqueur | Occurrences | Valeurs réelles |
|---|---|---:|---:|
| `PA_ChampLibre_Intitule_4` (auteur) | `#N/A` | 8 793 | 2 923 |
| `PA_ChampLibre_Intitule_3` (éditeur) | `#N/A` | ~8 400 | 3 270 |
| `lib_fiscal` (code douanier) | `undefined` | 5 655 | 4 830 |

Le filtrage est centralisé dans `cleanFreeField()`, qui corrige aussi le double encodage
au passage.

**Ce que la source porte réellement**, et où cela aboutit :

| Source | Volume | Cible |
|---|---:|---|
| `lib_fiscal` | 4 830 | `customcode` — nomenclature douanière, champ natif |
| `PA_ChampLibre_Intitule_4` | 2 923 | extrafield `aerotb_auteur` |
| `PA_ChampLibre_Intitule_3` | 3 270 | extrafield `aerotb_editeur` |

Les codes douaniers sont pour l'essentiel en NC8 (huit chiffres, `49011000` pour les
livres) ; une centaine sont en TARIC, plus long. Tous sont repris tels quels.

L'auteur et l'éditeur étaient d'abord versés dans la note privée du produit ; ils ont
depuis leurs champs propres, ce qui les rend filtrables en liste. Les 625 notes posées
entre-temps ont été nettoyées.

### A7. Trois notions de coût pour un seul champ

La source distingue le prix d'achat brut (`AR_PrixAch`), le prix de revient unitaire
(`AR_PrixRU`) et le coût standard (`AR_CoutStd`). Dolibarr n'a qu'un `cost_price`.

Les trois diffèrent réellement : le prix de revient s'écarte du prix d'achat dans 9 236
cas, et du coût standard dans 4 866.

**Décision : c'est le COÛT STANDARD qui alimente `cost_price`**, le prix de revient ne
servant que de repli. C'est contre-intuitif — son nom promet mieux — mais la source est
formelle : **`AR_PrixRU` n'est jamais recalculé quand le tarif fournisseur change.**

```
10514  Maquette A350-1000       revient 35,26   coût standard 75,92   achat F261 75,92
```

Sur les 13 814 articles ayant un fournisseur principal tarifé, **2 767 (20 %) portent un
prix de revient inférieur à leur propre prix d'achat**. Le coût standard, lui, suit : il
égale exactement le prix d'achat sur 11 836 articles (86 %).

Trois mesures départagent les deux colonnes.

**Propreté.** Le coût standard porte **14 906 valeurs exploitables contre 13 152**, et
**aucune sous le centime contre 1 628** — jusqu'à `0,000001 €` pour un article à `4,19 €`.

**Cohérence avec le prix payé.** Sur les 13 814 articles ayant un fournisseur principal
tarifé, le coût descend sous le prix d'achat net **1 097 fois pour le coût standard, 1 379
fois pour le prix de revient**. Et l'écart y est autrement plus violent : le prix de revient
tombe régulièrement à la moitié exacte du prix payé, motif qu'on ne rencontre pas sur le coût
standard.

```
    51                          revient 141,35   coût standard 295,00   achat 295,00
  9309                          revient 132,00   coût standard 264,01   achat 264,01
  8598                          revient 128,57   coût standard 259,14   achat 259,14
```

**Prise en compte de la remise.** C'est la mesure la plus parlante, et la seule qui se
contrôle à l'écran de l'ancien ERP. Sur les 53 articles dont la remise fournisseur est connue
(voir F7), le coût standard reproduit le prix d'achat **net** 15 fois, le prix de revient 11
fois. L'article 13566 en donne l'illustration exacte :

```
13566  Thomas Pesquet     achat brut 39,00   remise 30 %   net 27,30
                           coût standard 27,30   ← le net, au centime
```

> Une mise en garde sur un argument qu'il ne faut pas reprendre : mesuré sur les 2 767
> articles au prix de revient périmé, le coefficient prix de vente / coût donnait
> « 14 489 208 » pour `AR_PrixRU` contre 1,84 pour le prix d'achat. Le chiffre est exact mais
> ne prouve rien — il n'est que l'effet des divisions par les 1 628 valeurs microscopiques.
> Hors ces valeurs, les deux colonnes donnent des coefficients moyens comparables, **1,93
> pour le coût standard et 2,00 pour le prix de revient**, et des marges négatives en nombre
> voisin (197 contre 207). La décision repose sur les trois mesures ci-dessus, pas sur
> celle-là.

Le prix de revient sert de repli pour les **14 articles** sans coût standard exploitable.
**837 articles** n'ont de valeur dans aucune des deux colonnes et restent sans coût.

**Conformité de la reprise, contrôlée sur les 15 811 produits :**

| `llx_product.cost_price` | Produits |
|---|---:|
| égal à `AR_CoutStd` | 14 906 |
| égal à `AR_PrixRU` (le repli) | 14 |
| **sans origine identifiable** | **0** |

**Ce qui n'est pas corrigé** : 1 318 articles gardent un coût standard lui aussi inférieur
à leur prix d'achat. Forcer la valeur au prix d'achat aurait été possible, mais c'eût été
inventer une donnée que la source ne porte pas. Ils sont laissés tels quels — sur les 600
produits de développement, cela représente 67 fiches dont la marge affichée reste optimiste.

> **Correction d'une décision antérieure.** La règle initiale privilégiait le prix de
> revient et ne basculait sur le coût standard qu'en dessous du centime. Elle laissait donc
> passer tous les prix de revient périmés mais plausibles — comme les 35,26 € de l'article
> 10514. La règle est exactement inversée depuis.

### A10. Des articles achetés plus cher qu'ils ne sont vendus

Le prix d'achat du fournisseur principal dépasse le prix de vente HT sur **54 articles**.

```
#10177  achat 134,17 chez F23    vente 97,50
#10395  achat   7,00 chez F87    vente  3,79
#10463  achat   7,10 chez F20    vente  4,73
```

Rien ne permet de trancher : c'est peut-être un tarif fournisseur périmé à la hausse, un
prix de vente jamais réajusté, ou une vente à perte assumée sur du déstockage. La reprise
n'y touche donc pas — elle les liste nommément dans le rapport de `supplierprice`, et la
marge apparaîtra négative dans Dolibarr, ce qui a le mérite de les rendre visibles.

**À faire vérifier par le client, article par article.**

### A8. `f_consigne` : du stock en dépôt-vente, pas des consignes

Le nom prête à confusion : il ne s'agit pas d'instructions mais de **consignation
commerciale** — de la marchandise déposée chez un tiers qui n'en est pas propriétaire.

La structure est celle de `f_artstock` — mêmes colonnes `AS_QteSto`, `AS_QteRes`,
`AS_QteCom`, `AS_QteMini`/`Maxi` — avec **`CT_Num` en plus** : le stock est rattaché à un
client, non à un dépôt.

```
AR_Ref 9906, CT_Num 27813     →  -8 en stock
AR_Ref 9906, CT_Num 99113773  →  46 en stock, -36 réservés
AR_Ref 9906, CT_Num 99116852  →   2 en stock,  -1 réservé
```

279 lignes. Aucun champ texte : les extrafields `aerotb_prep_notes`, `aerotb_pack_notes`
et `aerotb_sale_notes` **n'ont pas de source** dans les données reprises.

**À traiter avec la reprise des stocks**, pas avec les produits. Deux questions s'y
poseront :

- Dolibarr gère des entrepôts, pas du stock déposé chez un client. Il faudra soit créer un
  entrepôt par dépositaire, soit écarter ces lignes.
- Les quantités négatives (`-8`, `-36`) suggèrent des mouvements plutôt que des états :
  à vérifier avant toute reprise.

### A6. Références orphelines dans les tables liées

Relevé refait le 28/07/2026, sur `f_article` complète (15 812 articles) :

| Table | Lignes | Orphelines de `f_article` |
|---|---:|---:|
| `f_docligne_global` | 1 039 279 | 584 |
| `f_artstock` | 27 670 | 29 |
| `f_artfourniss` | 15 962 | 5 |

Ces lignes-là sont de vraies anomalies, définitives : rien ne les résorbera, aucun article ne
porte plus ces références. Elles sont écartées et signalées nommément par les rapports de
reprise.

> **Correction d'un relevé antérieur.** Ce tableau annonçait des taux de 82 à 97 %. Ils ne
> mesuraient pas des orphelines de `f_article` mais des lignes sans produit **migré** à un
> instant donné, alors que `f_article` avait été temporairement réduite à 828 articles et que
> 417 produits seulement existaient en cible. Deux choses distinctes, que la formulation
> confondait. Le nombre de lignes sans produit repris reste évidemment élevé tant que
> `migrate.php product` n'a pas été passé en entier — c'est un ordonnancement, pas une
> anomalie, et les rapports le disent explicitement.

### A11. Codes-barres en double et valeurs de remplissage

`AR_CodeBarre` est renseigné sur **8 706 articles pour 8 637 valeurs distinctes** : 69
doublons. La colonne sert aussi de pense-bête — « à compléter » (5 articles), « code barre »,
ou des références fournisseur comme « PNR ASA-AVIDYNE ».

**Dolibarr impose l'unicité du code-barres, et refuse la fiche entière** lorsqu'elle en porte
un déjà pris — pas seulement le champ. Un doublon fait donc échouer la création ou l'adoption
du produit, avec toutes ses autres données, pour un champ accessoire.

**Décision** : le code-barres n'est posé que s'il est libre. Les valeurs comportant un espace
ou dépourvues de chiffre sont écartées d'emblée — deux règles qui suffisent à isoler le bruit
sans risque, les 8 572 codes réels faisant tous 12 ou 13 caractères. Le produit est repris
dans tous les cas, seul son code-barres manque, et le rapport dénombre les deux situations.

> **Ce défaut est resté invisible en développement.** `Product::verify()` ne contrôle
> l'unicité que si le module **Codes-barres** est activé (product.class.php:1361) — il ne
> l'était pas sur l'instance de développement. Et l'index `uk_product_barcode` portant sur
> `(barcode, fk_barcode_type, entity)`, il ne bloquait rien non plus : `fk_barcode_type`
> étant NULL sur les 15 811 produits, MySQL n'applique pas la contrainte.
>
> Deux garde-fous neutralisés en même temps, sur un environnement plus permissif que la
> cible. À retenir pour les prochaines reprises : **vérifier les modules activés des deux
> côtés avant de conclure qu'un script est propre.**

### A9. Des déclinaisons créées en articles indépendants

Sage sait gérer les gammes : `AR_Gamme1` désigne l'article porteur, `f_artgamme` en énumère
les déclinaisons. Le mécanisme n'a quasiment pas servi — **209 articles sur 15 812**.

Le reste du temps, les tailles et variantes ont été créées en articles séparés, la variante
étant écrite dans le libellé. Les deux modélisations coexistent parfois dans la même famille
de produits :

```
10476  Chemisier avion rouge F.F.A. brodé            AR_Gamme1 = 1   → XS, S, M, XL
10475  Chemisier avion rouge F.F.A. brodé - Taille L AR_Gamme1 = 0
10479  Chemisier avion rouge F.F.A. brodé - Taille M AR_Gamme1 = 0
10480  Chemisier avion rouge F.F.A. brodé - Taille XL AR_Gamme1 = 0
```

Quelqu'un a commencé à déclarer une gamme sur `10476`, puis on a continué à créer des
articles à côté.

C'est ce qui explique l'essentiel des collisions de référence fournisseur (voir F1) : seize
articles portent la référence `Chemise` chez F274 parce que le fournisseur, lui, ne vend
qu'un modèle. Sur les 371 groupes en collision, 34 seulement impliquent un article à gamme.

**Constaté, non traité.** Basculer ces articles vers les variantes Dolibarr — module activé
mais vierge, aucun attribut ni combinaison — supposerait de fusionner des produits **déjà
synchronisés avec PrestaShop**, chacun avec son identifiant boutique, ses commandes et son
historique. C'est une restructuration du catalogue, à arbitrer avec le client, pas un
effet de bord d'une reprise de prix d'achat.

---

## Tarifs fournisseurs (`f_artfourniss`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| F1 | Une référence fournisseur pour plusieurs articles | 1 033 | Suffixée par la référence produit |
| F2 | Référence fournisseur absente ou vide de sens | 2 159 | Remplacée par la référence produit |
| F3 | Taux de change implicites incohérents | 91 | Taux de l'instance appliqué |
| F4 | Prix d'achat absent ou nul | 1 512 | Ligne créée, tarif à zéro |
| F5 | Clés inexploitables (`CT_Num` vide ou inconnu, `AR_Ref` orphelin) | 12 | Écartées et listées |
| F6 | `AF_QteMini` et `AF_Colisage` font double emploi | 15 962 | Une seule reprise |
| F7 | `AF_Remise` est bien un pourcentage | 58 | Repris dans `remise_percent`, vérifié à l'écran |
| F8 | **Le tarif affiché n'est pas dans les données livrées** | 6 fiches sur 9 vérifiées | **Ouvert** — export applicatif à demander |
| F9 | Tabulations dans les références | 6 | Nettoyées |
| F10 | Colonnes sans emploi | 15 962 | Non reprises |
| F11 | `AR_PrixAch` et `AF_PrixAch` divergent | 8 263 | Deux notions distinctes, seul le tarif est repris |

### F1. Une référence fournisseur pour plusieurs articles

C'est **l'anomalie structurante** de cette table, parce qu'elle heurte de front la façon
dont Dolibarr identifie une ligne tarifaire : `uk_product_fournisseur_price_ref` porte sur
`(ref_fourn, fk_soc, quantity)`. Une référence y désigne un seul article chez un
fournisseur donné.

**1 033 lignes** violent ce postulat, réparties en 371 groupes, jusqu'à 17 articles sur une
même valeur :

```
F255  BAL-A P488-029        17 articles
F274  Chemise               16 articles   (les tailles d'un chemisier, cf. A9)
F88   CC001                 13 articles
F112  ---                   11 articles
F22   3813                   9 articles
```

Certaines sont de vraies références de gamme, d'autres du pur bruit de saisie.

**Le piège n'est pas l'index, c'est le silence.** `ProductFournisseur::update_buyprice()`
appelé sans `product_fourn_price_id` exécute un `DELETE` sur la place occupée **avant** son
`INSERT` (fournisseur.product.class.php:618). La seconde ligne ne provoque donc aucune
erreur : elle fait disparaître la première. Sur l'ensemble de la source, environ **2 600
lignes se seraient évaporées** en laissant les compteurs annoncer un succès complet.

Décision : la référence est conservée quand elle est unique chez le fournisseur, et
désambiguïsée par la référence du produit sinon — `Chemise (#10475)`. La détection se fait
avant le parcours, un traitement par lots ne pouvant repérer autrement deux lignes en
conflit tombées dans des lots différents.

### F2. Référence fournisseur absente ou vide de sens

**2 128 lignes** n'ont aucune référence, et 31 portent une valeur qui n'en est pas une :
`---`, `///`, `//`, `////`. Pour Dolibarr, vingt lignes à référence vide chez un même
fournisseur sont la même ligne vingt fois — F20 en compte justement vingt.

La référence du produit prend le relais. Elle est unique par construction : un article
n'apparaît jamais deux fois sans référence chez le même fournisseur.

### F3. Taux de change implicites incohérents

La source porte les deux prix — `AF_PrixAch` en euros et `AF_PrixDev` en devise — mais pas
le taux qui les relie. Or Dolibarr **recalcule** le prix en euros par
`prix en devise / taux` : c'est le taux qui décide de la valeur stockée.

Le rapport `AF_PrixDev / AF_PrixAch` varie de **0,28 à 2,17 sur une même devise**, là où le
taux de l'instance vaut 1,075 pour le dollar. Une partie des valeurs est crédible, le reste
est manifestement faux.

| Devise | Lignes avec les deux prix | Taux plausible | Aberrant |
|---|---:|---:|---:|
| USD | 469 | 385 | 84 |
| CAD | 13 | 11 | 2 |
| GBP | 6 | 1 | 5 |

Décision : le taux de la source est repris s'il est à moins de 20 % de celui de l'instance —
les deux prix sont alors ceux de la source, au centime. Sinon le taux officiel s'applique et
le prix en euros est recalculé, l'écart étant signalé au rapport.

Cas particuliers : **123 lignes** annoncent une devise sans montant en devise (elles
repartent en euros), **100** ont l'inverse (le taux officiel est la seule voie).

À noter : `AF_Devise = 1` désigne l'euro, comme sur les tiers. Vérifié — sur les 74 lignes
concernées portant les deux prix, 68 ont `AF_PrixDev` égal à `AF_PrixAch`.

### F4. Prix d'achat absent ou nul

**1 295 lignes** à zéro, **217** nulles. La ligne est créée quand même : la référence
fournisseur vaut à elle seule d'être reprise, et un tarif à zéro est visible et corrigeable
depuis la fiche produit.

### F5. Clés inexploitables

- **3 lignes sans `CT_Num`** (cbMarq 12129, 12290, 12528) : rattachables à rien, écartées à
  la lecture.
- **4 `CT_Num` absents de `f_comptet`** : `Andre Rooney`, `DENOD`, `Le comptoir du li`
  (tronqué à 17 caractères par la colonne) et `F990000054`. Les trois premiers sont
  visiblement de la saisie libre à la place d'un code ; le quatrième en a pourtant
  l'apparence.
- **5 `AR_Ref` absents de `f_article`** : 11879, 11899, 11952, 12011, 12656.

Aucun n'est traité en erreur : rien ne les résorbera, et cinq lignes d'erreur masqueraient
les vraies. Ils sont comptés et listés nommément.

### F6. `AF_QteMini` et `AF_Colisage` font double emploi

Les deux colonnes sont **rigoureusement identiques** sur les 15 962 lignes — mêmes valeurs,
mêmes distributions, zéro divergence. C'est la même information saisie deux fois : le
conditionnement. 269 lignes portent une valeur supérieure à 1 (2, 3, 4, 5, 6, 10, 12, 16, 20,
24, 40, 48, 50, 60, 72, 96, 100, 120, 288).

Reprise dans `packaging`, mais **`PRODUCT_USE_SUPPLIER_PACKAGING` n'est pas activée** par la
reprise : cette constante modifie l'arrondi des quantités d'achat pour toute l'instance,
c'est un arbitrage du client. La donnée est stockée, elle s'activera le jour voulu.

### F7. `AF_Remise` est bien un pourcentage — vérifié dans l'ancien ERP

58 lignes portent une remise, valeurs de 2 à 33,5. **`AF_TypeRem` étant NULL sur les 15 962
lignes**, rien dans les données ne disait s'il s'agissait d'un taux ou d'un montant. La
question est tranchée par lecture d'écran sur l'article **13566** :

| Ancien ERP | Valeur | Source |
|---|---:|---|
| Prix achat brut | 39,00 | `AF_PrixAch` = 39,00 |
| Remise | 30,00 | `AF_Remise` = 30,00 |
| Prix achat net | 27,30 | 39,00 × (1 − 30/100) |

**`AF_PrixAch` porte le brut, `AF_Remise` le taux, le net est calculé.** Le modèle cible est
le même : `price` reçoit le brut, `remise_percent` le taux, et le coeur applique
`unitprice × (1 − remise_percent / 100)` partout où le prix net compte — choix du meilleur
fournisseur (`fournisseur.product.class.php:1013`), colonne « Prix d'achat » de la liste des
produits, valorisation des nomenclatures, prix d'achat des lignes de document. Le taux reste
visible et modifiable dans l'onglet **Prix fournisseurs** de la fiche article, colonne
« Remise quantité min ».

Le cas 13566 est reproduit à l'identique en cible : `price` 39,00 · `remise_percent` 30.

> **La portée de ce constat est limitée à la nature de la colonne**, et il ne faut pas en tirer
> qu'elle est fiable. `AF_Remise` est bien un pourcentage appliqué à `AF_PrixAch` — mais sur
> neuf fiches confrontées à l'écran de l'ancien ERP, six affichent autre chose que la table.
> Voir F8, qui est le compte rendu de cette confrontation.

### F8. Le tarif fournisseur affiché n'est pas dans les données livrées

C'est **la seule anomalie de tarif fournisseur qui reste ouverte**, et elle ne se règle pas
côté reprise. Elle a coûté assez d'heures pour mériter un compte rendu complet.

#### Ce qu'on cherche

Pour chaque couple article / fournisseur, le prix d'achat tel que l'ancien ERP l'affiche : un
brut, une remise, un net. Le calcul y est constant — `net = brut × (1 − remise/100)` — et c'est
la seule chose qui ne varie jamais dans tout ce qui suit.

#### Le point de départ : une colonne presque vide

| `AF_Remise` | Lignes |
|---|---:|
| NULL | 12 347 |
| 0 | 3 557 |
| > 0 | **58** |

#### Le vrai problème : la table est contredite par l'écran

Ce n'est pas qu'elle soit peu remplie. **Neuf fiches ont été ouvertes dans l'ancien ERP et
confrontées à la table. Six divergent.**

| Article | L'écran affiche | La table contient | Nature de l'écart |
|---|---|---|---|
| `13566` | brut 39,00 · remise 30 % | idem | concorde |
| `3355` | brut 333,34 · remise 0 | idem (`AF_Remise` NULL) | concorde |
| `7248` | brut **50,80 $** · remise 0 | `AF_PrixAch` 47,244 € | concorde via `AF_PrixDev` |
| `14240` | remise **17 %** | `AF_Remise` NULL | remise absente |
| `11866` | remise **0 %** | `AF_Remise` 33,50 | remise contredite |
| `13463` | remise **7,85 %** | `AF_Remise` 5,00 | remise contredite |
| `15721` | remise **3 %** | `AF_Remise` 0,00 | remise absente |
| `13631` | brut **13,24** | `AF_PrixAch` 1,92 | prix contredit |
| `11912` | brut **39,00** | `AF_PrixAch` 0,00 | prix absent |

**Ce ne sont pas des données périmées.** Les copies vont jusqu'au 25/06/2026, et les lignes
concernées n'ont pas été modifiées depuis — `15721` remonte à septembre 2025, `11912` à mars
2023, `3355` à 2019.

#### Trois reconstitutions essayées, trois réfutations

**1. Depuis la table fournisseur** (`AF_PrixAch` + `AF_Remise`) — échoue sur les six cas
ci-dessus. C'est pourtant ce que fait la reprise, faute de mieux.

**2. Depuis l'historique des achats.** `f_docligne_global.DL_Remise01REM_Valeur` porte bien
des remises — 4 534 lignes d'achat sur 6 855 articles —, et c'est là qu'on retrouve les 17 %
de l'article `14240`, introuvables ailleurs. La piste semblait décisive. Elle ne l'est pas :

- la remise d'un document est celle **négociée ce jour-là**, pas le tarif de référence ;
- elle s'applique visiblement au **document entier** : le 28/05/2026, les articles 13463,
  13470, 13471 et 13472 du fournisseur F990000055 portent tous 7,85 %, quand leur tarif est
  à 5 % ;
- confrontée aux 58 remises connues, elle n'en retrouve que **28** ;
- et pour `14240`, l'écran affiche la remise de **décembre 2023** (17 %), pas celle du dernier
  achat de juin 2025 (19 %). Même la règle « la plus récente » ne tient pas.

**3. Depuis le coût de revient de l'article** — `remise = 1 − AR_CoutStd / AR_PrixAch`. Sur les
53 témoins exploitables : 13 exactes, 18 à un point près, **35 divergentes**. `AR_CoutStd` est
un coût moyen qui intègre l'historique des achats, pas un prix net courant.

Chaque piste explique quatre cas sur cinq et se casse sur le suivant. **Ce n'est donc pas un
problème d'interprétation qu'un effort supplémentaire résoudrait : l'information affichée est
calculée ou stockée ailleurs que dans les tables exportées.**

#### Deux acquis à ne pas perdre

**L'ancien ERP affiche le prix dans sa devise d'origine.** L'article `7248` s'affiche à
**50,80 $** (`AF_PrixDev` = 50,80, `AF_Devise` = 2) ; `AF_PrixAch` = 47,244 n'en est que la
conversion en euros. Une lecture de la seule colonne en euros perd l'information de devise.

**`AF_PrixAch` n'a pas de sémantique constante.** Sur un échantillon de 20 couples : elle porte
le **net** sur certains (`1646` 10,10 pour un brut à 10,50), le **brut** sur d'autres (`15721`
15,20), une conversion de devise (`7248`), ou zéro (`11912`). C'est ce qui explique
rétrospectivement l'inclassabilité de l'export CSV, qui exporte cette colonne : sur les 44
lignes à remise connue, il donne 14 fois le net, 13 fois le brut, 17 fois autre chose.

#### Conséquence pour la reprise

Le brut est repris tel quel, la remise quand elle existe. Sur les 12 347 lignes sans remise, le
prix d'achat en cible est **au mieux exact, au pire surévalué du montant de la remise**.

**Décision : ne rien reconstituer.** Reprendre `AF_PrixAch` et `AF_Remise` reste ce qu'il y a de
plus défendable — c'est la donnée telle qu'elle est livrée, pas une déduction dont on sait
qu'elle échoue une fois sur cinq. Une reconstitution plausible mais fausse serait pire que
l'absence : elle serait invisible.

#### Ce qu'il faut demander à l'éditeur

Un export produit par **l'application** et non par une copie de ses tables :

```
AR_Ref   |   CT_Num   |   prix d'achat brut affiché   |   remise affichée
```

À défaut, l'indication de la table et des colonnes où l'application lit ces deux valeurs. Les
quatre références `15721`, `11912`, `3355` et `7248` suffisent à poser la question sans qu'elle
puisse être éludée : elles couvrent les quatre configurations rencontrées.

### F9. Tabulations dans les références

Six références portent une tabulation en tête ou en fin — `\t9782373011173`, `52641\t` —,
et huit autres des espaces de bord.

Anecdotique en soi, structurant pour le code : **`TRIM()` de MySQL ne retire pas les
tabulations**, contrairement à `trim()` de PHP. Une carte des collisions construite par
agrégation SQL n'aurait donc pas coïncidé avec les clés calculées à l'exécution, et ces
lignes seraient passées à travers la détection. Le nettoyage est fait par une fonction PHP
unique, autorité sur la forme d'une référence en préparation comme à l'écriture.

### F10. Colonnes sans emploi

`AF_TypeRem`, `AF_Garantie` et `EG_Champ` sont NULL sur les 15 962 lignes. `AF_Unite` ne
vaut `1` que sur 710 lignes, `AF_Conversion` et `AF_ConvDiv` sont entièrement nulles.
`designation_fournisseur` n'est renseignée que 206 fois, `AF_DelaiAppro` 14 fois,
`AF_CodeBarre` 4 fois. Les colonnes de tarif futur (`AF_PrixAchNouv`, `AF_RemiseNouv`,
`AF_DateApplication`) ne sont pas reprises : Dolibarr n'a pas de notion équivalente.

### F11. Deux prix d'achat qui ne parlent pas de la même chose

`f_article` porte son propre `AR_PrixAch`, distinct de l'`AF_PrixAch` du fournisseur
principal. Sur les 14 929 couples concernés :

| | Couples |
|---|---:|
| valeurs identiques | 6 564 |
| prix de l'article supérieur | 4 238 |
| prix de l'article inférieur | 4 025 |

Ce n'est donc pas une redondance mais **deux notions** : `AF_PrixAch` est le tarif négocié,
`AR_PrixAch` un prix constaté au niveau de l'article. Le CSV `expart.csv` tranche dans le même
sens — sur les 8 251 couples divergents, sa colonne suit `AF_PrixAch` **7 184 fois** contre
1 134 pour `AR_PrixAch` : c'est bien le tarif fournisseur qu'il exporte.

Aucun des deux n'est repris tel quel dans `cost_price` : le tarif va dans
`llx_product_fournisseur_price` (script `supplierprice`), le coût de revient vient de
`AR_CoutStd` (A7).

**Un point de vigilance en découle.** L'écran de l'ancien ERP peut afficher, dans son bloc
fournisseur, une valeur qui n'est ni l'une ni l'autre. Article 13631 : l'écran donne 13,24,
`AR_PrixAch` vaut 13,24, mais `AF_PrixAch` vaut **1,92**. Ce n'est pas un défaut de notre
copie — les deux imports indépendants (celui de la base de travail et l'import intégral des
archives) donnent la même valeur, au centime et à la seconde de `cbModification` près. La
source livrée est simplement en désaccord avec ce que l'application affiche, ce qui rejoint la
conclusion de F8 : **ce dont nous avons besoin doit venir d'un export produit par
l'application, pas d'une copie de ses tables.**

---

## Emplacements de stock (`f_emplacements`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| E1 | Libellés absents de la source | 819 numéros | Extraits du HTML, complétés par `F_DEPOTEMPL` |
| E2 | Un même libellé pour plusieurs emplacements | 70 libellés | Fusionnés |
| E3 | Emplacements sans libellé | 3 occupés | Nommés d'après leur numéro |
| E4 | Libellés fantaisistes | 6 | Repris tels quels |
| E5 | La salle 6 manquait à l'extraction | 8 (114 articles) | Récupérée, couverture désormais totale |
| E7 | Un seul emplacement par article dans la source | 27 670 lignes | Sans objet : Dolibarr en autorise autant qu'on veut |
| E8 | L'entrepôt principal adopté n'était pas marqué | 1 | Marqueur posé à l'adoption (0.8.1) |

### E1. Les libellés n'étaient nulle part dans les données

`f_artstock.DP_NoPrincipal` ne porte qu'un numéro. La table de correspondance de Sage
(`F_DEPOTEMPL`) **n'a pas été répliquée** dans la base livrée — vérifié par balayage de
l'intégralité du schéma. Aucune autre colonne ne contient d'emplacement : les 20
`champ_libre` de `f_article` sont vides, comme `f_catalogue.CL_Emplacement`, pourtant
prometteur par son nom.

L'export CSV de l'ancien ERP donne les libellés mais **pas les identifiants**. Ceux-ci ont
finalement été trouvés dans le **code HTML de l'interface**, portés par l'attribut
`data-dp-no` des cases à cocher. D'où la table `f_emplacements`, seule table de ce jeu qui ne
vienne pas de Sage, livrée avec son script dans `data/f_emplacements.sql`.

`F_DEPOTEMPL` a fini par réapparaître dans l'import intégral des archives, et a complété
l'extraction des huit emplacements de la salle 6 (E5). La table en compte désormais **1 014**,
pour une couverture de **819 numéros utilisés sur 819**.

Ses libellés n'ont en revanche pas remplacé les nôtres, car ils sont de moindre qualité : les
accents y sont perdus au profit du caractère `?` — `BOUTIQUE VITRINE S?CURIS?E 1`, vérifié en
hexadécimal — et plusieurs libellés portent des tabulations de tête. Le fichier livré est donc
un composite, et il fait autorité : il est régénéré depuis la base, pour que les deux ne
puissent pas diverger.

> **Piège évité.** Faute d'identifiant dans le CSV, un alignement par position avait été
> envisagé — le fichier n'étant pas trié alphabétiquement, il suivait vraisemblablement
> l'ordre de création. Les trois emplacements les plus chargés tombaient d'ailleurs sur
> « BOUTIQUE » avec un décalage de 1, ce qui était troublant. C'était **faux** : la
> première ligne du CSV correspond au numéro 1014. Des milliers d'articles auraient été
> placés au mauvais endroit sans que rien ne le signale.

### E2. Un même libellé pour plusieurs emplacements

70 libellés sont partagés par plusieurs numéros : huit s'appellent `BOUTIQUE`, huit
`DERRIERE S3-A2`, sept `S1-A11-2`. S'y ajoutent des variantes d'écriture qui désignent
probablement le même endroit — `BOUTIQUE`, `B-BOUTIQUE` (122 articles), `B- BOUTIQUE` (96).

Dolibarr impose l'unicité du libellé sur **toute l'entité** (`uk_entrepot_label`), et non
sous le parent. **Décision : les homonymes stricts sont fusionnés** (93 emplacements), le
premier numéro rencontré l'emportant. Les variantes d'écriture restent distinctes : rien ne
prouve qu'elles désignent le même emplacement physique, et les fusionner serait irréversible.

### E3. Emplacements sans libellé

Trois emplacements occupés (345, 512, 706 — 16 articles, dont 8 avec du stock) n'ont aucun
libellé. Dolibarr refuse un entrepôt sans nom : leur numéro d'origine en tient lieu,
`Emplacement 345`.

### E4. Libellés fantaisistes

`blibli` (19), `AFFECTER` (836 et 841), `B` (380), `M` (397), et une **adresse client
complète** saisie en guise d'emplacement (590 : `2V MIGNOTTE SYLVAIN 22 ROUTE DE CAR`).
Quatre d'entre eux sont effectivement occupés.

**Décision : repris tels quels.** Ce sont de vraies saisies, au client de les corriger en
connaissance de cause.

### E5. Huit emplacements manquaient à l'extraction — c'était la salle 6

Huit numéros encore portés par des articles étaient absents de l'extraction HTML : **605 à
611, et 639**, pour 114 articles et 161 unités.

`F_DEPOTEMPL`, retrouvée depuis dans l'import intégral des archives, les nomme sans
ambiguïté :

| Numéro | `DP_Intitule` | Articles | Quantité |
|---:|---|---:|---:|
| 605 | `S6-A2-2` | 23 | 29 |
| 606 | `S6-A2-3` | 10 | 0 |
| 607 | `S6-A3-3` | 12 | 8 |
| 608 | `S6-A1-2` | 6 | 48 |
| 609 | `S6-A4-3` | 20 | 30 |
| 610 | `S6-A3-2` | 13 | −12 |
| 611 | `S6-A4-2` | 22 | 36 |
| 639 | `S6-A4-4` | 8 | 22 |

**C'est la salle 6 en entier**, et le seul étage du magasin que la copie de l'interface avait
manqué. Les huit numéros sont désormais dans `f_emplacements`, qui en compte **1 014**, et les
114 lignes de stock rejoignent leurs vrais emplacements au lieu de l'entrepôt de repli.

> **Une conclusion erronée, et pourquoi elle l'était.** Ces huit numéros avaient d'abord été
> déclarés *supprimés dans l'ancien ERP*, au motif que leurs voisins immédiats étaient
> présents — 604 `R14`, 612 `COMPLEMENT AUX CARTES AERO`, 638, 640 — donc qu'il ne pouvait pas
> s'agir d'une page manquée. Le raisonnement était séduisant et faux : les numéros
> d'emplacement ne suivent aucun ordre géographique, `604` et `612` ne sont pas voisins de
> rayon. La contiguïté des numéros ne prouvait rien sur la contiguïté à l'écran.
>
> Une absence dans une extraction ne doit pas être interprétée à partir de la donnée
> extraite : ce n'était pas un trou dans les données, c'était un trou dans notre copie.

**L'entrepôt de repli « À localiser » reste en place**, créé sous l'entrepôt principal et
marqué `SAGE:ORPHELIN`. Il n'a plus de contenu attendu, mais il demeure la destination de
toute ligne dont l'emplacement ne serait pas résolu — ce qui vaut mieux qu'un mouvement
refusé, et se voit d'un coup d'oeil. Le libellé est une consigne et non un constat, et son
accent initial le fait remonter en tête des sélecteurs d'entrepôt.

À ne pas confondre avec deux situations voisines, volontairement traitées ailleurs : les
trois emplacements **sans libellé** (E3), qui existent toujours dans l'ancien ERP et
redeviendront exploitables si quelqu'un les y nomme ; et les quelque 2 000 lignes **sans
aucun emplacement** (E6), qui vont dans l'entrepôt principal — un article jamais rangé n'est
pas une anomalie de données.

### E6. Un tiers du stock n'a aucun emplacement

Sur les 15 839 lignes du dépôt principal, **6 575 seulement portent un emplacement**. Parmi
les lignes qui ont réellement du stock, **1 980 n'en ont aucun** — soit 35 %.

Ce n'est pas une anomalie de la reprise mais un état de fait de l'ancien ERP.

### E7. Un seul emplacement par article dans la source, plusieurs possibles dans Dolibarr

L'ancien ERP ne gère **qu'un emplacement par article** : `DP_NoPrincipal`, la clé primaire
`(AR_Ref, DE_No)` interdisant plusieurs lignes par article et par dépôt. `DP_NoControle`,
seul autre champ possible, est NULL sur les 27 670 lignes.

Les « quatre emplacements » évoqués au démarrage ne sont donc pas une donnée à retrouver
mais un **besoin exprimé pour la cible** — et il ne demande aucun développement :
`uk_product_stock (fk_product, fk_entrepot)` autorise une ligne de stock par couple
produit/entrepôt, sans limite de nombre. Un article pourra donc être présent dans quatre
emplacements, ou dans vingt.

**Conséquence pour la reprise** : elle pose le stock dans l'unique emplacement connu de la
source. La ventilation sur plusieurs emplacements se fera ensuite dans Dolibarr, par les
transferts de stock, au fil du rangement réel.

### E8. L'entrepôt adopté n'était pas marqué, et le script suivant refusait de démarrer

Rencontré en production. L'entrepôt principal y préexistait — créé par la boutique, nommé
`BOUTIQUE.AERO` —, et `warehouse` l'a correctement reconnu par son libellé plutôt que d'en
créer un second, ce que `uk_entrepot_label` aurait de toute façon refusé. Son rapport
annonçait « déjà présent ». Mais **rien en base ne disait qu'il tenait ce rôle** : son
`import_key` restait vide.

`stock`, qui cherche le marqueur `SAGE:DEPOT1` pour savoir où verser les articles sans
emplacement, s'arrêtait donc net :

```
Entrepôt principal introuvable (marqueur SAGE:DEPOT1) : lancez « migrate.php warehouse » avant celui-ci.
```

Message trompeur au possible : `warehouse` venait précisément d'être lancé, avec succès.

**Corrigé en 0.8.1** : le marqueur est posé à l'adoption, comme il l'est pour les tiers venus
de la boutique. Un marqueur venu d'un autre import n'est jamais écrasé — le rapport avertit
alors du conflit plutôt que de le laisser découvrir au lancement suivant.

> **La leçon.** Une adoption qui ne laisse aucune trace en base n'est pas une adoption, c'est
> une coïncidence qui se répète à chaque passage. Tout objet qu'un script reconnaît comme
> sien doit porter sa marque, sans quoi le script d'après n'a aucun moyen de le retrouver —
> et le message d'erreur qu'il produira désignera le mauvais coupable.

---

## Stocks (`f_artstock`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| S1 | Un seul dépôt réel sur trois déclarés | 11 831 lignes écartées | Dépôt 1 uniquement |
| S2 | Ligne sans référence article | 1 (−1 826) | Écartée et signalée |
| S3 | Quantités négatives | 131 (−954) | Reprises telles quelles |
| S4 | `AS_MontSto` est unitaire malgré son nom | 1 755 | Ignorée |
| S5 | `AS_QteDispo` est un cache, pas une donnée | 27 670 | Non repris |
| S6 | Lignes sans stock mais avec seuil | 236 | Reprises pour leurs seuils |
| S7 | Seuils d'alerte négatifs | 2 | Ramenés à zéro |
| S8 | Articles porteurs de stock devenus services | 3 (−14) | Écartés et signalés |
| S9 | `DL_MvtStock` vide, l'historique n'est pas marqué | 1 039 279 lignes | Historique non repris |
| S10 | Lignes sans coût standard pour la valorisation | 21 + 104 | Prix de revient en repli, puis sans prix |
| S11 | **En production, le stock était déjà posé par la boutique** | 5 559 articles | Relocalisé, quantités inchangées |

### S1. Un seul dépôt réel

`f_depot` en déclare deux, la source en utilise trois :

| Dépôt | Lignes | Contenu |
|---|---:|---|
| 1 — `boutique.aero` | 15 839 | **La totalité du stock**, des seuils, des emplacements |
| 999 — `Siege boutique.aero` | 11 826 | **Intégralement à zéro**, y compris réservé et commandé |
| 0 — *non déclaré* | 5 | Cinq lignes négatives, cumul −68 |

Les deux dépôts partagent d'ailleurs la même adresse, et les 19 caisses de `f_caisse`
pointent toutes sur le dépôt 1. **Décision : seul le dépôt 1 est repris.**

### S2. Une ligne de stock sans référence article

Une ligne du dépôt 1 a un `AR_Ref` vide et porte **−1 826 unités** — le plus gros écart de
toute la reprise. Aucun produit où la poser : elle est écartée par le filtre de lecture,
mais **dénombrée et affichée au rapport**, pour qu'elle ne disparaisse pas du récit.

### S3. Quantités négatives

131 lignes reprises, cumul −954. Dolibarr les accepte,
`STOCK_DISALLOW_NEGATIVE_TRANSFER` n'étant pas posée. **Décision : reprises telles quelles**,
et listées au rapport — la photo doit rester fidèle, y compris dans ses incohérences.

Parmi elles, 32 articles composés dérivent : leur stock propre est décrémenté à la vente sans
que rien ne l'alimente. La table de nomenclature de Sage n'ayant pas été importée, il n'y a
rien à en tirer de plus.

### S4. `AS_MontSto` n'est pas un montant

Malgré son nom, la colonne porte une valeur **unitaire** : sur les 1 755 lignes renseignées,
1 555 égalent `AS_CoutStd` et 1 297 `AS_PrixRU`, contre **53 seulement** pour
`quantité × prix`. Elle n'est d'ailleurs remplie que sur 6 % des lignes, dont 429 à stock nul.

**Décision : ignorée.** La valorisation se calcule — voir S10 pour la règle retenue.

### S5. `AS_QteDispo` est un cache

Vérifié : `AS_QteDispo = AS_QteSto − AS_QteRes − AS_QtePrepa`, à 99,3 %. Le taux global est
trompeur — 98 % des lignes ont réservé et préparation à zéro — mais le segment discriminant
tranche : sur les 162 lignes où `AS_QtePrepa` n'est pas nul, la formule tient sur 146 contre
5 pour sa concurrente. `AS_QteCom`, l'attendu fournisseur, n'entre pas dans le disponible.

186 lignes s'écartent de la formule : c'est un champ recalculé par l'applicatif, qui peut
désynchroniser. **Décision : ni le disponible, ni le réservé, ni le commandé ne sont repris.**
Dolibarr les recalcule depuis les commandes.

Quatre colonnes sont par ailleurs intégralement à zéro : `AS_QteResCM`, `AS_QteComCM`,
`AS_QteSIS`, `as_qteroul`. La contremarque n'est pas utilisée, ce que confirme
`AR_Contremarque`, NULL sur 15 799 articles.

### S6. Des lignes sans stock mais avec seuil

236 lignes ont une quantité nulle et un seuil renseigné. Un filtre sur la seule quantité les
perdrait alors qu'elles portent une information réelle.

**Conséquence à connaître** : ne produisant aucun mouvement, elles n'entrent jamais dans
l'index d'idempotence et sont **retraitées à chaque passage**, comptées en « mis à jour ».
Sans danger — réécrire le même seuil est idempotent — mais cela explique pourquoi le
compteur n'est jamais à zéro. L'alternative, poster un mouvement de quantité nulle pour les
marquer, aurait pollué l'historique de 236 lignes vides.

### S7. Seuils d'alerte négatifs

Deux articles portent `AS_QteMini = −200`. Un seuil d'alerte négatif ne se déclencherait
jamais et s'afficherait comme une aberration sur la fiche : **ramenés à zéro et signalés**.

### S8. Des articles porteurs de stock devenus des services

Trois articles ont du stock dans l'ancien ERP mais sont de type « service » dans Dolibarr :
`#10548` (−9), `#07338` (−4), `#09163` (−1).

Dolibarr ne déplace pas le stock d'un service tant que `STOCK_SUPPORTS_SERVICES` n'est pas
activée. **Décision : écartés et listés nommément au rapport, avec le remède** — les passer
en type « Produit » puis relancer. Le script ne change pas leur type de lui-même : ils sont
synchronisés avec la boutique, où cela aurait des conséquences.

### S9. L'historique des mouvements est reconstituable, mais n'est pas repris

`f_docligne_global.DL_MvtStock`, la colonne censée marquer les lignes qui bougent le stock,
**est vide** : 28 lignes sur 1 039 279. Toute reprise qui s'appuierait dessus produirait zéro
mouvement, en silence.

L'historique reste pourtant reconstituable par le type de document :

```
AS_QteSto = AS_QteInv
          + réceptions (domaine 1, type 13) + entrées (domaine 2, type 20)
          − factures (domaine 0, types 6 et 7) − sorties (domaine 2, type 21)
          sur les documents postérieurs à date_inventaire
```

**Vérifié à 98,9 %** sur les 15 687 articles suivis en stock. À noter : les préparations, les
bons de livraison et les factures fournisseur ne bougent pas le stock — la sortie se fait à
la facturation, l'entrée à la réception.

**Décision : non repris.** Un stock d'ouverture suffit, et l'historique reste consultable
dans l'ancien ERP. La formule est consignée ici si le besoin se présentait — il faudrait
alors compter environ 581 000 mouvements et nettoyer au préalable les dates d'inventaire
aberrantes : 519 à `0000-00-00`, 109 en année **9202**, 2 en 2027.

### S11. En production, le stock était déjà là — posé par la boutique

Constat fait sur l'instance en ligne avant toute reprise de stock :

| Produits porteurs de stock | Produits | Unités |
|---|---:|---:|
| repris de l'ancien ERP (`ref_ext` en `SAGE:`) | 5 559 | **172 965** |
| nés dans la boutique, hors reprise | 536 | 5 012 |

Or la photo n'annonce que 167 830 unités. **PrestaShop tient son stock de l'ancien ERP**, et
Prestasync l'avait déjà poussé dans Dolibarr — tout entier dans l'entrepôt principal, la
boutique n'ayant aucune notion d'emplacement.

Concordance article par article :

| | Articles |
|---|---:|
| quantité identique à la photo | **15 242** (96,4 %) |
| en place supérieur | 376 |
| en place inférieur | 192 |

Le stock en place **est** celui de l'ancien ERP. Reste à comprendre l'écart net de +5 135
unités, et sa décomposition est instructive :

| Origine de l'écart | Articles | Unités |
|---|---:|---:|
| articles que la source **ne suit pas en stock** | 13 | **+2 472** |
| un seul article, `#09462` | 1 | **+2 214** |
| tous les autres | 554 | **+449** |

Les 13 premiers ne sont pas des écarts mais un malentendu de lecture : leur `AR_SuiviStock`
valant 0, le zéro de la source ne signifie pas « il n'y a rien » mais « je ne compte pas ».
Le garde-fou du script les écarte déjà, leur stock reste où il est. `#09462` est une dérive
de la boutique, confirmée à l'écran de l'ancien ERP — 3 168 affichés contre 5 402 en base.

Reste **449 unités sur 554 articles**, moins d'une par article : le décalage normal entre la
copie de la source et l'injection qui a peuplé la cible.

**Décision : relocaliser, jamais réajuster.** La reprise déplace le stock vers l'emplacement
que la source connaît — seule information qui manque à la boutique — et ne touche pas aux
quantités. Il n'y a pas de masse d'écarts à corriger, mais du bruit et deux cas isolés, qui
s'arbitrent article par article après la reprise.

> **Une explication séduisante et fausse, à ne pas resservir.** L'écart avait d'abord été
> attribué aux « ventes et réceptions postérieures à la copie ». La chronologie l'interdit :
> les mouvements qui ont peuplé la cible datent du **23 juin** et forment une injection unique
> — 5 572 mouvements pour 5 571 produits, un seul par produit —, alors que
> `f_artstock.cbModification` s'arrête au **26 juin**. La copie est postérieure de trois jours,
> les écarts ne peuvent donc pas être des ventes qui l'auraient suivie.
>
> Une seconde vague de 520 mouvements existe bien au 15 juillet, mais elle ne concerne que des
> produits nés dans la boutique, hors du périmètre de la reprise : 519 sur 520.
>
> La leçon tient en une phrase : **datez les faits avant de les expliquer.** Deux requêtes sur
> `datem` et `cbModification` auraient évité de bâtir un raisonnement, et une décision, sur
> une chronologie supposée.

Le mécanisme est décrit en tête de `class/migrationstock.class.php`. Deux points méritent
d'être retenus au-delà de ce projet :

- **Le régime se choisit ligne par ligne, pas par une option.** Une option de ligne de
  commande qu'on oublie de passer double le stock ; une condition évaluée sur l'état réel du
  produit ne le fait jamais.
- **Antidater ne protège de rien.** `_create()` fait un `reel = reel + qty`
  (`mouvementstock.class.php:608`) sans jamais trier par date : poser une ouverture au 1er
  juillet par-dessus un stock de juillet donne le même total qu'en la datant du jour. La date
  ne sert qu'à l'écran « Stock à une date », qui reconstitue par les mouvements datés
  (`stockatdate.php:216`) — utile pour la lisibilité de l'historique, sans effet sur les
  quantités.

### S10. La valorisation suit la règle du coût de revient, repli compris

Le prix porté par chaque mouvement d'entrée est celui qui alimente le coût moyen pondéré de
Dolibarr. Il applique donc **exactement** la règle de A7 : coût standard, puis prix de revient
unitaire en dessous du centime. Sur les 5 926 lignes à reprendre :

| Valorisation | Lignes | Unités |
|---|---:|---:|
| coût standard | 5 801 | |
| prix de revient, en repli | **21** | 93 |
| aucune valeur disponible | 104 | 812 |

Le repli n'est pas anecdotique : ces 21 lignes représentent **14 443,92 €** qui entreraient
sinon avec un coût moyen nul. La règle doit être la même des deux côtés, sans quoi la fiche
article afficherait un coût de revient et l'onglet Stock une valorisation à zéro pour le même
produit.

Les 104 lignes sans valeur disponible reçoivent un mouvement sans prix : la quantité est
juste, le coût moyen reste nul. Le rapport les dénombre, ainsi que les lignes passées par le
repli.

---

## Documents commerciaux (`f_doc*`)

Les quatre tables de documents — 318 030 en-têtes, 1 039 279 lignes — ont été analysées le
29/07/2026. Depuis, six familles sont reprises (commandes clients et fournisseur, factures
avec leurs règlements — retrouvés dans `z_docregl_global` —, réceptions, expéditions, devis) ;
le carnet atelier type 3 est écarté (décision client, 20/08/2026). Pour les devis, le statut
fait foi par la grille donnée par le client le 20/08/2026 — l'écran ADD affiche « Terminée »
pour le statut 9 là où le dictionnaire `z_statut_document` de l'export dit encore « Devis
refusé » : le libellé a été renommé côté ADD, et « terminée » signifie « transformée en
commande ».

Points d'origine toujours vrais :

- **Coupure d'archivage fin 2019** : avant, il ne reste que des factures comptabilisées.
- **316 bons de livraison pour 62 519 préparations** : le vrai flux d'expédition est la
  préparation de livraison (type 2) ; le type 3 est le carnet atelier/SAV.

Les rattachements sont excellents : 99,9 % des documents de vente retrouvent leur tiers,
96 % des lignes de facture retrouvent leur produit.

### D2. Préparations de livraison : statut muet, port posé à l'expédition, composants à 0 €

Quatre constats mesurés le 20/08/2026 sur le type 2, qui fondent les choix du script
`shipment` :

- **le statut ne dit rien** : le dictionnaire de la surcouche ne définit que le 0
  (« A préparer »), porté par 1 824 pièces pourtant soldées et facturées ; le 9 (terminal)
  couvre le reste, et `Z_Solde` contredit les dates (330 « non soldées » étalées 2019-2026).
  Tout est repris clôturé ;
- **les frais de port et modes de retrait naissent à l'expédition** (PORTSTD ×15 321,
  RETRAITRELAIS ×8 826, PORTLCS, PORTINT, RETRAITMAG…) : absents de la commande par nature,
  ils ne peuvent pas devenir des lignes d'expédition Dolibarr — recopiés en note ;
- **les produits montés s'expédient parent + composants** : la préparation liste le kit à son
  prix PUIS ses composants à 0 € (vérifié sur les pochettes VFR). Seul le parent se rattache
  à la commande ; les composants vont en note ;
- **296 des 361 « sans commande » sont des coquilles vides** — aucune ligne, créées en rafale
  à la seconde (artefact ADD) ; une vingtaine des autres se rattrape par `DO_NoWeb` (numéro de
  commande ou numéro web commun), le reliquat est écarté et listé. Les 6 797 lignes sans
  article sont des annotations — dont les **numéros de série livrés**, seule traçabilité série
  de l'ancien ERP, préservés dans la note de chaque expédition.

### D1. L'ère Sage native ne porte aucun prix unitaire HT — 62 716 factures reprises à 0 €

**Toutes les lignes de l'ère `FAC` (oct. 2015 → mars 2019) ont `DL_PrixUnitaire` à NULL** —
148 114 lignes sur 148 114. Seul `DL_PUTTC` y est rempli (148 002 lignes), avec `DL_Taxe1`
toujours renseigné (20, 5,5 ou 0 %). La reprise construisait les lignes depuis le HT : les
62 716 factures de l'ère — **6,79 M€ TTC dans ADD** — sont toutes entrées à 0,00 €, sans une
erreur, une ligne à zéro étant parfaitement légale.

Découvert le 19/08/2026, en rattachant les factures des clients disparus : le contrôle des
montants de la veille l'avait pourtant montré (« 62 407 écarts FAC, cumul 6,8 M€ ») mais avait
été mal lu — la colonne `DL_MontantTTC` passait pour non comparable sur cette ère, alors que
c'est Dolibarr qui était à zéro.

Correctif en 0.16.1, affiné en 0.16.2 : quand le HT est NULL, **le montant de ligne fait
foi** — `DL_MontantHT` (remise reconstituée pour que Dolibarr y retombe), à défaut
`DL_MontantTTC` détaxé, et une ligne sans aucun montant vaut zéro, jamais de repli sur le
`PUTTC` (il refacturerait les doublons d'export). La première
reconstruction (PUTTC seul) était fausse sur 783 pièces (891 k€) : des **remises de 100 %**
dont le prix ne vit que dans le montant (PUTTC à zéro), et des **lignes dupliquées par
l'export, montants à NULL** — une ligne sans aucun montant vaut zéro, c'est un doublon.
**Seul le NULL du PU déclenche le repli** : un HT réellement à zéro (ligne offerte, texte)
reste à zéro. Réparation de l'existant par `scripts/fix_invoice_zero_amounts.php` (pièces à
PU NULL en écart avec la somme des montants de ligne, puis rejeu de la reprise — converge).

Trois pièges dans le périmètre de réparation :
- les **ventes magasin réglées par avoir** (`F02…`) sont légitimement à zéro : une ligne
  `#AVOIR` négative, dont le montant ne vit que dans le PU, équilibre la marchandise — la
  somme des `DL_MontantTTC` d'ADD les fait passer pour valorisées ;
- `ABS(NULL)` élimine la ligne d'un `WHERE` : compter les PU vides exige `COALESCE`, sans
  quoi le phénomène paraît toucher 3 pièces au lieu de 62 967 ;
- les doublons d'export à montants NULL comptent pour zéro dans la somme de référence
  (`SUM(COALESCE(DL_MontantTTC, 0))`), exactement comme dans la reconstruction — c'est ce
  qui fait converger le périmètre.

### D3. `z_docregl_global` est incomplète — `DR_Regle` fait foi sur le règlement

La table des règlements ne commence qu'en **novembre 2019** (5 lignes éparses avant, un
filet d'essai au printemps 2019, puis ~15 000/an). Et même après, elle ne voit pas tout :
sur 2020-2025, **6 739 factures marquées réglées par l'ancien ERP n'y ont aucune ligne** —
des encaissements jamais enregistrés en détail.

Le drapeau qui fait foi est dans l'entête : `f_docentete_global.DR_Regle`. Calibrage sur
2020-2025, là où les règlements existent : 88 400 concordances avec l'état payé de la
reprise, 12 contradictions. Les colonnes `reglement_1..10` et `Z_Solde` de l'entête sont
vides partout — des vestiges.

**Conséquence.** La reprise des factures (qui pose « payée » d'après les règlements) laisse
« impayées » 71 585 factures d'avant octobre 2019 et ~6 700 récentes qui ont pourtant été
encaissées. `scripts/classify_paid_invoices.php` réaligne : réglée ADD → payée ; non réglée
et antérieure à la borne → abandonnée « Prescription » (arbitrage client du 31/08/2026).
Ce qui reste impayé (~1 400 pièces, 126 k€) est la vraie liste de recouvrement.

**Avant 2019, le drapeau ne discrimine rien** : il vaut 1 sur 100 % des 60 606 pièces —
soit tout était soldé, soit c'est la valeur d'avant la surcouche. Les deux lectures
convergent avec la décision de classer payé.

## Pièges Dolibarr rencontrés

### P1. `purge.php product --confirm` détruit des produits de la boutique

`MigrationProduct` pose `ref_ext = 'SAGE:…'` sur les produits **adoptés**, c'est-à-dire ceux
que PrestaShop avait créés et que la reprise a complétés. Or la purge du socle supprime tout
ce qui porte ce marqueur, sans distinguer création et adoption.

En développement c'est sans conséquence. **En production, cette commande supprimerait des
produits de la boutique.** À traiter avant toute mise en production : soit distinguer les
adoptions par un second marqueur, soit refuser la purge hors développement.

Le script `supplierprice` ne souffre pas de ce défaut : il ne marque jamais une ligne
préexistante, et sa purge est donc bornée à ce qu'il a lui-même créé.

### P2. `llx_product_fournisseur_price_log` n'est jamais nettoyée

`remove_product_fournisseur_price()` supprime la ligne tarifaire, pas son journal, et aucune
API ne le permet. Chaque purge laisse donc des lignes de log orphelines — il y en avait déjà
quatre, issues d'essais manuels.

Sans gravité fonctionnelle, mais trompeur : `rowid` étant auto-incrémenté, un journal
orphelin peut se retrouver rattaché à une ligne tarifaire ultérieure sans rapport. À nettoyer
à la main en développement.

---

### P3. `Entrepot::create()` crée un entrepôt fermé

La méthode insère une ligne minimale puis appelle `update()`, qui écrit **sans condition**
`statut`, `warehouse_usage` et `fk_user_author` depuis les propriétés de l'objet. Les
laisser vides donne un entrepôt **fermé** malgré le `DEFAULT 1` de la colonne, d'usage `0`
— valeur qui n'est ni `USAGE_INTERNAL` ni `USAGE_EXTERNAL` —, et sans auteur.

C'est exactement l'état des trois entrepôts de démonstration présents en base. Le script
`warehouse` positionne les trois propriétés avant l'appel.

À l'inverse, `update()` écrit bien `import_key` : le marqueur de reprise est posé dès la
création, sans la seconde passe qu'impose `llx_product_fournisseur_price`.

### P4. L'unicité des entrepôts porte sur l'entité, pas sur le parent

`uk_entrepot_label (ref, entity)`. Un nom d'entrepôt est donc unique dans toute la base :
il est impossible d'avoir un `RANG-A` sous `ETAG-A` **et** sous `ETAG-B`. Toute
arborescence d'entrepôts doit porter des noms complets et uniques.

Sans conséquence sur cette reprise, les libellés de l'ancien ERP étant déjà positionnels
(`S1-A15-4`), mais structurant pour toute réorganisation ultérieure.

### P5. `correct_stock()` annonce un succès quand rien n'a été écrit

La méthode teste `if ($result >= 0)` (product.class.php:6243) et retourne `1`. Or
`MouvementStock::_create()` retourne **`0`** — et non un code négatif — lorsqu'il n'a rien
fait : produit inexistant, entrepôt vide, ou **produit que Dolibarr ne gère pas en stock**.

Trois articles de la source sont des services en cible : leur stock aurait disparu sans le
moindre message, avec un compteur annonçant un succès complet.

La méthode ne sait par ailleurs **pas dater un mouvement** — sa signature n'expose pas
`$datem`, contrairement à `_create()`.

**Le script appelle donc `_create()` directement**, teste `<= 0` et non `< 0`, et détecte les
services avant d'écrire.

### P6. `MouvementStock::delete()` ne recalcule pas le stock

C'est un `deleteCommon()` : il supprime la ligne d'historique **et rien d'autre**. Ni
`llx_product_stock.reel`, ni `llx_product.stock`, ni le coût moyen ne sont recalculés.

Supprimer un mouvement laisse donc le stock en place, privé de sa trace d'origine — le pire
des deux mondes. Toute annulation doit passer par une **contre-passation**, qui repasse par
`_create()` et laisse le coeur remettre les compteurs en état.

### P7. `llx_product_stock` porte un `import_key` qu'aucune classe n'écrit

La colonne existe, et l'index unique `(fk_product, fk_entrepot)` en ferait une clé
d'idempotence idéale. Mais `_create()` ne la touche pas, et **aucune classe du coeur n'a
`table_element = 'product_stock'`** — vérifié sur l'ensemble du code. Il n'existe pas d'objet
métier pour cette table.

L'écrire imposerait une requête directe. Le script se rabat sur `inventorycode`, prévu pour
cela par le coeur.


### P8. `Entrepot::delete()` emporte le stock sans le dire

On attend d'un ERP qu'il refuse de supprimer un entrepôt qui contient encore quelque chose.
**Dolibarr fait l'inverse** : `Entrepot::delete()` ne vérifie rien et supprime lui-même, dans
cet ordre (entrepot.class.php:458-481) :

```
DELETE FROM llx_product_batch    WHERE fk_product_stock IN (… de cet entrepôt)
DELETE FROM llx_stock_mouvement  WHERE fk_entrepot = …
DELETE FROM llx_product_stock    WHERE fk_entrepot = …
DELETE FROM llx_entrepot         WHERE rowid = …
```

Le stock disparaît, **et son historique de mouvements avec** — donc sans trace permettant de
savoir ce qui a été perdu, ni de le reconstituer. Aucun message, aucun code de retour négatif :
la suppression réussit.

Constaté en supprimant les 726 sous-entrepôts du modèle abandonné : douze unités d'un article
se sont évaporées. Elles subsistaient parce que la ligne source de cet article est à zéro, si
bien que la reprise du stock ne l'avait jamais lu et n'avait pas pu les rapatrier.

**Ce qu'on en fait.** Le rapport de `migrate.php stock` dénombre le stock resté hors de
l'entrepôt principal et nomme les entrepôts concernés ; `purge.php warehouse` ouvre sur
l'avertissement plutôt que sur le décompte. Lire l'un avant de lancer l'autre est la seule
protection : le coeur n'en offre aucune.

À noter que `llx_product.stock` reste cohérent après coup — il tombe à `NULL`, que Dolibarr
traite comme zéro. Un contrôle écrit `p.stock <> (SELECT SUM(reel) …)` ne remonte donc rien,
la comparaison avec `NULL` étant elle-même `NULL` : il faut un `COALESCE(p.stock, 0)` pour
que le contrôle ait un sens.


### P9. Un niveau de prix non renseigné facture zéro, sans avertissement

C'est le piège le plus coûteux de la série, parce qu'il ne produit **aucune erreur**.

`Product::fetch()` charge les niveaux un par un (product.class.php:3196-3217). Quand un
niveau n'a aucune ligne dans `llx_product_price`, il pose :

```php
$this->multiprices[$i] = $result ? $result["price"] : null;
```

`getSellPrice()` s'en sert **sans repli** (ligne 2469) :

```php
} elseif (getDolGlobalString('PRODUIT_MULTIPRICES') && !empty($thirdparty_buyer->price_level)) {
    $pu_ht = $this->multiprices[$thirdparty_buyer->price_level];
```

Un client rattaché à un niveau vide se voit donc proposer **0,00 €** sur chaque ligne de
devis, de commande ou de facture. Ni message, ni code de retour négatif : le document est
valide, simplement gratuit.

Constaté avant la reprise des tarifs : **146 498 clients étaient rattachés au niveau 2, qui
ne portait que cinq articles**. Toute la boutique aurait facturé zéro.

**Ce qu'on en fait.** `customerprice` écrit les huit niveaux pour tous les articles, sans
exception. Un article sans dérogation reçoit le prix de sa fiche. C'est ce qui explique le
volume — 127 000 lignes pour 15 900 articles — et il n'y a pas d'économie possible : le seul
moyen de ne pas remplir un niveau serait de n'y rattacher aucun client.


### P10. `updatePrice()` écrit le prix de base sans regarder le niveau

En multi-prix, `llx_product.price` finit par porter la valeur du **dernier niveau écrit**.
L'`UPDATE` de `updatePrice()` (product.class.php:2868) ne consulte jamais `$level` — la seule
condition `$level == 1` de la méthode porte sur l'autogénération, ligne 2784.

Le coeur se contredit lui-même, ce qui rend le défaut incontestable : `getSellPrice()`
retombe sur `$this->price` pour un tiers sans `price_level` (ligne 2468) et traite donc ce
champ comme le tarif par défaut. La boucle de saisie de la fiche produit finissant par le
niveau le plus élevé, **un client sans catégorie se voit appliquer le tarif le plus cher**.

Le module `aerotoolbox` corrige par un trigger `PRODUCT_PRICE_MODIFY` qui réaligne le champ
sur le niveau 1. `customerprice` s'appuie dessus, mais ne s'en remet pas à lui seul : il
**écrit les niveaux 2 à 8, puis le niveau 1 en dernier**, de sorte que le prix de base reste
juste même si le trigger venait à être désactivé. C'est ce champ que Prestasync publie vers
la boutique.

Une purge suivie d'un rejeu partiel laisse d'ailleurs la trace du défaut : sur l'instance de
travail, un essai manuel du 6 août avait posé un niveau 8 à zéro sur l'article `#00039`, dont
le prix de base est resté à 0,00 € pendant six jours.


### P11. `llx_product_price.import_key` existe, mais rien ne l'écrit

Même configuration que P7, sur une autre table. La colonne est déclarée — en `varchar(14)` —
mais `Product::_log_price()` (product.class.php:2332) ne la cite pas dans son `INSERT`.
`price_label` est bien écrite, mais elle s'affiche à l'écran des prix : en faire un marqueur
technique polluerait la fiche.

Faute de marqueur, `customerprice` porte son idempotence sur **la comparaison des valeurs**.
C'est d'ailleurs plus sûr qu'un marqueur : un prix corrigé à la main puis rejoué est remis à
sa valeur source, là où un marqueur aurait fait sauter la ligne.


### P12. `Reception::addline()` retourne un index, pas un code de retour

La méthode se termine par `return $num`, où `$num` est `count($this->lines)` **relevé avant
l'ajout** (reception.class.php:1038). La première ligne de chaque réception rend donc **0** —
qui est un succès.

Le réflexe `if ($result <= 0)` est ici un piège parfait : il rejette la première ligne de
chaque document, et l'objet ne porte aucun message puisqu'il n'y a pas d'erreur. Mesuré :
**16 réceptions en échec sur les 20 premières**, avec pour seul diagnostic « Erreur inconnue ».

Tester `< 0`. C'est le symétrique de P5, où `0` signalait au contraire un échec silencieux.


### P13. `addline()` empile, `addlinefree()` insère — et l'ordre est inverse

Deux méthodes de la même classe, aux contrats opposés :

| | `addline()` | `addlinefree()` |
|---|---|---|
| Effet | empile dans `$this->lines` | **insère en base** |
| Moment | **avant** `create()` | **après** `create()` |
| Pourquoi | `create()` insère la pile (l. 384-391) | a besoin de `$this->id` |
| Statut exigé | aucun | brouillon, que `create()` ne pose pas |

Créer d'abord puis empiler — l'ordre naturel — produit des réceptions **validées sans la
moindre ligne**, et aucune des deux méthodes ne proteste. Constaté : 4 réceptions à zéro ligne,
statut validé, avant que le contrôle en base ne le révèle.

Une réception qui mêle les deux natures de ligne impose donc de scinder le traitement de part
et d'autre de `create()`, et de repositionner `$this->status` à `STATUS_DRAFT` entre les deux.


### P14. `Reception::addline()` instancie une classe qu'elle n'inclut pas

Ligne 967, elle fait `new CommandeFournisseurDispatch(...)` sans le moindre `require_once`. Le
coeur compte sur l'écran appelant pour l'avoir chargée. En CLI, personne ne le fait : **erreur
fatale**, donc hors du bloc `try` et sans rollback — la transaction n'est annulée que par la
fermeture de la connexion.

Il faut inclure `fourn/class/fournisseur.commande.dispatch.class.php` soi-même. C'est le pendant
de ce qu'on a rencontré avec `date.lib.php`, que `master.inc.php` ne charge pas.


### P15. `llx_reception.ref_ext` existe, mais aucune méthode ne l'écrit

Troisième colonne du même acabit, après P7 et P11. `fetch()` sait pourtant lire par elle
(reception.class.php:517), mais ni `create()` ni `update()` ne la renseignent. `import_key` ne
peut pas la remplacer : ses 14 caractères ne suffisent pas à « SAGE:BLF990003143 ».

`CommonObject::setValueFrom()` est la sortie honorable — méthode générique du coeur, appelée
juste après la création. Le mécanisme d'idempotence du socle fonctionne ensuite sans surcharge.


### P16. `STOCK_CALCULATE_ON_RECEPTION_CLOSE` est forcée par le coeur

Elle vaut 1 sans figurer dans `llx_const`, et l'y écrire à 0 ne change rien. Dès que le module
lots/séries est actif et que `STOCK_CALCULATE_ON_RECEPTION` est vide, `Conf::setValues()` la
repose à 1 à **chaque chargement** (conf.class.php:963).

Conséquence pour la reprise : la validation d'une réception ne mouvemente rien, mais sa
**clôture** si. Les 2 848 réceptions reprises doivent donc rester au statut validé. Les clôturer
en masse depuis l'écran ajouterait 387 502 unités au stock d'ouverture.

Le script le rappelle à chaque passage plutôt que de compter sur la mémoire de qui le lance.


### P17. `CommandeFournisseurLigne::fetch()` lit deux colonnes qu'elle ne sélectionne pas

`$obj->subprice_ttc` (l. 198) et `$obj->multicurrency_subprice_ttc` (l. 257) sont absentes de sa
propre requête. Sans conséquence fonctionnelle — les propriétés restent nulles —, mais deux
avertissements PHP par ligne lue.

Sur une reprise de 22 849 lignes, cela fait **45 000 messages** qui noient la sortie et la
ralentissent. Lancer avec `php -d display_errors=0` ; le fichier de log reste exploitable.

### P18. `is_erasable()` a une porte de sortie que son refus le plus visible masque

Le refus connu est le -2 : *« cette facture n'est pas la dernière de sa séquence »*
(`core/class/commoninvoice.class.php:869`). Sur un parc de 182 000 documents, il rend toute
suppression ciblée impossible — et c'est ce qui avait été conclu en 0.12.5.

Mais la méthode **retourne 1 avant tout autre contrôle** quand deux conditions sont réunies
(ligne 827) :

```php
$tmppart = substr($this->ref, 1, 4);
if ($this->status == self::STATUS_DRAFT && $tmppart === 'PROV') { return 1; }
```

Brouillon **et** référence commençant par « PROV » : ni la place dans la séquence, ni l'envoi par
courriel, ni l'impression POS, ni la ventilation comptable ne sont alors examinés. C'est la porte
qu'emprunte Dolibarr lui-même pour un brouillon jamais validé.

**Deux détails la rendent utilisable, et l'un des deux est contre-intuitif :**

`setDraft()` ne touche **que** `fk_statut` — la référence y survit. Repasser une facture en
brouillon ne suffit donc pas : il faut réécrire la référence séparément, par `setValueFrom()`.
C'est aussi ce qui garantissait, en 0.12.5, qu'une correction en place ne renumérotait rien.

`substr($ref, 1, 4)` commence au deuxième caractère : la référence attendue est `(PROV…`, avec sa
parenthèse. `PROVDEL123` échouerait là où `(PROVDEL123)` passe.

Le suffixe est utile : trois références `(PROV…)` existent déjà en base — `(PROV103216)`,
`(PROV53556)`, `(PROV-POS1-0)` —, et `uk_facture_ref` est unique. `(PROVDEL<rowid>)` ne peut
heurter ni celles-là ni ses propres consœurs.

**Et un piège en sortie :** `delete()` supprime le répertoire de documents d'après
`$this->ref`, donc d'après la référence **temporaire**. Le dossier d'origine reste orphelin. Il
faut mémoriser la référence avant, et retirer le répertoire soi-même après — hors transaction, le
système de fichiers ne participant pas au `rollback`.

Employé par `scripts/delete_invoices_cancelled_orders.php`, qui a supprimé 311 factures sans
laisser une ligne, un lien ni un règlement orphelin.

### P19. `CAST(<entier> AS CHAR)` prend la collation de la CONNEXION, pas celle d'une table

Le piège le plus coûteux rencontré jusqu'ici, parce qu'il ne se manifeste **que sur certaines
installations** et qu'il désigne les données alors qu'elles sont innocentes.

Comparer une colonne `varchar` à une colonne entière suppose de convertir la seconde. Mais
`CAST(<entier> AS CHAR)` n'hérite d'aucune table : sa collation est celle de `collation_connection`.
Dès que cette collation diffère de celle des tables **tout en partageant leur jeu de caractères**,
MySQL refuse :

```
Illegal mix of collations (utf8mb3_general_ci,IMPLICIT)
                     and (utf8mb3_unicode_ci,IMPLICIT) for operation '='
```

**Le même SQL réussit ou échoue selon l'environnement, sans qu'aucune donnée ne change :**

| | Tables | Connexion | Résultat |
|---|---|---|---|
| Développement | `utf8mb4` | `utf8mb3` | **passe** — charsets différents, MySQL convertit vers le plus large |
| phpMyAdmin | `utf8mb3_general_ci` | `utf8mb4_unicode_ci` | **passe** — même raison |
| Dolibarr en ligne | `utf8mb3_general_ci` | `utf8mb3_unicode_ci` | **REFUS** — même charset, collations différentes |

C'est ce troisième cas qui bloquait `productkit` en 0.13.1. La conséquence pratique est
désorientante : la requête recopiée dans phpMyAdmin **rend le bon résultat**, ce qui donne à croire
que le script ne l'exécute pas telle quelle. Et trois `ALTER TABLE` successifs sur la table source
n'y changent rien — la collation fautive n'appartient à aucune table.

**Deux réflexes à retenir :**

Ne pas chercher dans `information_schema.COLUMNS`. Regarder d'abord
`SHOW VARIABLES LIKE 'collation_connection'`, et la comparer à `TABLE_COLLATION`. Si les deux
partagent le charset et diffèrent par la collation, la cause est là.

Ne jamais écrire de nom de collation en dur pour s'en sortir : il serait faux sur l'installation
suivante. `BINARY CAST(… AS CHAR)` place les deux membres hors de toute collation, sans rien
présumer de l'environnement — au prix d'une comparaison sensible à la casse, à vérifier sur les
données avant de l'adopter.

Employé par `MigrationProductKit::refExpr()`. Les autres scripts n'y sont pas exposés : ils
comparent des colonnes de même nature, jamais un entier à une chaîne.

### P20. Les modules se neutralisent en mémoire — et l'expédition a trois chausse-trapes

`isModEnabled()` ne lit pas la base : il lit `$conf->modules`, chargé au démarrage du
processus (functions.lib.php:465). Un script CLI peut donc retirer un module de SA
configuration — `unset($conf->modules['stock'])` — sans toucher à l'instance : c'est ce qui
permet à `shipment` de clôturer 58 000 expéditions sans un mouvement de stock, pendant que
l'application garde ses modules actifs. Aucun état à restaurer si le script meurt.

Trois comportements du coeur l'imposent, tous vérifiés dans `expedition.class.php` :

- `setClosed()` ne crée le mouvement que si `isModEnabled('stock') &&
  STOCK_CALCULATE_ON_SHIPMENT_CLOSE` (ligne 2923) — et cette constante est FORCÉE par le
  coeur dès que le module lots est actif, comme pour les réceptions (P16) ;
- `addline()` refuse tout article suivi par lot — `ADDLINE_WAS_CALLED_INSTEAD_OF_
  ADDLINEBATCH` (ligne 1276) — dès que `isModEnabled('productbatch')`, même sans stock ;
- `create()` éclate les produits composés en lignes de composants si `PRODUIT_SOUSPRODUITS`
  est posée (ligne 524).

**Effets de bord sur la commande d'origine, à défaire :** `valid()` la passe en « expédition
en cours » (ligne 1067) et `setClosed()` la clôture quand les quantités se recoupent (ligne
2915) — faux sur un historique dont `customerorder` a déjà posé les statuts d'après la
source. `shipment` relève le statut avant et le restaure après via `setStatut()`.

**Corollaire d'exploitation :** une expédition reprise clôturée doit LE RESTER. La rouvrir
depuis l'écran puis la reclôturer déclencherait cette fois la sortie de stock, l'application
ayant ses modules actifs.

**Effet de bord fonctionnel découvert sur les réceptions (0.20.0) :** la neutralisation ne
retire pas que des contrôles. `Reception::addline()` ne pose le produit sur la ligne empilée
QUE dans son bloc `isModEnabled('stock')` (reception.class.php:981) — module neutralisé,
`$line->fk_product` reste à zéro et `create()` refuse toutes les lignes (« property
->fk_product must not be empty »), 2 523 documents en échec au premier passage. Le script
pose donc le produit lui-même après l'appel. Avant d'étendre la neutralisation à un nouveau
script, relire CHAQUE bloc `isModEnabled('stock')` du chemin d'écriture : certains portent
des affectations, pas seulement des vérifications.

**Second effet découvert sur le stock (0.27.1) :** un produit peut devenir un KIT après sa
reprise — les nomenclatures aerotoolbox 1.13.0 sont arrivées après l'ouverture du stock.
Avec `PRODUIT_SOUSPRODUITS` active, `MouvementStock::_create()` ne bouge pas le stock d'un
parent de kit (`$movestock` reste à 0, mouvementstock.class.php:337) et déplace SES
COMPOSANTS à la place (ligne 672) : la contre-passation de `purge.php stock` échoue sans
message (« Erreur inconnue » — 25 refus constatés en ligne le 26/08/2026), et une ouverture
rejouée créditerait les composants en double. La photo ADD tenant le stock de chaque article
pour lui-même, `MigrationStock` neutralise la constante en mémoire, à la reprise comme à la
purge ciblée (`purgeEverything`, en SQL pur, n'est pas concernée).


## Tarifs clients (`z_tarifparticulier`)

Table applicative de l'ancien ERP, et non une table Sage. 27 591 lignes, 15 077 articles,
neuf catégories. Quatre de ses colonnes ont été écartées après vérification — savoir
pourquoi évite de vouloir les rétablir.

### Récapitulatif

| # | Constat | Volume | Décision |
|---|---|---:|---|
| T1 | La catégorie 0 n'est pas le prix public | 19 693 lignes | Écartée, `f_article` fait foi |
| T2 | `coeff` ne reconstitue aucun prix | 1 729 lignes | Ignorée |
| T3 | `statut` ne corrèle avec rien | 27 591 lignes | Ignorée |
| T4 | 61 règles par famille sans effet | 61 lignes | Écartées, comptées |
| T5 | Tarifs d'agence, nominatifs, paliers | 512 lignes | Écartés, comptés |
| T6 | Jusqu'à seize lignes pour un même couple | 940 couples | Départage documenté |
| T7 | Remises de −30 % à 100 % | 15 valeurs | Bornées et signalées |
| T9 | La catégorie Comptoir fusionne dans le site | 5 005 dérogations | Abandonnées, chiffrées (−1 689 € sur 2026) |

### T1. La catégorie 0 n'est pas le prix public

C'est le contresens qui coûterait le plus cher, parce qu'il est vraisemblable : une catégorie
« 0 » ressemble à un tarif général, et elle couvre 15 059 articles — presque le catalogue.

Elle coïncide avec `f_article.AR_PrixVen` sur **15 149 des 15 885 articles comparables**. Ce
sont les 736 écarts qui tranchent : **735 fois, c'est la fiche article qui a été modifiée en
dernier**.

```
f_article        : 15 238 lignes modifiées en 2026,    505 en 2025
z_tarifparticulier
  catégorie 0    :    570 lignes modifiées en 2026, 14 783 en 2023
```

La catégorie 0 est une strate figée en 2023 que l'exploitation a cessé d'entretenir.

**Décision.** Le prix de référence est `f_article.AR_PrixVen`, avec `AR_PrixTTC` pour la base
de saisie. C'est aussi la source de `MigrationProduct` : les deux scripts ne peuvent pas
diverger.

### T2. `coeff` ne reconstitue aucun prix

Le nom promet un multiplicateur. Vérification sur la catégorie Marché Enac, la seule où il
varie :

```
article   78  coeff 3,0159  coût std  2,99  →  9,02   tarif réel  9,50
article 2502  coeff 2,3174  coût std 11,20  → 25,95   tarif réel 29,95
article  438  coeff 2,3065  achat    12,00  → 27,68   tarif réel 18,25
```

Aucune combinaison avec le prix d'achat, le coût standard, la TVA ou le prix de vente ne le
retrouve. C'est un indicateur de marge, calculé et jamais réutilisé.

Sa distribution achève de le disqualifier : sur 27 591 lignes, **22 217 le laissent à zéro**
et 3 645 à exactement 1,000000. Seules 1 729 portent une autre valeur, et elles se concentrent
dans la catégorie 0, écartée par ailleurs.

**Décision.** Ignoré. Sur toutes les lignes concernées, `AR_PrixVen` est renseigné et suffit.

### T3. `statut` ne corrèle avec rien

Trois valeurs : `O` (19 639), `S` (2 581) et vide (5 371). Ni la péremption, ni la promotion,
ni le prix ne s'y rattachent — les lignes périmées se répartissent entre les trois.

Le filtre naturel, `statut = 'O'`, serait un désastre silencieux : **en catégorie Comptoir,
3 717 lignes sur 5 217 ont un statut vide**. Il en perdrait 71 %.

**Décision.** Ignoré. Seules les dates de validité départagent, et elles sont nettes :
3 452 lignes périmées, aucune postdatée.

### T4. Soixante et une règles par famille qui ne font rien

La catégorie FFA porte 61 lignes sans référence article, désignant chacune une famille de
catalogue. Toutes ont `remise = 0` et `coeff = 1` : elles n'appliquent aucune modification.

Sept règles seulement sont agissantes, en catégories Aéro-Clubs et Marché Enac :

```
Aéro-Clubs   famille  11   −5 %    1 943 articles
Marché Enac  famille   7  −20 %       44 articles
Marché Enac  famille  11   −9 %    1 943 articles
Marché Enac  famille  27   −9 %    3 651 articles
Marché Enac  familles 177, 186, 191   −9 %    44 articles
```

**Décision.** Les règles neutres sont écartées et comptées au rapport, pour que leur absence
dans le résultat ne passe pas pour un oubli. Un article relevant de plusieurs familles
remisées reçoit **la remise la plus forte** — un article porte jusqu'à quatre niveaux de
classification, et retenir la première trouvée ferait dépendre le prix de l'ordre des
colonnes `CL_No1..4`.

### T5. Trois dimensions sans équivalent en cible

| Motif | Lignes | Pourquoi |
|---|---:|---|
| `AG_No1 <> 0` | 509 | Tarif propre à une agence, notion absente de Dolibarr |
| `CT_Num` renseigné | 2 | Tarif nominatif : relèverait de `llx_product_customer_price` |
| `AR_aPartirDe > 1` | 1 | Palier de quantité, que le multi-prix ne porte pas |

Les tarifs nominatifs auraient pu justifier d'activer `PRODUIT_CUSTOMER_PRICES`. Deux lignes
ne le justifient pas.

### T6. Jusqu'à seize lignes en vigueur pour un même couple

940 couples (article, catégorie) ont plusieurs lignes valides simultanément en catégorie 0,
90 en catégorie Comptoir, et jusqu'à **seize** pour un seul article.

**Départage retenu**, dans cet ordre : le dépôt principal avant le dépôt générique — 52
articles portent les deux en catégorie Comptoir —, puis la date d'effet la plus récente, puis
le plus grand `cbMarq`. Le tri est fait par la base, une seule ligne est conservée par couple,
et le rapport dénombre les écartées.

### T7. Remises de −30 % à 100 %

La catégorie 0 porte quinze valeurs distinctes, dont une remise de **100 %** (deux lignes) et
une de **−30 %**. Une remise de 100 % donne un prix nul ; une remise négative est une
majoration.

**Décision.** Les deux sont appliquées telles quelles — la source les a voulues —, mais
bornées : au-delà de 100 %, le prix est nul, jamais négatif. Les deux cas sont comptés au
rapport. La catégorie 0 étant écartée (T1), aucun ne subsiste en pratique.

### T8. Les prix remisés sont arrondis au centime

L'ancien ERP ne stocke pas de prix remisé : il garde un taux et l'applique à la vente. Le
multi-prix, lui, exige une valeur figée. 22,20 € moins 9 % donne 20,202 € — un prix catalogue
à trois décimales, que la fiche affiche « 20,20 » tout en facturant 20,202.

**Décision.** Le prix issu d'une remise est arrondi au centime, dans sa base de saisie. La
grille tarifaire étant éditable à l'écran, une valeur non arrondie y serait « corrigée » au
premier passage, créant une divergence que rien ne signalerait. Les prix fixes, eux, ne sont
jamais retouchés.

### T9. La catégorie Comptoir fusionne dans le tarif du site (0.28.0)

Demande client du 21/08/2026 : « le prix comptoir n'existe plus vraiment ». Le constat
technique le confirme — la caisse vend déjà au tarif par défaut (client TakePOS générique
sans niveau, repli niveau 1) et la boutique publie `llx_product.price`. La grille passe de
huit à sept niveaux : catégories 1 et 2 → niveau 1, les six autres décalées d'un cran.

**Ce qui disparaît.** Les tarifs saisis en catégorie 1 ne nourrissent plus aucun niveau —
5 174 lignes valides au 30/08, soit **5 005 dérogations** (prix comptoir ≠ site, 4 982 à la
baisse). Chiffrage remis au client (`rapports/derogations_comptoir_20260830.csv`) : 1 820
sur des produits encore en vente, mais **352 seulement vendues au comptoir en 2026**
(683 unités), pour **−1 689 €** d'écart cumulé face au tarif site. L'abandon est quasi
indolore — et irréversible côté Dolibarr, la reprise ne relisant plus ces lignes.

**Un SQL direct assumé.** `scripts/merge_price_levels.php` supprime les lignes de prix du
niveau 8 (≈ 15 922) : illisibles dès que la limite vaut 7 — `Product::fetch()` ne lit
jamais au-delà — et le coeur n'offre aucune méthode pour retirer un niveau entier. Remise
en cohérence d'environnement, même statut que la purge de `customerprice`. Le même script
décale les tiers nés dans la boutique (~124), que `pricelevel` ne visite pas.


## Tiers (`f_comptet`)

### Récapitulatif

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
| 14b | Code postal incohérent avec la ville | ~170 estimés | Repris tel quel |
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

## Contacts (`f_contactt`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| C1 | Contacts qui répètent le nom de leur tiers | 124 273 / 155 368 | Repris quand même |
| C2 | `CT_Civilite` n'est pas une civilité | 155 369 | Civilité déduite du tiers |
| C3 | Coordonnées et adresse absentes du contact | 43 tél. / 155 369 | Reprises du tiers |
| C4 | Nom identique au prénom | 507 | Prénom vidé |
| C5 | Contact rattaché à un tiers inexistant | 1 | Refusé et signalé |
| C6 | Retour à la ligne dans un nom | 1 | Espaces normalisés |
| C7 | Désinscription newsletter | 2 621 tiers | Reprise via `Societe::setNoEmail()` |
| C8 | Contacts sans nom ni prénom | 957 | Écartés et signalés |

### C1. Des contacts qui doublonnent leur tiers

**124 273 contacts sur 155 368 portent exactement le nom de leur société.** C'est logique :
chez un particulier, le « contact » Sage *est* le client. Seuls 31 095 apportent une
information (nom différent, ou coordonnée propre).

Le déséquilibre est net selon la nature du tiers : 123 802 doublons sur 147 487 contacts de
particuliers (84 %), contre 478 sur 7 881 pour les personnes morales (6 %).

**Décision : reprise de la totalité**, fidélité à la source.

### C2. `CT_Civilite` n'est pas une civilité

Malgré son nom, la colonne classe le **genre et la nature du tiers**, pas la civilité :

| Valeur | Signification déduite | Volume |
|---:|---|---:|
| 0 | masculin, ou non renseigné | 109 794 |
| 1 | féminin | 39 028 |
| 2 | personne morale | 6 521 |
| 3 | indéterminé | 26 |

Elle est inexploitable telle quelle : parmi les 0 figurent 1 315 « Madame », 1 206 « Mme » et
344 « Mademoiselle ». S'en servir aurait adressé environ 2 865 clientes en « Monsieur ».

**Décision : la civilité est déduite de `CT_Qualite` du tiers** (Monsieur → `MR`,
Madame/Mme → `MME`, Mademoiselle → `MLE`), seul champ fiable. Les personnes morales n'en
reçoivent aucune.

### C3. Des contacts sans coordonnées ni adresse

Sur 155 369 contacts : 54 e-mails, 43 téléphones, 23 fonctions, 1 fax. Et surtout,
`f_contactt` **ne comporte aucune colonne d'adresse** — ni rue, ni code postal, ni ville.
Toutes ces informations ne vivent que sur le tiers.

**Décision : les coordonnées et l'adresse du tiers sont reportées sur le contact**, sans
quoi la quasi-totalité des fiches contact serait vide. Ce qui existe en propre sur le
contact reste prioritaire.

Ces valeurs sont relues sur le **tiers Dolibarr déjà migré**, et non sur la source : son
adresse, son pays et son département y sont déjà normalisés. Cela évite de dupliquer dans
le script contacts la table de correspondance des pays et la déduction du département, et
garantit que les deux fiches portent la même information.

Contrepartie assumée : la donnée est dupliquée et ne suivra pas un changement d'adresse
ultérieur du tiers.

### C4. Nom identique au prénom

507 contacts portent la même valeur dans les deux champs, souvent une saisie dégradée
(`#`, `a`, `00225 05 934 338`). **Décision : seul le nom est conservé**, le prénom est vidé.

### C8. Contacts sans nom ni prénom

957 lignes de `f_contactt` n'ont ni `CT_Nom` ni `CT_Prenom`. Ce sont des coquilles créées
dans l'ancien ERP puis jamais renseignées — rattachées, elles, à des tiers parfaitement
valides (`ÉDITIONS JPO`, `DIMATEX Sécurité`…).

Elles sont pour l'essentiel vides de bout en bout :

| | Nombre |
|---|---:|
| Sans identité | 956 (+1 contact orphelin, voir C5) |
| dont avec un e-mail | 13 |
| dont avec un téléphone | 11 |
| dont avec une fonction | 4 |
| **dont totalement vides** | **~930** |

Dolibarr exige un nom pour créer un contact. **Décision : ces lignes sont écartées** et
listées en fin de passage. Les reprendre en leur donnant le nom de leur tiers créerait 930
fiches sans le moindre contenu, qui encombreraient les listes et les sélecteurs de contact
pour rien.

Conséquence assumée : les quelque 25 lignes qui portent un e-mail ou un téléphone ne sont
pas reprises non plus. Elles restent identifiables dans la source si le besoin se
manifeste :

```sql
SELECT CT_No, CT_Num, CT_EMail, CT_Telephone, CT_TelPortable, CT_Fonction
FROM f_contactt
WHERE TRIM(COALESCE(CT_Nom,'')) = '' AND TRIM(COALESCE(CT_Prenom,'')) = ''
  AND (TRIM(COALESCE(CT_EMail,'')) <> '' OR TRIM(COALESCE(CT_Telephone,'')) <> ''
       OR TRIM(COALESCE(CT_TelPortable,'')) <> '' OR TRIM(COALESCE(CT_Fonction,'')) <> '');
```

### C7. Désinscription newsletter

**Traité par le script `newsletter`.** 2 621 tiers portent `Unsubscribe_Newsletter = 1`,
dont 2 617 avec une adresse e-mail — les 4 autres ne sont pas reprenables, faute d'adresse.

Dolibarr ne stocke pas cette information sur la fiche : la colonne `no_email` de
`llx_socpeople` est marquée « no more used » dans le coeur, et `Contact::setNoEmail()` ne
fait rien lorsque le contact n'a pas d'adresse — ce qui était le cas de 155 315 contacts
sur 155 369. La désinscription est en réalité une simple liste d'adresses,
`llx_mailing_unsubscribe`, consultée au moment des envois en masse.

La reprise passe donc par **`Societe::setNoEmail()`** (societe.class.php:4576), qui vérifie
l'existence de l'adresse avant de l'insérer : le script est naturellement rejouable.

**Conséquence à connaître : c'est l'ADRESSE qui est désinscrite, pas le tiers.** Or 9
adresses de la source sont partagées entre un tiers désinscrit et un tiers qui ne l'est
pas. Les désinscrire les retire des envois pour les deux. C'est le fonctionnement de
Dolibarr, pas un défaut de la reprise, mais il faut le savoir avant la première campagne.

### C7bis. Note historique

`Unsubscribe_Newsletter` (2 621 tiers) n'a pas trouvé sa place. Dolibarr ne stocke plus
cette information sur le contact : la colonne `no_email` de `llx_socpeople` est annotée
« no more used » dans le cœur, et `Contact::setNoEmail()` alimente en réalité
`llx_mailing_unsubscribe` — une simple liste d'adresses. Or cette méthode **ne fait rien si
le contact n'a pas d'e-mail**, ce qui est le cas de 155 315 des 155 369 contacts.

L'adresse étant portée par le tiers, la reprise consisterait à insérer les e-mails des
2 621 tiers désinscrits directement dans `llx_mailing_unsubscribe`. **À traiter à part.**

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

### 14b. Codes postaux incohérents avec la ville

Certaines adresses associent un code postal et une ville qui ne correspondent pas. Le cas
repéré à l'œil nu : le tiers `91` porte `31000 MONTAUBAN`, alors que 31000 est Toulouse et
Montauban 82000.

Faute de référentiel officiel code postal / commune, le volume a été estimé en repérant
les villes rattachées à plusieurs départements et en isolant les rattachements marginaux
(moins de 5 % des occurrences d'une ville apparaissant au moins 20 fois) : **environ 170
lignes**, sur 960 villes concernées par au moins deux départements.

Exemples : `TOULOUSE` apparaît 4 464 fois, dont une fois en `33`, une en `66`, une en `69`
et deux en `75` ; `PARIS` apparaît 4 301 fois, dont six en `92` et deux en `44`.

L'estimation est prudente et à prendre comme un ordre de grandeur : des homonymes
légitimes existent (plusieurs Saint-Denis, Sainte-Marie…), et à l'inverse une erreur qui
resterait dans le bon département passerait inaperçue.

**Décision : repris tel quel**, conformément à la règle retenue pour les téléphones et
les e-mails — la source fait foi.

**Conséquence à connaître :** le département étant déduit du code postal, ces adresses
reçoivent un département cohérent avec le code postal mais faux au regard de la ville. Le
contact hérite du même défaut, puisqu'il reprend l'adresse du tiers.

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

Deux pièges Dolibarr sur ce champ :

- `price_level` n'est écrit ni par `create()` ni par `update()`. Il faut passer par
  `setPriceLevel()`, qui journalise chaque changement dans `llx_societe_prices` — la
  reprise ne l'appelle donc que si le niveau change réellement.
- **`Societe::fetch()` ne restitue pas la valeur brute** : lorsque le multiprix est actif
  (`PRODUIT_MULTIPRICES`) et que la colonne est vide, il retourne `1` par défaut
  (societe.class.php:2231). Tester `$societe->price_level` après un `fetch()` laisse donc
  croire que toute fiche possède déjà un niveau, et la reprise n'en pose jamais. Le
  script relit la valeur directement en base pour décider.

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
| `id_externe` | 155 562 | **identifiant du client dans la boutique en ligne** — voir §31 |
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

### 31. `id_externe` et le rapprochement avec la boutique en ligne

**Correction d'une analyse initiale erronée** : `id_externe` avait d'abord été écarté comme
une simple recopie de `CT_Num`. C'est faux — seules 110 458 valeurs sur 157 102 coïncident,
les fournisseurs perdant par exemple leur préfixe (`F128` → `128`).

`id_externe` est en réalité l'**identifiant du client dans PrestaShop**. C'est la clé de
rapprochement avec les tiers déjà présents dans Dolibarr, via la table
`llx_prestasync_customer` du module Prestasync :

| Colonne | Rôle |
|---|---|
| `fk_soc_doli` | rowid du tiers Dolibarr |
| `fk_customer_presta` | identifiant client PrestaShop = `f_comptet.id_externe` |

Recouvrement vérifié : 20 176 des 20 487 liens retrouvent un tiers dans `f_comptet`
(98,5 %).

**Trois pièges de cette table :**

- Rien n'impose qu'un tiers n'ait qu'un seul lien : l'index sur `fk_soc_doli` n'est pas
  unique et la contrainte `uk_prestasync_customer_fieldp` porte sur le **triplet**
  `(fk_presta, fk_soc_doli, fk_customer_presta)`. Indexer les liens par `fk_soc_doli` fait
  donc silencieusement perdre des correspondances — erreur commise puis corrigée pendant
  le développement. L'index doit aller de l'identifiant boutique vers le tiers.
- **313 valeurs d'`id_externe` sont partagées** par plusieurs tiers Sage (dont `0`, présent
  56 fois). Un identifiant boutique ne pouvant désigner qu'un seul tiers, les doublons sont
  écartés du lien et comptés dans le rapport de fin de passage.
- 1 540 tiers ont un `id_externe` vide ou égal à `'0'` : aucun rapprochement possible, ils
  sont créés normalement et sans lien.

**Pourquoi la reprise alimente cette table.** Le module de synchronisation **ne recherche
aucun tiers existant** avant d'en créer un — choix de sécurité assumé et commenté dans son
code (`prestaCustomer.class.php:270`), l'option de rapprochement par e-mail
(`PRESTASYNC_LINK_CUSTOMER_BY_EMAIL`) étant inactive. Sans lien enregistré, la première
commande d'un client repris ferait donc créer un second tiers. La reprise insère le lien
pour chaque tiers créé disposant d'un `id_externe` exploitable.

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
