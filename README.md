# AeroMigration

Module Dolibarr technique hébergeant les scripts de reprise de données de l'ancien ERP
(Sage 100) vers Dolibarr.

## Principe

Les scripts **n'écrivent jamais en SQL direct** dans les tables `llx_*`. Ils instancient
les objets métier Dolibarr et appellent leurs méthodes :

```php
$societe = new Societe($db);
$societe->name = ...;
$societe->create($user);
```

On conserve ainsi les règles de gestion, les extrafields, les modules de numérotation et
les triggers du coeur. Les tables sources `f_*` ne sont lues qu'en lecture seule.

## Ordre des reprises

L'ordre n'est pas indifférent : chaque script s'appuie sur les objets posés par les
précédents, qu'il retrouve par leur `ref_ext`.

```
php migrate.php thirdparty     tiers
php migrate.php contact        contacts        → rattachés aux tiers
php migrate.php newsletter     désinscriptions → enrichit les tiers
php migrate.php category       catalogue
php migrate.php product        articles        → rattachés aux catégories
php migrate.php supplierprice  tarifs fournisseurs → relient articles et tiers
php migrate.php warehouse      dépôts → entrepôts
php migrate.php location       emplacements → dictionnaire d'aerotoolbox
php migrate.php stock          stocks → dans l'entrepôt du dépôt
php migrate.php productlocation emplacement de rangement → fiche produit
php migrate.php supplierorder  commandes fournisseur → relient tiers et articles
php migrate.php customerorder  commandes clients → adopte celles de la boutique
```

### Deux bases sources

L'éditeur a livré son dossier entier — 347 tables — chargé dans une base à part par
[scripts/import_add_csv.php](scripts/import_add_csv.php). Les scripts qui la lisent portent
`$sourceDb`, que l'option **`--source-db=NOM`** permet de changer.

`warehouse`, `location`, `stock` et `productlocation` s'y appuient. Les autres lisent encore
les tables importées à côté des `llx_*` : l'écart n'est pas anodin — sur `f_artstock`, 1 401
quantités diffèrent et 97 articles s'ajoutent — mais les basculer demande de les éprouver un
par un.

**Quand l'hébergement n'autorise qu'une base**, ce qui est le cas sous Plesk, les tables de
l'ancien ERP sont importées dans celle de Dolibarr. Aucune ne porte le préfixe `llx_`, la
cohabitation est donc sans risque. Il faut alors le dire aux scripts, avec une option **sans
valeur** :

```
php migrate.php stock --source-db=
```

### L'emplacement n'est pas un entrepôt

Une première version créait un sous-entrepôt par emplacement de rangement : sept cent
dix-neuf pour un dépôt réel. L'entrepôt dit **combien**, l'emplacement dit **où** ; les deux
sont désormais séparés. Les emplacements vivent dans le dictionnaire
`c_aerotoolbox_location` du module `aerotoolbox`, et sur un champ de la fiche produit.

Sur une instance reprise avec l'ancien modèle :

```
migrate.php warehouse                    # adopte le dépôt, ne crée plus de sous-entrepôt
migrate.php location                     # remplit le dictionnaire
migrate.php stock                        # rapatrie le stock dans le dépôt
purge.php warehouse --legacy --confirm   # supprime les vestiges
migrate.php productlocation              # range les produits
```

**Lisez le bloc « Stock resté ailleurs » du rapport de `stock` avant la purge.**
`Entrepot::delete()` supprime le stock et les mouvements de l'entrepôt sans avertir — voir
P8 dans [ANOMALIES.md](ANOMALIES.md).

| Script | Dépend de | Ce qui se passe s'il est lancé trop tôt |
|---|---|---|
| `thirdparty` | — | |
| `contact` | `thirdparty` | s'arrête avec un message explicite |
| `newsletter` | `thirdparty` | s'arrête avec un message explicite |
| `category` | — | |
| `product` | `category` | les articles sont créés **sans classement**, et le rapport le signale |
| `supplierprice` | `thirdparty`, `product` | les lignes sans article ni tiers repris sont ignorées, et le rapport les dénombre |
| `warehouse` | — | s'arrête si le dépôt principal est absent de `f_depot` |
| `location` | `warehouse` | s'arrête si l'entrepôt marqué `SAGE:DEPOT1` est absent |
| `stock` | `product`, `warehouse` | s'arrête si l'entrepôt principal est absent ; les lignes sans article repris sont ignorées et dénombrées |
| `productlocation` | `product`, `location` | s'arrête si le dictionnaire est vide ; les produits non repris sont ignorés et dénombrés |
| `supplierorder` | `thirdparty`, `product` | les commandes dont le fournisseur n'est pas repris sont écartées et dénombrées ; les lignes sans article deviennent du texte libre |
| `customerorder` | `thirdparty`, `product` | idem, et les commandes déjà créées par la boutique sont adoptées au lieu d'être recréées |

