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
