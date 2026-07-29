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

La preuve tient dans la marge, mesurée sur ces 2 767 articles :

| Coût retenu | Coefficient moyen prix de vente / coût |
|---|---|
| Prix d'achat fournisseur | **1,84** — une marge commerciale plausible |
| `AR_PrixRU` | 14 489 208 — absurde |

Le coût standard est aussi le plus propre des deux : **14 906 valeurs exploitables contre
13 152**, et **aucune sous le centime contre 1 628**. Les 1 628 valeurs aberrantes du prix
de revient — jusqu'à `0,000001 €` pour un article à `4,19 €` — disparaissent d'elles-mêmes
du calcul.

Le prix de revient sert de repli pour les **14 articles** sans coût standard exploitable.
**837 articles** n'ont de valeur dans aucune des deux colonnes et restent sans coût.

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
| F7 | `AF_Remise` sans `AF_TypeRem` | 58 | Repris en pourcentage, à valider |
| F8 | Tabulations dans les références | 6 | Nettoyées |

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

### F7. `AF_Remise` sans `AF_TypeRem`

58 lignes portent une remise, valeurs de 2 à 33,5 — cohérentes avec des pourcentages. Mais
**`AF_TypeRem` est NULL sur les 15 962 lignes** : rien ne prouve qu'il s'agisse d'un taux
plutôt que d'un montant.

Repris en pourcentage, et listé au rapport pour validation client. Le risque est borné :
Dolibarr n'en déduit pas le prix unitaire, la remise ne joue que sur les lignes de commande
fournisseur.

### F8. Tabulations dans les références

Six références portent une tabulation en tête ou en fin — `\t9782373011173`, `52641\t` —,
et huit autres des espaces de bord.

Anecdotique en soi, structurant pour le code : **`TRIM()` de MySQL ne retire pas les
tabulations**, contrairement à `trim()` de PHP. Une carte des collisions construite par
agrégation SQL n'aurait donc pas coïncidé avec les clés calculées à l'exécution, et ces
lignes seraient passées à travers la détection. Le nettoyage est fait par une fonction PHP
unique, autorité sur la forme d'une référence en préparation comme à l'écriture.

### F9. Colonnes sans emploi

`AF_TypeRem`, `AF_Garantie` et `EG_Champ` sont NULL sur les 15 962 lignes. `AF_Unite` ne
vaut `1` que sur 710 lignes, `AF_Conversion` et `AF_ConvDiv` sont entièrement nulles.
`designation_fournisseur` n'est renseignée que 206 fois, `AF_DelaiAppro` 14 fois,
`AF_CodeBarre` 4 fois. Les colonnes de tarif futur (`AF_PrixAchNouv`, `AF_RemiseNouv`,
`AF_DateApplication`) ne sont pas reprises : Dolibarr n'a pas de notion équivalente.

---

## Emplacements de stock (`f_emplacements`)

| # | Anomalie | Volume | Décision |
|---|---|---:|---|
| E1 | Libellés absents de la source | 819 numéros | Extraits du HTML de l'ancien ERP |
| E2 | Un même libellé pour plusieurs emplacements | 70 libellés | Fusionnés |
| E3 | Emplacements sans libellé | 3 occupés | Nommés d'après leur numéro |
| E4 | Libellés fantaisistes | 6 | Repris tels quels |
| E5 | Emplacements supprimés dans l'ancien ERP | 8 (114 articles) | Entrepôt de repli « À localiser » |
| E7 | Un seul emplacement par article dans la source | 27 670 lignes | Sans objet : Dolibarr en autorise autant qu'on veut |

### E1. Les libellés n'étaient nulle part dans les données

`f_artstock.DP_NoPrincipal` ne porte qu'un numéro. La table de correspondance de Sage
(`F_DEPOTEMPL`) **n'a pas été répliquée** dans la base livrée — vérifié par balayage de
l'intégralité du schéma. Aucune autre colonne ne contient d'emplacement : les 20
`champ_libre` de `f_article` sont vides, comme `f_catalogue.CL_Emplacement`, pourtant
prometteur par son nom.

L'export CSV de l'ancien ERP donne les libellés mais **pas les identifiants**. Ceux-ci ont
finalement été trouvés dans le **code HTML de l'interface**, portés par l'attribut
`data-dp-no` des cases à cocher. D'où la table `f_emplacements` (1 006 lignes), seule table
de ce jeu qui ne vienne pas de Sage, livrée avec son script dans `data/f_emplacements.sql`.

Couverture : **810 des 819 numéros utilisés (98,9 %)**.

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

### E5. Emplacements supprimés dans l'ancien ERP

Huit numéros encore portés par des articles sont absents de l'extraction : **605 à 611, et
639**, pour 114 articles.

**Ce ne sont pas des trous d'extraction.** Leurs voisins immédiats sont bien présents —
604 `R14`, 612 `COMPLEMENT AUX CARTES AERO`, 638 `S1-A12-2`, 640 `T8`. Il ne s'agit donc
pas d'une page manquée à la copie mais de **trous dans la séquence** : ces emplacements ont
été supprimés dans l'ancien ERP sans que leur contenu soit réaffecté. Le numéro 631 manque
également, simplement plus aucun article ne le référence.

L'impact réel est faible : sur les 114 articles, **99 ont un stock à zéro**. Seuls **15
portent réellement du stock**, pour 161 unités, dont une ligne à −12.

| Numéro | Articles | Avec du stock | Quantité |
|---:|---:|---:|---:|
| 605 | 23 | 5 | 29 |
| 606 | 10 | 0 | 0 |
| 607 | 12 | 2 | 8 |
| 608 | 6 | 1 | 48 |
| 609 | 20 | 3 | 30 |
| 610 | 13 | 1 | −12 |
| 611 | 22 | 2 | 36 |
| 639 | 8 | 1 | 22 |

