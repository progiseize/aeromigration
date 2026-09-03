# Mise en production — ordre des opérations

L'instance en ligne actuelle est un **test** : tout ce qui suit sera rejoué sur la vraie
production. Cette note fige l'ordre des opérations et les contrôles, tels qu'ils ressortent
des travaux d'août 2026 — en particulier de la règle posée par le client le 18/08 :
**avant 2026, la base ADD fait foi sur tout** ; depuis la bascule du flux web vers la
boutique (juin 2026), c'est Prestasync qui fait foi.

## Le principe qui commande tout l'ordre

**La reprise d'abord, Prestasync ensuite.** Sur l'instance de test, le rattrapage boutique
intégral de juin 2026 (28 474 factures créées d'un coup depuis PrestaShop) a précédé la
reprise : la reprise a dû *adopter* l'existant au lieu d'écrire sur du propre, et ce
rattrapage — fait depuis des statuts PrestaShop périmés — a produit 40 factures fantômes
supprimées depuis, plus 45 factures annulées dans ADD restées actives.

Dans l'ordre inverse, rien de tout cela n'existe. Deux réserves :

1. **Un rattrapage Prestasync reste nécessaire, mais borné à la période post-bascule**
   (≈ juin 2026 → jour J) : ces ventes web n'ont **jamais été facturées par ADD** — vérifié
   pièce par pièce, aucune contrepartie. Le rattrapage *intégral* est superflu et nocif :
   sur toute la période où ADD facturait, il ne peut produire que doublons ou fantômes.
2. Depuis la 0.15.0, l'ordre n'est plus *critique* — l'adoption aligne les annulées, les
   pièces multiples créent chacune leur facture, et `align_invoices_add.php` ramène tout à
   la règle en fin de parcours. « Reprise d'abord » est l'ordre propre ; l'autre n'est plus
   dangereux, juste laborieux.

## Pré-requis

- **aerotoolbox** activé (dictionnaires disponibilité/suivi indispensables à `product`), et
  **réactivé après déploiement** de sa 1.13.0+ — l'extrafield `aerotb_avail_restore` des
  produits composés est créé à l'activation.
- **aeromigration 0.15.0 ou au-delà.**
- **Un export ADD frais du jour J** (zip de CSV `advance_*`) : la procédure de génération est
  acquise, l'export se fait à volonté. Ne pas partir d'un export vieux de plusieurs jours —
  tout le périmètre de la purge se recalcule depuis cette base.
- **`secure_file_priv`** : `import_add_csv.php` passe par `LOAD DATA INFILE` ; les CSV doivent
  être déposés sous le dossier que le serveur MySQL autorise (le script le vérifie et indique
  le chemin attendu).
- **PHP CLI : `-d memory_limit=1024M -d max_execution_time=0` sur chaque script.** Sous Linux,
  `max_execution_time` compte le temps **CPU** : un passage massivement calculatoire (reprise
  de dizaines de milliers de factures) meurt à mi-course avec la limite Plesk de 120 s, alors
  que les passages surtout en attente SQL passent. Vécu sur le test le 19/08 ; les scripts sont
  idempotents, une relance reprend où elle en était, mais autant ne pas mourir du tout.
- **Base unique en ligne** : les tables `f_*`/`z_*`/`p_*` cohabitent avec les `llx_*`. Poser la
  constante `AEROMIG_SOURCE_DB` en conséquence (ou `--source-db=` sur chaque script, vide pour
  « la base de Dolibarr »), et `--database=`/`--model=` pour l'import CSV. `--only=` évite d'y
  installer les 347 tables : seules celles que les scripts lisent sont utiles.
- **Les fichiers de liaison boutique** (`data/liaison_presta_tiers_*.csv` et
  `liaison_presta_fournisseurs_*.csv`) régénérés depuis la **dernière photo** de l'instance
  précédente — ils portent la correspondance id PrestaShop ↔ code tiers ADD que l'étape 6
  rejouera sur la base neuve.

## L'ordre des opérations

### 1. Figer la boutique

Suspendre le flux Prestasync (pas de création de commandes/factures pendant la reprise), et
vérifier que `PRESTASYNC_SEND_STOCK_TRIGGER` vaut `disable` — il doit le rester ensuite : sur
les produits composés, il pousserait un `stock_reel` nul et écraserait le stock assemblable.

### 2. Charger la base source

```
php scripts/import_add_csv.php --source=<dossier des CSV> [--database=… --model=… --only=…]
```

Contrôler le récapitulatif : zéro écart fichier/table, zéro échec, et la date du dernier
document (`MAX(DO_Date)` sur `f_docentete_global`) au jour J.

### 3. Rejouer les reprises

Dans l'ordre du README (`thirdparty → contact → newsletter → category → product →
supplierprice → packaging → warehouse → location → stock → productlocation → supplierorder →
customerorder → invoice → reception → shipment → proposal → pricelevel → customerprice →
productkit`), chacune en `--dry-run` d'abord.

Points d'attention hérités du test :

- **Après `product`, passer `scripts/import_disposuivi.php`** (simulation puis `--confirm`) :
  l'arbitrage client du 03/09 sur la disponibilité et le suivi (fichier
  `migrationdata/disposuivi_migration.csv`). Le rejeu de `product` recrée les
  395 « EMPLACEMENT » (ils restent dans `f_article`) et remet les 46 prestations en type
  produit : ce script les re-supprime et les re-bascule à chaque rejeu, tant que le client
  n'a pas corrigé ADD.
- **Après `supplierprice`, passer `scripts/merge_suppliers.php`** (simulation puis
  `--confirm`) : la production a les mêmes anciens fournisseurs boutique que le test, et
  `thirdparty` les recréera en double depuis ADD pour la même raison (voir ANOMALIES
  Tiers §30 — `llx_prestasync_supplier` n'avait pas été alimentée). Le script fusionne
  l'ancien dans le tiers SAGE, repointe les correspondances PrestaShop et purge les tarifs
  d'achat que `supplierprice` remplace. Après `supplierprice` et non `thirdparty` : le
  `--map` fusionne aussi les doublons internes à ADD (SODIS → F208, Alpha Industries →
  F990000031), et les tarifs d'un tiers absorbé doivent être repris AVANT que son `ref_ext`
  disparaisse — le script diffère d'ailleurs ces fusions-là s'il détecte des tarifs ADD non
  repris. Contrôler les appariements interprétés affichés par la simulation, et refaire le
  `--map` depuis `data/fusions_fournisseurs.csv` (les réfs `Fxxx;Fyyy` restent valables,
  les rowid anciens changent avec la base).
- **`pricelevel` et `customerprice` vont ensemble**, et Prestasync doit être suspendu sur
  toute la fenêtre : entre la purge des tarifs et la fin du rejeu, `llx_product.price` vaut
  zéro. **La grille est à SEPT niveaux depuis la 0.28.0** (fusion comptoir/site) :
  `customerprice` refuse de démarrer si `PRODUIT_MULTIPRICES_LIMIT` ne vaut pas exactement
  7 — au besoin, passer `scripts/merge_price_levels.php --confirm` d'abord (rejouable : sur
  une base déjà basculée, il ne décale plus rien).
- Les écartés **attendus** de `invoice` : documents sans aucune ligne (≈ 380). Les ≈ 311
  factures dont le tiers a disparu de `f_comptet` lui-même sont **reprises** depuis la 0.16.1,
  rattachées au tiers générique « Clients Anonymisés » (décision client du 19/08/2026) —
  créé automatiquement au premier besoin, code ADD d'origine en note de chaque facture.
- **Après `invoice`, passer `scripts/classify_paid_invoices.php`** (simulation puis
  `--confirm`) : le rejeu recrée « impayées » les ~80 000 factures dont l'ancien ERP dit
  pourtant qu'elles sont réglées (`DR_Regle`, voir ANOMALIES D3 — la table des règlements
  ne commence qu'en novembre 2019). Le script les classe payées, abandonne « Prescription »
  les non-réglées antérieures à la borne, et laisse la vraie liste de recouvrement
  (~1 400 pièces). Attendu : passe prescription vide, comme sur le test.
- **`shipment` exige que `customerorder` soit passé en entier** : une commande citée par une
  préparation et absente de la cible est une erreur, pas un écart. Ses expéditions naissent
  clôturées **avec leur référence définitive** (`BL…`, règle des factures — coupure 01/10/2023,
  validée par le client le 20/08/2026) : aucune renumérotation ultérieure, et le stock ne bouge
  pas — le script neutralise les modules stock/lots dans la mémoire de son seul processus et le
  vérifie au rapport. Écartés attendus : ≈ 296 coquilles vides, ≈ 53 documents sans commande,
  2 pièces du carnet atelier (OR), 2 236 annulées.
- **Après `shipment`, passer `scripts/close_delivered_orders.php`** (simulation puis
  `--confirm`) : il classe « Livrée » les commandes que les expéditions couvrent en totalité,
  en ignorant ce qui ne s'expédie jamais (texte libre, article « Transport », pseudo-articles
  `PORT*`/`RETRAIT*`). Ni le coeur ni `shipment` ne peuvent le faire seuls — voir le
  ChangeLog 0.19.1. Les livraisons partielles sont listées, jamais forcées.
- **Après les reprises, les imports à fichier** (dossier `migrationdata/`, jamais versionné —
  déposer les CSV sur le serveur d'abord), chacun en simulation puis `--confirm` :
  `import_birthdays.php` (dates de naissance boutique → contacts des particuliers ;
  **régénérer l'extraction depuis la dernière photo PrestaShop** avant le jour J, comme les
  liaisons) et `import_consignes.php` (garantie, consignes et notes produits arbitrées par le
  client — fichier figé issu de `data/2608_consignes.xlsx`, aucun rafraîchissement à prévoir).
- **Puis `scripts/set_alert_stock.php`** (simulation puis `--confirm`) : pose le stock
  d'alerte calculé (ventes ADD 2026 / 8 × 2, arrondi supérieur) sur les produits dont le
  seuil est vide — les 926 valeurs humaines reprises d'ADD sont conservées. À passer APRÈS
  `product`, il lit les ventes directement dans les tables source.

### 4. Aligner les produits composés

```
php scripts/sync_kit_tracking.php --confirm
```

Après réactivation d'aerotoolbox (pré-requis ci-dessus).

### 5. Contrôle final de la règle « ADD fait foi »

```
php scripts/align_invoices_add.php            # simulation
php scripts/align_invoices_add.php --confirm  # si elle montre quelque chose
```

Si la reprise a couru sur une base vierge de tout rattrapage boutique, les passes 1 et 2
doivent être à **zéro** — c'est le témoin que l'ordre a été respecté. La passe 3 liste les
ventes de 2026 sans facture ADD : à rapprocher de la liste arbitrée avec le client (au 18/08 :
3 expédiées dont 2 716,42 € de créances aéroclubs, 5 commandes en attente).

### 6. Rétablir les liaisons boutique — AVANT tout démarrage de Prestasync

Prestasync tient ses correspondances par **rowid Dolibarr** : sur la base neuve, elles sont
toutes caduques. Sans elles, il ne reconnaît personne et **recrée un tiers à chaque commande**
— c'est ce qui a dédoublé les 317 fournisseurs de l'instance de test.

```
php scripts/relink_prestasync.php            # simulation
php scripts/relink_prestasync.php --confirm
```

Le script lit les fichiers `data/liaison_presta_*.csv` (id PrestaShop ↔ code tiers ADD,
exportés depuis l'instance précédente — **à régénérer depuis la dernière photo avant le
jour J**) et repeuple `llx_prestasync_customer` et `llx_prestasync_supplier` en résolvant
chaque code vers le `ref_ext` posé par la reprise. Attendu au 19/08 : ~155 673 clients et
~292 fournisseurs rattachés ; les fournisseurs nés dans la boutique (25) et les ambigus (4)
sont listés au rapport — Prestasync les recréera, ou ils se traitent à la main. Les adresses
de livraison (`llx_prestasync_address`) ne sont pas rattachées : nées de la boutique, sans
clé stable, Prestasync les recrée à la demande sans dégât.

### 7. Rattrapage Prestasync borné à la bascule

Rattraper commandes et factures boutique **à partir de juin 2026 seulement** (borne exacte à
fixer avec l'associé au vu de la date de bascule effective du flux web, mesurée au 11/06/2026
sur les données). Jamais de rattrapage intégral.

### 8. Rouvrir le flux vivant

Réactiver Prestasync. À partir de là, la boutique fait foi sur le neuf ; ADD n'est plus
consulté que par les scripts de contrôle.

### 9. Renumérotation des pièces

Chantier distinct, à jouer **après** les étapes ci-dessus (le parc doit être définitif avant
de renuméroter) : coupure au 01/10/2023, numéros ADD conservés avant, séquence par exercice
fiscal après. Trois scripts : `renumber_invoices.php` (FA/AV), `renumber_supplier_orders.php`
(COF) et `renumber_customer_orders.php` (CO, périmètre complet boutique comprise) — les
expéditions, devis et réceptions naissent déjà au format définitif depuis les
0.18.0/0.19.0/0.20.0. **Le script CO se joue impérativement APRÈS le rattrapage Prestasync et
la pose des liaisons** : le flux vivant retrouve les commandes par `llx_prestasync_order`,
mais le filet anti-doublon à la création cherche par référence. Les PDF de commandes sont
supprimés avant (garde-fou) et ne se régénèrent pas en masse : la boutique les obtiendra à la
demande via l'endpoint aeropresta (décision client du 20/08/2026 — vaut aussi pour les
factures).

**La question des PDF est tranchée (19/08/2026)** : sur les instances de test, aucun document
n'a de valeur — les PDF « transmis » ne l'ont été qu'à la boutique de test. Tout a été
supprimé (répertoires, `llx_ecm_files`, `last_main_doc`), et la renumérotation n'a donc
**aucun renommage de fichiers à gérer** : elle travaille sur un parc sans documents, avec un
garde-fou qui le vérifie. Les PDF se régénèrent **à la demande, après** renumérotation — au
premier clic, avec le numéro définitif. Au jour J, même principe : renuméroter avant toute
émission de document, jamais l'inverse.

## Contrôles de fin de mise en production

1. **Idempotence** : `migrate.php invoice --dry-run` → 0 créé, 0 mis à jour ;
   `align_invoices_add.php` → 0 / 0. Un rejeu qui trouve encore du travail signale un
   problème d'ordre ou de fraîcheur de la base source.
2. **Réconciliation pièce à pièce** (référence : celle du 18/08 sur le test) :
   - toute facture **active** de Dolibarr a une contrepartie **active** dans ADD — l'écart
     attendu est **zéro** ;
   - les actives ADD absentes de Dolibarr se réduisent à la seule catégorie documentée
     (documents sans aucune ligne, ≈ 380) plus les factures boutique post-bascule — depuis la
     0.16.1, les tiers disparus ne font plus d'absentes ;
   - aucune facture abandonnée ne porte de règlement.
3. **Montants** : sur les ères où ADD tient `DL_MontantTTC` (numéros `F`/`FF`/`AF`/`AP`/`RF`),
   la somme des lignes ADD retombe sur le total Dolibarr à l'arrondi près (98,8 à 100 % sur le
   test). L'ère `FAC` (2015-2019) n'est pas comparable par cette colonne — ce n'est pas un
   écart.

## Décisions en attente qui touchent cette procédure

- ~~Les ≈ 310 factures aux tiers disparus d'ADD~~ **Tranché le 19/08/2026** : reprises et
  rattachées au tiers générique « Clients Anonymisés » (0.16.1) — plus rien à faire au jour J.
- **Les créances aéroclubs** (2 716,42 € au 18/08) : à recouvrer côté gestion ; leurs factures
  Dolibarr sont conservées et seront renumérotées normalement.
- **Statuts produits** : la source vivante est `disponibilite_origine`/`suivi_origine` (ids
  ADD 1-7 = dictionnaires aerotoolbox, ADD 8 = notre 10), pas les champs libres — correctif
  de `product` à livrer avant le rejeu final (voir ANOMALIES A3).