Les deux derniers restent tolérants. Un article sans catégorie demeure un article valide, et
l'on peut vouloir reprendre les produits seuls ; le rapport indique alors clairement
qu'aucune catégorie n'a été trouvée. De même, un tarif dont l'article n'est pas encore repris
n'est pas une erreur mais une ligne à repasser plus tard : le script la compte, la signale et
poursuit. Il est rejouable autant de fois que nécessaire.

## Lancer une reprise

Toujours en ligne de commande, jamais depuis le navigateur : les volumes dépassent
largement les limites d'une requête web. En CLI, `max_execution_time` vaut 0, il n'y a donc
aucun délai maximal d'exécution à craindre.

```
php -d memory_limit=512M migrate.php thirdparty
php -d memory_limit=512M migrate.php contact
```

**Relever `memory_limit` est nécessaire**, pas optionnel. Les scripts chargent leurs
référentiels en mémoire une fois pour toutes, plutôt que d'interroger la base à chaque
ligne — ce qui fait la différence entre quelques minutes et plusieurs heures. L'index des
contacts (155 000 tiers avec leurs coordonnées) pèse à lui seul environ 128 Mo, soit la
valeur par défaut de `memory_limit` : sans ce réglage, la reprise des contacts s'arrête sur
un `Allowed memory size exhausted`.

L'option `-d` ne vaut que pour l'exécution en cours : ni le `php.ini` ni le PHP d'Apache ne
sont modifiés.

Une reprise interrompue se reprend sans dommage : chaque ligne est écrite dans sa propre
transaction, et les enregistrements déjà repris sont reconnus à leur `ref_ext`. Relancer
la même commande suffit, l'option `--cursor` permettant d'aller plus vite si l'on a noté
la dernière valeur affichée.

L'option `--filter` restreint la lecture à un sous-ensemble, sans jamais l'élargir : la
condition est ajoutée à celle du script. Elle sert aux rattrapages ciblés, et surtout à
reprendre d'une instance à l'autre exactement le même échantillon, seul moyen de comparer
une reprise locale à une reprise en ligne client par client.

```
php migrate.php customerorder --dry-run --filter="DO_Tiers IN ('100568','110441')"
```

## Rapprochement avec la boutique en ligne

La cible n'est pas vierge : le module Prestasync y crée des tiers venus de PrestaShop. La
reprise des tiers en tient compte de deux façons.

**Adoption.** Un tiers déjà présent est reconnu via `llx_prestasync_customer`
(`fk_customer_presta` = `f_comptet.id_externe`). Il n'est pas recréé : la reprise ne
remplit que les champs laissés vides et pose son `ref_ext`. Son nom, son code client et son
statut ne sont jamais modifiés.

**Déclaration des nouveaux tiers.** Chaque tiers créé est inscrit dans
`llx_prestasync_customer`, sans quoi la première commande du client sur la boutique ferait
créer un doublon — le module ne recherche aucun tiers existant avant d'en créer un.

C'est la **seule écriture SQL directe** du module, à rebours de la règle générale. Elle est
assumée : `PrestaCustomer::setCustomDolLink()` n'exécute qu'un `INSERT` sans traitement
annexe, et passer par la classe imposerait d'ouvrir une connexion webservice vers la
boutique pour chacun des ~135 000 tiers.

## Les commandes clients, quand la boutique les a déjà créées

Prestasync crée dans Dolibarr les commandes venues du site, et la source les contient aussi.
Les recréer donnerait **deux commandes pour chaque vente en ligne**. `customerorder` les
reconnaît donc et se contente de poser leur `ref_ext` : ni renommées, ni remaniées, la
boutique restant leur source.

Le rapprochement se fait sur la référence de la boutique, que la source enregistre de deux
façons selon l'époque :

| Document source | `DO_NoWeb` | Référence retenue |
|---|---|---|
| `CI-251029` | `251029` | `251029` |
| `CI-261866` | `UJGSMDLHX` | `UJGSMDLHX` |
| `CI-210251` | *(vide)* | `210251`, tiré du numéro |
| `C990000777` | *(vide)* | `C990000777`, saisie interne |

La convention a changé en septembre 2022 : avant, seule la partie numérique du numéro portait
la référence ; ensuite `DO_NoWeb` l'enregistre, **et 3 333 fois elle diffère du numéro**. Les
deux voies sont donc nécessaires.

**La référence donnée en cible est celle que la boutique donnerait**, et ce n'est pas
cosmétique : Prestasync vérifie l'existence d'une commande avant de la créer, sur `ref`
(`prestaOrder.class.php:622`). Une commande reprise sous ce nom est reconnue par la boutique,
qui refusera de la recréer — la protection joue à chaque synchronisation ultérieure, pas
seulement au moment de la reprise.

159 documents visent la même référence, dont 22 partageant un `DO_NoWeb` valant `1` : la
première la prend, les suivantes reçoivent leur numéro de document, unique par construction.