**Décision : un entrepôt de repli « À localiser »**, créé sous l'entrepôt principal et
marqué `SAGE:ORPHELIN`. Les regrouper là plutôt que de les mêler à l'entrepôt principal les
rend identifiables d'un coup d'oeil — c'est tout l'intérêt, leur localisation physique étant
à retrouver.

Le libellé est une consigne et non un constat, et son accent initial le fait remonter en
tête des sélecteurs d'entrepôt : il restera visible tant que le rangement n'aura pas été
fait. Sa description porte la liste des numéros concernés.

À ne pas confondre avec deux situations voisines, volontairement traitées ailleurs : les
trois emplacements **sans libellé** (E3), qui existent toujours dans l'ancien ERP et
redeviendront exploitables si quelqu'un les y nomme ; et les quelque 2 000 lignes **sans
aucun emplacement** (E6), qui vont dans l'entrepôt principal — un article jamais rangé n'est
pas une anomalie de données, et les mêler noierait les 15 vrais cas.

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

**Décision : ignorée.** La valorisation se calcule.

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

---

## Documents commerciaux (`f_doc*`) — analyse préalable, non repris

Relevé du 29/07/2026, avant toute décision de reprise. Quatre tables :
`f_docentete_global` (318 030), `f_docligne_global` (1 039 279),
`f_docexpedition_global` (61 156) et `f_docligne` (243).

### D1. Ce que contiennent les documents

Les documents sont typés par le couple `DO_Domaine` / `DO_Type` :

| Domaine | Type | Document | Volume | Période |
|---|---|---|---:|---|
| 0 | 0 | Devis | 1 433 | 2019-2026 |
| 0 | 1 | Commande client | 60 936 | 2019-2026 |
| 0 | 2 | Préparation de livraison | 61 377 | 2019-2026 |
| 0 | 3 | Bon de livraison | **314** | 2019-2026 |
| 0 | 6 | Facture | 31 168 | 2019-2026 |
| 0 | 7 | Facture comptabilisée | 149 943 | **2015-2024** |
| 1 | 12 | Commande fournisseur | 2 567 | 2019-2026 |
| 1 | 13 | Bon de réception | 3 116 | 2019-2026 |
| 1 | 16 | Facture fournisseur | 2 113 | 2019-2026 |
| 2 | 20 / 21 | Entrée / sortie de stock | 5 054 | → 2026 |

Les lignes portent article, désignation, quantité, prix unitaire HT, taux de TVA et
jusqu'à trois remises. Sur 2025-2026 seulement : **25 137 factures, 2 431 196 € HT**.

### D2. Les rattachements sont excellents

| | |
|---|---|
| Documents de vente dont le tiers existe en cible | **304 783 / 305 171 (99,9 %)** |
| Documents d'achat dont le fournisseur existe | **7 805 / 7 805 (100 %)** |
| Lignes de facture rattachées à un produit | **500 357 / 519 890 (96 %)** |

Les 19 295 lignes sans référence sont des lignes de texte libre, que Dolibarr sait
représenter.

Taux de TVA rencontrés : 20 % (343 748 lignes), 5,5 % (135 663), 0 % (35 596), plus
quelques 5 % et 6 % résiduels.

### D3. Une coupure d'archivage fin 2019

Avant octobre 2019, il ne subsiste **que des factures comptabilisées** : devis, commandes,
préparations et réceptions ont été purgés. Les factures comptabilisées s'arrêtent elles-mêmes
en septembre 2024, quand les factures « vivantes » prennent le relais — **aucun recouvrement
entre les deux**, c'est une bascule propre, pas un doublon.

### D4. Aucun règlement n'est enregistré

`reglement_1`, `reglement_2` et `reglement_3` sont **vides sur les 181 111 factures**.

**C'est le point le plus bloquant** : rien ne dit ce qui est payé. Reprises telles quelles,
toutes les factures arriveraient **impayées** dans Dolibarr, ce qui fausserait entièrement
les comptes clients et les relances.

### D5. Le flux s'arrête à la préparation

**314 bons de livraison pour 61 377 préparations.** La sortie de stock se fait à la
facturation, pas à la livraison (confirmé par la reconstitution du stock, voir S9).
Reprendre des expéditions Dolibarr n'aurait donc guère de sens.

Le chaînage vers la commande d'origine existe sur **200 717 lignes de facture sur 519 890**
(39 %), via `DL_PieceBC`.

### D6. `f_docexpedition_global` est presque vide

61 156 lignes, mais **aucune URL de suivi**, aucun e-mail, aucun commentaire, aucun message
cadeau. Seuls **14 247 identifiants de point relais** et 2 627 marqueurs d'expédition sont
renseignés. Les colonnes de statut de préparation sont à zéro sur la totalité.

### D7. `f_docligne` est un résidu

243 lignes, dont **150 dupliquent `f_docligne_global`**. Table de travail, sans valeur.

### Ce qui vaudrait la peine, le jour venu

**À reprendre** : les factures depuis 2019 (31 168) avec leurs lignes, pour l'historique
commercial consultable. Éventuellement les commandes clients de la même période.

**À écarter** : les 149 943 factures comptabilisées antérieures à 2024 (volume énorme,
valeur documentaire seulement), les préparations, les bons de livraison, et
`f_docexpedition_global`.

**À trancher avec le client avant de commencer** : à quoi doit servir cet historique ?
Consulter le passé d'un client, ou disposer d'une comptabilité complète ? Dans le second
cas, l'absence de règlements est rédhibitoire — il faudra les récupérer ailleurs, n'importer
que des factures soldées, ou renoncer.

---

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
