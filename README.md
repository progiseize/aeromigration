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
```

| Script | Dépend de | Ce qui se passe s'il est lancé trop tôt |
|---|---|---|
| `thirdparty` | — | |
| `contact` | `thirdparty` | s'arrête avec un message explicite |
| `newsletter` | `thirdparty` | s'arrête avec un message explicite |
| `category` | — | |
| `product` | `category` | les articles sont créés **sans classement**, et le rapport le signale |

Seul `product` reste tolérant : un article sans catégorie demeure un article valide, et
l'on peut vouloir reprendre les produits seuls. Le rapport de fin de passage indique alors
clairement qu'aucune catégorie n'a été trouvée.

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

## Anomalies de la source

Les problèmes de qualité rencontrés dans les données de l'ancien ERP, les décisions
prises et ce qui reste à arbitrer sont consignés dans [ANOMALIES.md](ANOMALIES.md).
Ce fichier se complète au fur et à mesure de l'écriture des scripts.

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