> **Ce qui ne se teste pas en local.** L'adoption suppose des commandes créées par la
> boutique ; il n'y en a aucune sur une instance de développement. La conception s'est
> corrigée trois fois, chaque fois en comparant un client nommé entre les deux instances.
> Avant d'écrire quoi que ce soit en production, lancer un `--dry-run` et **regarder le
> compteur « adoptés »** : c'est lui qui dit si le rapprochement fonctionne.

## Idempotence : `ref_ext`, et `import_key` par exception

Chaque objet créé porte la clé de son enregistrement source dans `ref_ext`, préfixée
« SAGE: ». C'est ce qui rend les scripts rejouables sans table de correspondance dédiée.

Deux tables font exception, faute de `ref_ext` :

**`llx_product_fournisseur_price`** — le script `supplierprice` se rabat sur `import_key`,
prévue pour cela par le coeur et écrite par l'API `ProductFournisseurPrice`, sans requête
directe. Le marqueur y désigne **la ligne source** (`SAGE:<cbMarq>`) et non le couple
article/fournisseur : quatre articles sont référencés deux fois chez un même fournisseur,
avec deux références et deux prix distincts.

**`llx_stock_mouvement`** — elle n'a ni `ref_ext`, ni `import_key`, ni même `entity`. Le
script `stock` marque donc ses mouvements par **`inventorycode`**, dont la vocation dans le
coeur est précisément de regrouper plusieurs lignes en une opération. Ils portent
`SAGE:OUVERTURE` ou `SAGE:RELOCALISATION` selon le régime (voir ci-dessous), ce qui donne au
client une poignée concrète : filtrer dessus dans **Produits > Stocks > Mouvements** sort la
reprise en entier.

## Le stock, quand la cible n'est pas vierge

`stock` choisit son régime **ligne par ligne**, en regardant si le produit porte déjà du
stock :

| État du produit | Ce qui est écrit | Code d'inventaire |
|---|---|---|
| aucun stock | mouvement d'ouverture, à la quantité de la source | `SAGE:OUVERTURE` |
| du stock ailleurs | transfert vers l'entrepôt du dépôt | `SAGE:RELOCALISATION` |
| du stock déjà au bon endroit | rien, la ligne est comptée en place | — |

C'est le cas en production : PrestaShop tient son stock de l'ancien ERP et Prestasync l'avait
déjà poussé dans Dolibarr, tout entier dans l'entrepôt principal. Poser la photo par-dessus
aurait doublé le stock.

**Les quantités ne sont jamais modifiées dans le second régime.** Ce qui est en place vient du
système en service ; la source est une copie datée. Les écarts sont dénombrés au rapport, sans
être corrigés — décision documentée dans [ANOMALIES.md](ANOMALIES.md), S11.

Ce choix est fait sur l'état réel du produit et non par une option de ligne de commande :
**un lancement de trop ne double rien**, quel que soit l'état de la cible.

À noter, parce que l'intuition dit le contraire : **antidater les mouvements ne protège pas
du doublon.** `MouvementStock::_create()` fait un `reel = reel + qty` sans trier par date. La
date ne sert qu'à l'écran « Stock à une date » et à la lisibilité de l'historique.

À noter que `llx_product_stock` porte bien un `import_key`, mais **aucune classe du coeur ne
l'écrit** — il n'existe pas d'objet métier pour cette table. L'utiliser aurait imposé une
requête directe.

## Anomalies de la source

Les problèmes de qualité rencontrés dans les données de l'ancien ERP, les décisions
prises et ce qui reste à arbitrer sont consignés dans [ANOMALIES.md](ANOMALIES.md).
Ce fichier se complète au fur et à mesure de l'écriture des scripts.

## Documents commerciaux

Les quatre tables de documents — devis, commandes, factures, réceptions — ne sont **pas
reprises** à ce jour. Leur analyse préalable est dans [DOCUMENTS.md](DOCUMENTS.md) :
nomenclature des types, colonnes exploitables, volumétrie, correspondance possible avec les
objets Dolibarr, et les trois limites qui décideront du périmètre — au premier rang
desquelles l'absence totale de règlements enregistrés.

## Arborescence

```
aeromigration/
├── admin/          Pages d'administration (setup, about)
├── class/          Classes de reprise, une par entité migrée
├── core/modules/   Descripteur du module
├── langs/          Traductions (fr_FR, en_US)
├── lib/            Fonctions partagées
└── scripts/        Lanceurs en ligne de commande
```

## Ajouter un script de reprise

1. Créer la classe dans `class/`.
2. La déclarer dans `aeromigrationGetScripts()` (`lib/aeromigration.lib.php`) : elle
   apparaîtra alors dans la page de configuration.

## Installation

Le module ne crée aucune table. Il s'active depuis
**Accueil > Configuration > Modules > Progiseize > Reprise de données**.
