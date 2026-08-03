# Journal des modifications — Reprise de données

Toutes les évolutions notables du module sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et le module respecte le [versionnage sémantique](https://semver.org/lang/fr/).


## [0.11.0] — 2026-08-03

L'**emplacement de stockage cesse d'être un entrepôt**, et la reprise s'appuie désormais sur
l'export intégral livré par l'éditeur.

### Les emplacements quittent les entrepôts

Sept cent dix-neuf sous-entrepôts avaient été créés pour un dépôt réel, un par emplacement de
rangement. Chaque sélecteur d'entrepôt en était encombré, un déplacement d'étagère devenait un
transfert de stock, et les états par entrepôt n'étaient plus lisibles.

L'emplacement n'est pas une unité de gestion de stock. L'entrepôt dit **combien**, l'emplacement
dit **où** — deux questions distinctes, désormais portées séparément.

Deux scripts nouveaux, qui s'appuient sur le dictionnaire et le champ produit ajoutés par
`aerotoolbox` 1.5.0 :

| Script | Source | Résultat |
|---|---|---|
| `location` | `f_depotempl` | 866 emplacements dans le dictionnaire |
| `productlocation` | `f_artstock.DP_NoPrincipal` | 6 424 produits rangés |

**La source est fusionnée sur l'intitulé** : 1 007 lignes pour 866 emplacements distincts.
« BOUTIQUE » y figure huit fois, « S1-A11-2 » sept fois, sous des numéros différents — ce sont
des saisies répétées du même endroit, et les reprendre telles quelles donnerait huit entrées
indiscernables dans la liste déroulante.

**Trois défauts de la source, traités et signalés.** `DP_Code` est inexploitable : tronqué à
treize caractères (« STOCK SECURIS » pour « STOCK SECURISE ») et corrompu sur treize lignes, où
il vaut `<input type=`. C'est `DP_Intitule` qui sert de clé. Trois intitulés ne contiennent eux
aussi que ce fragment HTML : ils sont repris sous leur numéro d'origine, ce qui les garde
**distincts** — un repli commun les aurait fusionnés et aurait envoyé au même endroit des
articles rangés à trois places différentes. Cinq intitulés portent un « ? » à la place d'une
lettre accentuée ; rien n'est deviné, le rapport les liste pour correction.

Le rapport signale aussi les **quatre-vingts groupes d'emplacements qui ne diffèrent que par
leur ponctuation** — « STOCK SECURISE » et « STOCK-SECURISE ». Ils ne sont pas fusionnés : sur
un code d'allée, « M1-A2-3 » et « M1-A23 » ne désignent pas forcément le même rayonnage, et une
fusion à tort égarerait des articles sans laisser de trace, quand deux entrées en trop se
corrigent d'un clic.

### Une base source à part

L'éditeur a livré son dossier entier — 347 tables, 3,2 Go — là où les premiers travaux
s'appuyaient sur une vingtaine de tables importées à côté des `llx_*`. Les recopier dans la base
de Dolibarr y verserait plusieurs gigaoctets qui n'ont rien à y faire.

Le socle reçoit donc `$sourceDb` et un helper `src()`, et le lanceur l'option **`--source-db`**.
Laissée vide, rien ne change pour les scripts écrits avant elle.

Ce n'est pas qu'une commodité : l'export est plus récent, et l'écart compte. Sur `f_artstock`,
**1 401 quantités diffèrent et 97 articles s'ajoutent**. Les scripts `warehouse`, `stock`,
`location` et `productlocation` lisent la nouvelle base ; les autres restent sur l'ancienne tant
qu'ils n'ont pas été éprouvés dessus.

Un script d'import accompagne le tout : **`scripts/import_add_csv.php`** construit la base depuis
les CSV, en déduisant le schéma quand il n'est pas déjà connu.

### `stock` — tout dans le dépôt

`resolveWarehouse()` retourne l'entrepôt principal, sans condition. La ventilation par marqueur,
la résolution par libellé et l'entrepôt « À localiser » disparaissent, avec les index qui les
servaient.

Le régime de transfert est conservé et prend un sens nouveau : c'est lui qui **rapatrie le stock
des anciens sous-entrepôts** sur une instance reprise avant cette version.

Le rapport gagne un bloc **« Stock resté ailleurs »**. Le rapatriement ne porte que sur les
produits lus, et le filtre écarte les lignes entièrement à zéro : un article vendu depuis la
copie de la source s'y trouve à zéro alors qu'il porte encore du stock en base. Rare, mais il
faut le savoir avant de supprimer l'entrepôt qui le contient — voir ci-dessous.

### `warehouse` — un dépôt, un entrepôt

Le script lit `f_depot` et non plus `f_emplacements`. Le dépôt 999 « Siege boutique.aero » est
écarté : ce n'est pas un lieu de stockage mais une écriture technique, dont les 11 826 lignes de
stock sont à zéro.

L'adoption d'un entrepôt préexistant et le marquage `SAGE:DEPOT1` sont conservés — c'est ce dont
`stock` et `location` dépendent pour savoir où travailler.

`purge.php` reçoit **`--legacy`**, qui ne supprime que les sous-entrepôts hérités. Sans elle, la
purge emporterait aussi l'entrepôt du dépôt, qui porte maintenant tout le stock et auquel les
866 emplacements sont rattachés.

### Un piège du coeur, corrigé dans notre documentation

Nous avons d'abord écrit que Dolibarr refuse de supprimer un entrepôt qui porte du stock. **C'est
l'inverse.** `Entrepot::delete()` ne vérifie rien et supprime lui-même `product_batch`,
`stock_mouvement` puis `product_stock` avant l'entrepôt (entrepot.class.php:458-481). Le stock
disparaît, et son historique de mouvements avec — donc sans trace permettant de savoir ce qui a
été perdu. Aucun message, la suppression réussit.

Constaté en supprimant les 726 sous-entrepôts : douze unités se sont évaporées. Documenté en
**P8** dans `ANOMALIES.md`, avec l'avertissement reporté dans `purge.php warehouse` et dans le
rapport de `stock`.

À noter : `llx_product.stock` tombe alors à `NULL`, que Dolibarr traite comme zéro. Un contrôle
écrit `p.stock <> (SELECT SUM(reel) …)` ne remonte rien, la comparaison avec `NULL` étant
elle-même `NULL`. Il faut `COALESCE(p.stock, 0)`.

### Ordre d'exécution

```
thirdparty → contact → newsletter → category → product → supplierprice
→ warehouse → location → stock → productlocation
→ supplierorder → customerorder
```

`location` doit précéder `productlocation`, et `warehouse` les deux : les emplacements se
rattachent à l'entrepôt du dépôt.

### Reprendre une instance migrée avec l'ancien modèle

```
migrate.php warehouse            # adopte le dépôt, ne crée plus de sous-entrepôt
migrate.php location             # remplit le dictionnaire
migrate.php stock                # rapatrie tout dans le dépôt — lire « Stock resté ailleurs »
purge.php warehouse --legacy --confirm   # supprime les vestiges
migrate.php productlocation      # range les produits
```

Éprouvé de bout en bout : 726 sous-entrepôts supprimés, un seul entrepôt restant, 5 641 lignes de
stock pour 164 871 unités, aucune ligne de stock, d'emplacement ni de mouvement orpheline.


## [0.10.0] — 2026-07-31

### Sécurité

- **`data/` était servi par le serveur web**, sans aucune protection. Le dossier contient le
  script de création des emplacements, et sert de point de dépôt aux exports le temps d'un
  import : un dump de l'historique commercial y aurait été téléchargeable par quiconque en
  connaissait l'URL. Un `.htaccess` l'interdit désormais, dans les deux syntaxes d'Apache.

  Le réflexe vaut au-delà de ce dossier : **tout ce qui est déposé sous `htdocs/` est public
  par défaut**, y compris le temps d'une manipulation qu'on croit brève.

### Corrigé

- **Un produit existant pouvait être invisible au script tout en bloquant sa création.**
  `migrationproduct` indexait les références en cible à l'identique, alors que Dolibarr compare
  sans tenir compte de la casse. Un article `EMBALLAGE` dans la source, saisi `Emballage` en
  cible, n'était donc pas reconnu : le script tentait de le créer, et le coeur refusait sur
  `ErrorProductAlreadyExists`.

  Un seul article concerné en production — mais le plus utilisé du jeu, présent sur **754
  lignes de commande** qui se retrouvaient toutes sans produit rattaché. C'est ce qui a fait
  passer le compteur d'articles introuvables de 175 en local à 926 en ligne, et c'est en
  cherchant cet écart qu'il a été trouvé.

  Les index sont désormais normalisés. Aucune collision n'en résulte, et il ne peut pas y en
  avoir : l'unicité étant elle-même insensible à la casse côté base, deux références n'en
  différant que par elle ne peuvent pas coexister.

  > **Troisième occurrence du même piège** dans ce module, après les commandes fournisseur et
  > les commandes clients. La règle à retenir : **tout index PHP construit sur une valeur
  > venue de MySQL doit être normalisé**, sans quoi une requête de contrôle et le script
  > donneront des résultats différents — et c'est la requête qu'on croira.

### Ajouté

- **Reprise des commandes clients** — `migrate.php customerorder`. 60 936 commandes et
  200 010 lignes, de 2019 à 2026. Validée sur un échantillon de **3 116 commandes réparties
  sur 102 tiers** — gros, moyens et petits clients — repris en 2 min 52 sans une erreur.

  | | |
  |---|---:|
  | Commandes fermées, facturées dans l'ancien ERP | 2 932 |
  | Commandes annulées | 138 |
  | Commandes validées, sans suite | 46 |
  | Lignes écrites | 15 014 |
  | Montants identiques à la source | **3 048 / 3 081** |
  | Écart total | **1,13 €** sur 1 576 079 € |

  Les 33 écarts sont des arrondis de ligne. Les 3 116 références obtenues sont toutes uniques.

- **Option `--filter="SQL"`** sur le lanceur, pour tous les scripts. Elle restreint la lecture
  à un sous-ensemble — elle ne peut qu'ajouter une condition au filtre du script, jamais
  l'élargir. C'est ce qui a permis de reprendre en local exactement les mêmes tiers qu'en
  production et de comparer les deux instances client par client, sans rejouer les 60 936
  commandes à chaque essai.

#### L'adoption des commandes de la boutique

La cible n'est pas vierge : Prestasync y crée les commandes venues du site, et la source les
contient aussi. Les recréer donnerait **deux commandes pour chaque vente en ligne**. Elles
sont donc reconnues, marquées d'un `ref_ext`, et laissées intactes — ni renommées, ni
remaniées, la boutique restant leur source.

**La clé de rapprochement a demandé trois corrections successives**, et aucune n'était visible
en local. Elles méritent d'être détaillées, parce que le raisonnement qui menait à chacune
paraissait solide.

**1. `DO_NoWeb` semblait prévu pour cela** — 20 637 commandes en portent un. Mais la colonne
est NULL sur les deux tiers des documents, et une capture d'écran comparant local et
production a montré que les commandes concernées n'étaient pas celles qu'on croyait.

**2. Prestasync écrit la référence de la boutique dans `ref`** — pas dans une table de liaison,
qu'il prévoit pourtant et laisse vide :

```php
if ($resSearchRef === 0 && !getDolGlobalInt('PRESTASYNC_NO_USER_PRESTA_REF')) {
    $this->doliObject->ref = $this->reference;                  // prestaOrder.class.php:634
}
```

La source enregistre le même document sous `CI-<référence>` : la commande `235880` de la
boutique est la commande `CI-235880` de la source. 54 700 des 60 936 portent ce préfixe.

**3. Mais la convention a changé en septembre 2022.** Avant, la référence de la boutique
n'était pas conservée et seul le numéro la portait ; après, `DO_NoWeb` l'enregistre — tantôt
identique au numéro, tantôt alphanumérique :

```
CI-251029   DO_NoWeb = 251029       →  la boutique la nomme « 251029 »
CI-261866   DO_NoWeb = UJGSMDLHX    →  la boutique la nomme « UJGSMDLHX »
CI-210251   DO_NoWeb vide           →  repli sur le numéro, « 210251 »
```

**3 333 documents ont un `DO_NoWeb` qui diffère du numéro.** Se fier au seul numéro les aurait
manqués — et leur aurait donné une référence que la boutique n'aurait pas reconnue.

> **Ce que ces trois passes apprennent.** Aucune n'était détectable depuis la base locale : il
> a fallu, chaque fois, comparer un client précis entre les deux instances. Quand la cible
> d'une reprise contient déjà des données produites par un autre système, **la conception ne
> se valide pas en local** — elle se valide en confrontant les deux, sur des cas nommés.

#### La référence reprise est celle que la boutique donnerait

Une commande web est nommée `235880` et non `CI-235880`. Ce n'est pas cosmétique : Prestasync
vérifie l'existence d'une commande avant de la créer, et il le fait sur la référence
(`prestaOrder.class.php:622`). Une commande reprise sous le nom que la boutique lui donnerait
est donc reconnue par elle, qui refusera de la recréer. **La protection joue dans les deux
sens** — pas seulement au moment de la reprise, mais à chaque synchronisation ultérieure.

Les 6 236 documents des autres formats — `C99…`, `C02…`, `OR…` — sont des saisies internes
sans équivalent en ligne : ils gardent leur numéro d'origine.

**159 documents visent la même référence**, dont 22 partageant un `DO_NoWeb` valant `1`. La
première la prend, les suivantes reçoivent leur numéro de document, unique par construction.
Les références déjà présentes en base sont réservées au démarrage, ce qui évite de buter sur
`uk_commande_ref` au moment de la validation.

#### Deux défauts corrigés en cours de route

**La casse des références.** Une requête de contrôle annonçait 177 articles introuvables, le
script en trouvait **36 050** : la source écrit les frais de port tantôt `PortSTD`, tantôt
`PORTSTD`, et **MySQL compare sans tenir compte de la casse là où PHP compare des clés de
tableau à l'identique**. 37 877 lignes concernées. Les index sont désormais normalisés en
minuscules — vérifié qu'aucune collision n'en résulte, les 15 811 références des produits
restent 15 811. Le même défaut a été corrigé dans la reprise des commandes fournisseur.

**`Not for sale`.** Comme côté achats, `valid()` refuse un document portant un article dont
`tosell` vaut 0, et 1 076 des articles repris sont arrêtés.
`ORDER_NOCHECK_ONSALE_PRODUCTS_ONVALID` est posée en mémoire du processus, jamais en base.

#### Choix de reprise

- **Statuts** : `Z_Annule` pour l'annulation, chaînage `DL_PieceBC` vers les factures pour la
  clôture. Une commande fermée est en outre classée « facturée », sans quoi elle resterait
  dans la liste des commandes à facturer — 59 231 entrées qui n'auraient aucun sens.
- **Performance** : `addline()` recalcule les totaux du document à chaque appel si on ne l'en
  empêche pas (`commande.class.php:1829`). Le recalcul est reporté à la fin de chaque
  commande, un seul `update_price()` par document.
- **Les 22 documents sans tiers** sont écartés dès la lecture : une commande client sans
  client n'a pas de sens en cible, et le socle les compterait en erreur à chaque passage.
- **Lignes conservées en texte libre** : 1 890 sans référence article, 175 dont l'article n'est
  pas repris. Les perdre fausserait le montant du document.

#### Un compteur qui se contredisait

Le premier essai en production a révélé un défaut d'affichage : la ligne de progression
annonçait « créés 11, adoptés 0 » quand le rapport comptait 10 adoptions. Le socle appelle
`validateRow()` **puis** `previewAction()` sur la même ligne ; or la première consomme la
référence de boutique — sans quoi plusieurs documents la partageant adopteraient la même
commande — et la seconde ne la retrouvait donc plus.

Le rapport disait vrai, le compteur mentait. Sur le run complet, il aurait annoncé 60 936
créations là où des milliers de commandes sont en réalité adoptées : de quoi faire renoncer
à lancer, ou pire, croire à un doublon massif. La décision est désormais prise une fois par
`validateRow()` et relue, plutôt que recalculée sur un index modifié entre-temps.

### Reste à valider en production

**L'adoption n'a pas pu être testée sur données réelles** : `llx_prestasync_order` est vide en
local et aucune commande de la boutique n'y existe. Le mécanisme n'a été vérifié que sur deux
commandes simulées. Le `--dry-run` en ligne, et son compteur « adoptés », est le contrôle qui
décide — avant toute écriture.


## [0.9.0] — 2026-07-31

### Ajouté

- **Reprise des commandes fournisseur** — `migrate.php supplierorder`. Premier script du
  module à reprendre un **document à lignes** : 2 567 commandes et 24 265 lignes, de 2019 à
  2026, en 4 min 34.

  | | |
  |---|---:|
  | Commandes reçues complètement | 1 852 |
  | Commandes annulées | 603 |
  | Commandes en attente de réception | 112 |
  | Lignes écrites | 24 265 |
  | Montant repris | 6 404 643 € HT |

  Contrôle des montants sur les 2 399 commandes en euros : **2 379 identiques au centime**,
  20 écarts de 3 à 5 centimes dus aux arrondis de ligne, soit **1,60 € d'écart sur 5,6 M€**.

#### Les numéros d'origine sont conservés

`create()` pose `ref = '(PROV<id>)'` et le module de numérotation attribue normalement la
référence à la validation. Mais `valid()` ne renumérote que si la référence est encore
provisoire (`fournisseur.commande.class.php:816`) : la poser avant suffit à la garder. Les
2 567 `DO_Piece` étant uniques, le client retrouve ses `CLMBCF990002512` plutôt que des
`CF2607-0001` qui ne lui diraient rien.

#### Le statut vient d'une colonne que Sage ne documente pas

`DO_Statut` vaut **9 sur les 2 567 commandes** : elle ne distingue rien. L'annulation est
portée par **`Z_Annule`**, colonne ajoutée par l'applicatif par-dessus le schéma Sage — d'où
son préfixe `Z_`. Établi par lecture d'écran sur six documents : les cinq annulés portent
`A`, celui en cours ne porte rien.

Le reste se déduit du chaînage `DL_PieceBC` vers les réceptions et les factures. Les 112
commandes qui restent en attente sont le seul en-cours réel, et plus de la moitié date de
2024 ou après.

> **La même colonne sert côté ventes** — 1 423 commandes clients annulées sur 60 936 — et
> `f_docentete_global` porte aussi `Z_Solde`, `Z_Supprime` et `Z_Top`. Toute analyse d'une
> table de ce jeu devrait commencer par lister ses colonnes non-Sage : celles qui comptent
> sont parfois celles que le schéma d'origine ne connaît pas.

#### Deux obstacles rencontrés à l'écriture

**`create()` n'écrit ni `ref_ext` ni `date_commande`.** Ces colonnes ne figurent tout
simplement pas dans sa requête d'insertion. Sans rattrapage par `update()`, la reprise
n'aurait aucune clé d'idempotence — un second passage aurait recréé les 2 567 commandes — et
toutes seraient sans date. Détecté au premier essai d'écriture, sur quinze commandes.

**La validation refusait 233 commandes sur 2 567**, avec
`ErrorOneLineContainsADisactivatedProduct` : `valid()` rejette tout document portant un
article dont `tobuy` vaut 0, et **1 076 des 15 811 articles repris sont arrêtés**. Le
contrôle est justifié à la saisie, absurde sur un historique — ces commandes ont existé.
`SUPPLIER_ORDER_NOCHECK_ONBUY_PRODUCTS_ONVALID` est donc posée **en mémoire du processus**,
jamais en base : le comportement de l'application pour les utilisateurs n'est pas modifié.

> **Un effet de bord instructif.** Le `rollback()` interne de `valid()` ne se contente pas
> d'annuler sa propre transaction : il emporte celle que le socle avait ouverte autour de la
> ligne, et les écritures suivantes se perdent silencieusement. Sur le premier passage
> complet, 233 erreurs n'ont laissé que **20 commandes** en base au lieu des 2 334 attendues.
> Un script qui compte ses succès sans vérifier la base aurait annoncé une réussite.

#### Choix de reprise

- **Devises** — 120 commandes en devise étrangère (109 USD, 8 CAD, 3 GBP). Une commande dont
  le taux de change source est nul est reprise dans la devise de l'instance : un taux nul
  rendrait tous les montants infinis.

  > **Les deux systèmes comptent le taux à l'envers l'un de l'autre.** `DO_Cours` donne les
  > euros par unité de devise, `multicurrency_tx` les unités de devise par euro. S'en
  > apercevoir demandait une valeur connue des deux côtés : l'article `7248` vaut 50,80 $ pour
  > 47,244 €, et la ligne de `CLMBCF990002266` porte 47,710344 avec un cours de 0,93918 —
  > soit `50,80 × 0,93918`. Les lignes de document sont donc déjà en euros, ce qui rendait les
  > totaux justes sans rien faire ; seul l'onglet devise était faux, affichant 2 268 € pour
  > 2 041 $ au lieu de 2 520 $. Un défaut qui ne se voyait que sur 120 commandes, et sur un
  > onglet que personne n'ouvre avant la mise en service.
- **Remises** — 1 275 lignes. La source en autorise trois par ligne, une seule est utilisée.
  Les remises exprimées en montant et les remises négatives (des majorations) sont ignorées,
  Dolibarr n'ayant qu'un taux par ligne.
- **Lignes conservées en texte libre** — 18 lignes sans référence article, et 2 dont l'article
  n'a pas été repris. Les perdre fausserait le montant du document.
- **50 commandes sans aucune ligne** sont reprises telles quelles : elles existent dans la
  source, les masquer serait une décision qui ne nous appartient pas.
- **Une référence corrompue**, `undefinedBCF9900`, où le `undefined` de JavaScript s'est
  glissé dans le numéro. Unique, donc reprise telle quelle, et signalée au rapport.


## [0.8.1] — 2026-07-30

### Corrigé

- **`warehouse` adoptait l'entrepôt principal existant sans lui poser son marqueur**, et
  `stock` refusait alors de démarrer — « Entrepôt principal introuvable (marqueur
  SAGE:DEPOT1) ». Rencontré en production, où l'entrepôt de la boutique préexistait à la
  reprise : `warehouse` le reconnaissait bien par son libellé, l'annonçait « déjà présent »,
  mais rien en base ne disait qu'il tenait ce rôle.

  Le marqueur est désormais posé à l'adoption, comme il l'est déjà pour les tiers venus de la
  boutique : on ne recrée pas, on ne renomme pas, on complète ce qui est vide et on marque.
  Vérifié qu'aucune autre colonne ne bouge — `Entrepot::update()` réécrit tout l'objet, ce qui
  n'est neutre que parce que `fetch()` charge tout ; les triggers sont désactivés, poser un
  marqueur technique n'étant pas une modification métier.

  Un marqueur venu d'un autre import n'est **jamais écrasé** : il appartient à cet import et le
  détruire lui ferait perdre son idempotence. Le rapport avertit alors que `stock` ne trouvera
  pas son dépôt principal, plutôt que de laisser découvrir le blocage au lancement suivant.

- **La ventilation par emplacement était comptée deux fois** dans le rapport de simulation,
  dès lors que le produit portait déjà du stock. `resolveWarehouse()` tient les compteurs de
  ventilation, et le mode simulation l'appelle deux fois par ligne — une fois pour annoncer
  l'action, une fois pour la contrôler. Invisible sur une cible vierge, le défaut donnait en
  production 11 446 lignes ventilées pour 5 938 lues.

  La résolution est désormais mémorisée par ligne source : les compteurs sont incrémentés une
  fois par ligne, quel que soit le nombre d'appels. **Les écritures n'étaient pas concernées**,
  seul le rapport l'était.

> **Ce que ce défaut apprend.** Une méthode qui résout *et* compte n'est plus idempotente, et
> le second appel est indétectable à la lecture de l'appelant. Le compteur a été déplacé
> derrière un cache, mais la leçon vaut pour les prochains scripts : garder la résolution pure
> et compter à l'endroit qui décide, ou mémoriser dès qu'un effet de bord s'y attache.


## [0.8.0] — 2026-07-30

### Ajouté

- **La reprise du stock sait désormais que la cible n'est pas toujours vierge.** Sur
  l'instance de production, PrestaShop tient son stock de l'ancien ERP et Prestasync l'avait
  déjà poussé dans Dolibarr : **5 559 articles repris y portaient 172 965 unités**, contre
  167 830 dans la photo — 96,4 % de concordance exacte. Le stock *est* celui de l'ancien ERP,
  simplement plus récent, et tout entier dans un seul entrepôt faute d'emplacement connu de
  la boutique. Poser la photo par-dessus l'aurait doublé.

  Le script choisit maintenant son régime **ligne par ligne** :

  | État du produit | Ce qui est écrit | Code d'inventaire |
  |---|---|---|
  | aucun stock | mouvement d'ouverture | `SAGE:OUVERTURE` |
  | du stock en place | transfert vers son emplacement | `SAGE:RELOCALISATION` |

  Ligne par ligne et non par une option de ligne de commande : c'est ce qui garantit qu'un
  lancement de trop ne double rien, quel que soit l'état de la cible. L'idempotence ne repose
  sur aucun marqueur mais sur la condition elle-même — au passage suivant il n'y a plus rien
  hors de l'emplacement de destination. Plus solide qu'un index, qui ne dirait pas si
  quelqu'un a déplacé du stock à la main entre-temps.

  **Les quantités ne sont jamais modifiées.** La quantité en place vient du système en
  service, la photo d'une copie datée : les écarts sont des ventes et des réceptions
  postérieures. Le rapport les dénombre, donne l'écart net et cite les dix plus gros, sans
  rien corriger.

  Un transfert est une **paire** de mouvements de types 0 et 1, comme le fait l'écran natif
  de déplacement en masse (`massstockmove.php:230`) : un déplacement entre entrepôts n'est ni
  une entrée ni une sortie de l'entreprise, et les types 3 et 2 y donneraient un contresens
  comptable.

  **Le stock est valorisé au passage.** Les mouvements de Prestasync portant un prix nul, le
  coût moyen était resté à zéro ; le prix de revient est posé sur l'entrée dans la
  destination, et `_create()` prend `$newpmp = $price` dès lors que l'ancien coût moyen est
  nul (`mouvementstock.class.php:588`). Vérifié : le coût moyen égale le coût de revient sur
  les cinq articles du jeu d'essai.

  La purge défait les deux régimes. Elle parcourt les mouvements dans l'ordre inverse de leur
  écriture — la seconde moitié d'un transfert doit être annulée avant la première — et
  contre-passe chaque type par son symétrique dans sa propre famille (0↔1, 2↔3). Vérifié : le
  stock revient dans son entrepôt d'origine, à la quantité d'origine.

### Corrigé

- **La valorisation du stock ignorait le repli sur le prix de revient.** Le script `stock`
  ne lisait que `AR_CoutStd`, là où `product` retient le coût standard puis `AR_PrixRU` en
  dessous du centime. Vingt-et-une lignes de stock n'ont pas de coût standard mais un prix de
  revient exploitable : **93 unités et 14 443,92 €** entraient avec un coût moyen nul, quand
  la fiche article, elle, affichait bien un coût de revient. Les deux écrans se
  contredisaient.

  Les deux scripts appliquent désormais la même règle. Le rapport distingue les lignes
  valorisées par le repli de celles qu'aucune des deux colonnes ne couvre — 104 lignes,
  812 unités, qui restent sans prix.

### Vérifié

- **Le choix du coût standard comme coût de revient est confirmé**, et par un contrôle qui
  manquait : la lecture d'écran de l'ancien ERP. L'article 13566 y affiche un prix d'achat
  brut de 39,00, une remise de 30 % et un net de 27,30 — et `AR_CoutStd` vaut exactement
  27,30. Sur les 53 articles dont la remise est connue, le coût standard reproduit le prix
  net 15 fois contre 11 pour le prix de revient.

  Contrôle de conformité sur les 15 811 produits repris : 14 906 `cost_price` égalent
  `AR_CoutStd`, 14 égalent `AR_PrixRU` par le repli, et **aucun** n'a de valeur inexpliquée.

  Un argument avancé jusqu'ici est en revanche retiré : le coefficient prix de vente / coût
  « de plusieurs millions » pour `AR_PrixRU` n'était que l'effet des divisions par ses 1 628
  valeurs sous le centime. Hors celles-ci, les deux colonnes donnent 1,93 et 2,00. La
  décision tient aux trois autres mesures, consignées dans ANOMALIES.md A7.

- **`AF_Remise` est bien un pourcentage appliqué à `AF_PrixAch`**, ce dont `AF_TypeRem`, NULL
  sur toute la table, ne disait rien. Le script `supplierprice` le reprenait déjà correctement
  dans `remise_percent`, que le coeur exploite pour le choix du meilleur fournisseur et le
  prix d'achat des lignes de document.

  Reste ouvert, et hors de portée du module : la source ne porte que **58 remises sur 15 962
  lignes**, alors que l'ancien ERP en affiche sur d'autres — 17 % sur l'article 14240, dont
  `AF_Remise` est NULL. Aucune autre table livrée ne contient de barème, et l'export CSV
  mélange prix bruts et nets sans règle discernable. Un export complémentaire est à demander,
  voir ANOMALIES.md F8.


## [0.7.3] — 2026-07-29

### Corrigé

- **Un code-barres en double faisait échouer tout le produit.** La source en compte 69,
  plus quelques valeurs de remplissage — « à compléter » sur cinq articles, « code barre »,
  ou des références fournisseur. Dolibarr en impose l'unicité et **refuse la fiche entière**
  lorsqu'elle en porte un déjà pris : l'article était perdu avec toutes ses données, pour un
  champ accessoire.

  Le code-barres n'est désormais posé que s'il est libre, et les valeurs comportant un espace
  ou dépourvues de chiffre sont écartées d'emblée. Le produit est repris dans tous les cas.
  Le rapport dénombre les deux situations et cite les valeurs écartées.

  Le porteur légitime est distingué d'un conflit réel : un produit déjà repris porte son
  propre code-barres, ce n'est pas un doublon. Sans cette nuance, un second passage aurait
  écarté les 8 700 codes-barres qu'il venait lui-même de poser.

> **Pourquoi ce défaut est passé au travers de la recette.** `Product::verify()` ne contrôle
> l'unicité du code-barres que si le module **Codes-barres** est activé
> (product.class.php:1361) — il ne l'était pas sur l'instance de développement. Et l'index
> `uk_product_barcode` portant sur `(barcode, fk_barcode_type, entity)`, il ne bloquait rien
> non plus, `fk_barcode_type` étant NULL sur les 15 811 produits.
>
> Deux garde-fous neutralisés en même temps, sur un environnement plus permissif que la
> cible. Les prochaines reprises devront comparer les modules activés de part et d'autre
> avant de conclure qu'un script est propre.


## [0.7.2] — 2026-07-29

### La page de configuration affiche l'état réel

La colonne « État » annonçait « À lancer » pour tous les scripts, en dur, depuis la version
squelette du module. Elle indique désormais lesquels ont été passés :

```
Tiers (clients et fournisseurs)   thirdparty   OK
Entrepôts et emplacements         warehouse    OK
Stocks                            stock        À lancer
```

Elle répond à une seule question — où en est-on dans l'ordre des reprises — et l'état est
**mesuré sur la base**, non sur une trace d'exécution : un script est « lancé » si ce qu'il
produit est là. C'est la seule mesure qui reste juste d'un environnement à l'autre, et qui
repasse d'elle-même à « À lancer » après une purge.

Nouvelle méthode `countMigrated()` sur le socle, distincte de `loadMigratedIndex()` qui
charge l'index complet — sur les huit scripts réunis, cela aurait représenté plus de
350 000 entrées en mémoire pour afficher huit nombres.

Quatre scripts la surchargent, ne se repérant pas par `ref_ext` : `warehouse` et
`supplierprice` comptent sur `import_key`, `stock` sur le code d'inventaire, et `newsletter`
sur la liste d'exclusion des envois.

Le comptage de `newsletter` partait d'abord des 157 102 tiers et joignait `f_comptet` par un
`CONCAT()` entre deux collations différentes, que MySQL ne sait pas indexer : **1 942 ms à
lui seul**. Il part désormais de la liste d'exclusion, quelques milliers de lignes, pour un
résultat identique en 665 ms. Les huit comptages tiennent en **0,87 s** au lieu de 2,17.


## [0.7.1] — 2026-07-29

### Corrigé

- **La progression était fausse dès qu'on reprenait au curseur.** Le total affiché portait
  sur la source entière, alors que le compteur ne comptait que les lignes de ce passage :
  après une interruption à 80 %, la relance repartait de « 0,3 % » sur 157 102. Le décompte
  tient désormais compte du curseur de départ et de la limite, et l'en-tête distingue le
  restant à lire du nombre de lignes que le passage traitera.

  Au passage, la clause de reprise, jusque-là écrite en dur dans la lecture, est partagée
  avec le décompte : c'est ce qui garantit que les deux portent sur le même ensemble.


## [0.7.0] — 2026-07-29

### Reprise des stocks

- **Nouveau script `stock`** : **5 687 mouvements d'ouverture**, **167 844 unités**,
  valorisées **1 274 861 €** au coût standard. Vérifié : le stock Dolibarr reproduit celui
  de l'ancien ERP **sans le moindre écart**, article par article.
- **Photo d'ouverture, pas rejeu d'historique.** L'ancien ERP conserve environ 581 000
  lignes de mouvements ; elles y restent consultables. La formule permettant de les rejouer
  a été établie et vérifiée à 98,9 %, elle est consignée dans `ANOMALIES.md` si le besoin
  se présentait.
- **Ventilation par emplacement** : 3 418 lignes dans leur sous-entrepôt, 218 dans un
  entrepôt fusionné retrouvé par son libellé, 1 989 sans emplacement d'origine versées dans
  l'entrepôt principal, et 15 dans « À localiser ».
- **Seuils de réapprovisionnement repris** : 924 seuils d'alerte, 978 stocks désirés. Écrits
  sur l'article et non par entrepôt — `llx_product_warehouse_properties` n'est lue que par
  le réapprovisionnement, et seulement si `STOCK_ALLOW_ADD_LIMIT_STOCK_BY_WAREHOUSE` est
  activée, ce qui n'est pas le cas. Les seuils y seraient invisibles partout.
- **131 quantités négatives reprises telles quelles**, cumul −954, et listées au rapport.
- Nouvelle option **`--date=AAAA-MM-JJ`** pour caler les écritures que la source ne date pas
  sur la date de bascule convenue, plutôt que sur l'instant du passage.

### Trois pièges du coeur Dolibarr

**`correct_stock()` annonce un succès quand rien n'a été écrit.** Elle teste
`if ($result >= 0)` alors que `MouvementStock::_create()` retourne **`0`** — et non un code
négatif — lorsqu'il n'a rien fait, notamment sur un produit que Dolibarr ne gère pas en
stock. Trois articles de la source sont des services en cible : **leur stock aurait disparu
sans le moindre message**. Le script appelle donc `_create()` directement, teste `<= 0`, et
détecte les services avant d'écrire pour les signaler nommément avec leur remède.

Cette méthode ne sait par ailleurs pas dater un mouvement, ce qui la rendait de toute façon
inutilisable pour une bascule.

**`llx_product_stock` porte un `import_key` qu'aucune classe n'écrit.** La colonne existe et
son index unique en ferait une clé d'idempotence idéale, mais aucune classe du coeur n'a
`table_element = 'product_stock'` et `_create()` ne la touche pas. L'écrire aurait imposé
une requête directe, que la règle du module interdit.

L'idempotence passe donc par **`inventorycode`**, écrit par `_create()` et dont la vocation
déclarée dans le coeur est précisément de regrouper plusieurs mouvements en une opération.
Tous portent `SAGE:OUVERTURE` — le client filtre dessus dans **Produits > Stocks >
Mouvements** pour retrouver la reprise en entier.

**`MouvementStock::delete()` ne recalcule rien.** Il retire la ligne d'historique et rien
d'autre : ni le stock par entrepôt, ni le stock dénormalisé du produit, ni le coût moyen. Le
stock resterait en place, privé de sa trace d'origine. La purge contre-passe donc chaque
mouvement avant de supprimer les deux lignes — vérifié : 5 687 traités, 0 échec, tout revient
exactement à zéro.

### Ce que le script ne fait pas

- **12 références absentes de `f_article`** et une ligne sans référence du tout (−1 826
  unités) : écartées et signalées.
- **`f_consigne`** — 279 lignes de stock déposé chez des clients, cumul −4 630 — reste hors
  périmètre, rappelé à chaque passage. Le reprendre supposerait un entrepôt par dépositaire,
  soit un choix de modèle de gestion et non un stock d'ouverture.
- **2 seuils d'alerte négatifs** (−200) ramenés à zéro : ils ne se seraient jamais déclenchés.


## [0.6.0] — 2026-07-29

### Reprise des entrepôts et des emplacements

- **Nouveau script `warehouse`** : un entrepôt principal repris de `f_depot` avec son
  adresse, puis **717 sous-entrepôts** correspondant aux emplacements réellement occupés,
  rattachés à plat en dessous.
- L'ancien ERP ne connaît qu'un seul dépôt réel. Le second, « Siege boutique.aero », est
  une coquille de 11 826 lignes toutes à zéro ; un troisième, non déclaré, apparaît sous le
  numéro 0 avec cinq lignes négatives. Tous deux sont écartés.
- **Seuls les emplacements occupés sont créés** — 810 sur 1 006. Créer les 196 autres
  reviendrait à peupler les sélecteurs d'entrepôt d'entrées vides ; ils seront créés au
  passage suivant s'ils reviennent à l'usage.

### Les libellés d'emplacement, retrouvés hors des données

Les emplacements n'existaient dans la source que sous forme de numéros : la table de
correspondance de Sage n'a pas été répliquée, et l'export de l'ancien ERP ne montre pas
les identifiants.

Ils ont été extraits du **code HTML de l'interface**, où l'attribut `data-dp-no` des cases
à cocher les porte, puis rangés dans `f_emplacements` — seule table de ce jeu qui ne vienne
pas de Sage. Le script de création est livré dans `data/f_emplacements.sql` pour être
rejoué en production. Couverture : **810 des 819 numéros utilisés, soit 98,9 %**.

> Un alignement par position avait été envisagé sur l'export CSV, faute d'identifiant. Il
> était **faux** : sa première ligne correspond au numéro 1014, pas 1. L'appliquer aurait
> placé des milliers d'articles au mauvais endroit sans que rien ne le signale.

### Une contrainte de Dolibarr à connaître

`uk_entrepot_label` porte sur **(libellé, entité)** et non sur (libellé, parent) : un nom
d'entrepôt est unique dans toute la base. Deux conséquences :

- **93 emplacements sont fusionnés** avec un homonyme — huit s'appellent « BOUTIQUE ».
  L'entrepôt existant est réutilisé, le premier numéro rencontré l'emportant.
- Le jour où la hiérarchie sera affinée, il restera impossible d'avoir un « RANG-A » sous
  deux étagères différentes. Sans conséquence ici : les libellés portent déjà leur chemin
  complet — « S1-A15-4 » se lit salle 1, allée A15, niveau 4 —, la hiérarchie fine pourra
  donc être construite **sans jamais les renommer**.

Trois emplacements occupés n'ont aucun libellé dans la source ; Dolibarr refusant un
entrepôt sans nom, leur numéro d'origine en tient lieu. Ils existent toujours dans l'ancien
ERP : les y nommer suffirait à retrouver leur libellé au passage suivant.

### Un entrepôt « À localiser » pour les emplacements disparus

Huit numéros encore portés par 114 articles n'existent plus dans l'ancien ERP — 605 à 611
et 639. Ce ne sont pas des trous d'extraction : leurs voisins immédiats sont bien présents.
Quelqu'un les a supprimés sans réaffecter leur contenu.

Ces articles auraient rejoint l'entrepôt principal, mêlés aux quelque 2 000 lignes qui n'ont
simplement jamais été rangées. Ils sont désormais regroupés dans un entrepôt dédié, dont la
description porte la liste des numéros concernés.

Le nom est une consigne plutôt qu'un constat — leur localisation physique est à retrouver —
et son accent initial le fait remonter en tête des sélecteurs d'entrepôt, où il restera
visible tant que le rangement n'aura pas été fait.

Sur les 114 articles, 99 ont un stock à zéro : **15 seulement demandent une intervention**,
pour 161 unités.

### Le piège de la création d'entrepôt

`Entrepot::create()` insère une ligne minimale puis appelle `update()`, qui écrit sans
condition `statut`, `warehouse_usage` et `fk_user_author` depuis les propriétés de l'objet.
Les laisser vides donne un entrepôt **fermé**, d'usage `0` — valeur qui n'est ni interne ni
externe. C'est exactement l'état des trois entrepôts de démonstration déjà en base. Le
script les positionne avant l'appel.

Bonne surprise en revanche : `update()` écrit `import_key`, le marqueur de reprise est donc
posé dès la création, sans la seconde passe qu'imposent les tarifs fournisseurs.


## [0.5.0] — 2026-07-28

### Reprise des tarifs fournisseurs

- **Nouveau script `supplierprice`** : les 15 962 lignes de `f_artfourniss` deviennent des
  références et des prix d'achat sur les fiches produit. Les deux extrémités sont retrouvées
  par leur `ref_ext` — jamais par la référence produit, reformatée en `#00001`, ni par le
  code fournisseur, régénéré par Dolibarr.
- **Prix d'achat enfin repris.** Il avait été volontairement écarté de la reprise des
  articles : il relève du couple produit/fournisseur, pas de l'article.
- **Les devises sont conservées** — 594 lignes en dollars, 13 en dollars canadiens, 7 en
  livres.

### Deux pièges désamorcés

**Un index unique que 3 108 lignes violent.** Dolibarr identifie une ligne tarifaire par
`(ref_fourn, fk_soc, quantity)` : il postule qu'une référence désigne un seul article chez
un fournisseur. La source ne respecte pas ce postulat — 1 033 lignes partagent une référence
et 2 128 n'en ont aucune. Chez F274, seize articles portent la référence `Chemise`, ce sont
les tailles d'un même chemisier. Or `update_buyprice()` appelé sans identifiant de ligne
**supprime la place occupée avant d'insérer** : près de 2 600 lignes auraient disparu sans
la moindre erreur, le rapport annonçant un succès complet.

- Les références partagées sont **désambiguïsées par la référence du produit** —
  `Chemise (#10475)` —, celles qui manquent ou ne veulent rien dire (`---`, `///`, `.`)
  sont remplacées par elle. Les collisions sont repérées avant le parcours, un traitement
  par lots ne pouvant les voir passer autrement.
- La carte des collisions est construite **en PHP et non en SQL** : `TRIM()` de MySQL ne
  retire pas les tabulations, contrairement à PHP, et six références en portent. Une
  détection en SQL aurait laissé passer ces cas-là.
- **`update_buyprice()` ne part jamais en mode insertion.** `add_fournisseur()` est appelé
  d'abord, ce qui pose l'identifiant de ligne et neutralise la branche destructrice. Une
  collision venue de la cible plutôt que de la source — ligne posée par la boutique, saisie
  manuelle — est détectée avant écriture.
- Contrôle de recette dédié : le nombre de lignes en base doit égaler créations plus
  adoptions. Un écart est la signature exacte de la suppression silencieuse.

**Le multidevise décide du prix en euros.** Le module étant actif, `update_buyprice()`
exécute toujours `prix = prix en devise / taux` : le prix en euros qu'on lui fournit est
ignoré, et ne rien passer côté devise écrit un tarif **à zéro**.

- Le taux implicite de la source varie de 0,28 à 2,17 sur une même devise. Il est conservé
  lorsqu'il approche celui de l'instance à 20 % près — les deux prix sont alors ceux de la
  source, au centime —, remplacé par le taux officiel sinon. Sur les données actuelles,
  397 lignes gardent leur taux et 91 basculent, chaque bascule étant signalée.
- Les lignes annonçant une devise sans montant en devise repartent en euros.

### Idempotence sans `ref_ext`

La table cible n'a pas de `ref_ext`. Le marqueur va donc dans `import_key`, prévue pour cela
par le coeur et écrite via l'API `ProductFournisseurPrice` — la règle du module, aucune
écriture SQL directe, reste tenue. La clé y désigne **la ligne source** et non le couple
article/fournisseur : quatre articles sont référencés deux fois chez un même fournisseur,
avec deux références et deux prix, ce qu'une clé par couple aurait fait s'écraser à chaque
passage.

Les lignes déjà présentes en base ne sont pas marquées : la reprise les complète sans les
revendiquer, et la purge ne les supprimera pas.

### Ce que la reprise ne fait pas

- `PRODUCT_USE_SUPPLIER_PACKAGING` n'est **pas** activée, bien que le conditionnement soit
  repris dans `packaging` : cette constante modifie l'arrondi des quantités d'achat pour
  toute l'instance, c'est un arbitrage du client.
- `AF_TypeRem` étant vide sur toute la table, rien ne prouve que `AF_Remise` soit un
  pourcentage. Les 58 valeurs sont reprises comme telles et listées au rapport pour
  validation.

### Corrigé

- **Le coût de revient était pris dans la mauvaise colonne.** Le script `product`
  privilégiait `AR_PrixRU`, dont le nom promet un prix de revient, et ne basculait sur
  `AR_CoutStd` qu'en dessous du centime. Or la source ne recalcule jamais `AR_PrixRU`
  quand le tarif fournisseur change : **2 767 articles sur 13 814 portaient ainsi un coût
  inférieur à leur propre prix d'achat** — l'article 10514 affichait 35,26 € pour un achat
  à 75,92 €. Un coût sous-évalué gonfle la marge que Dolibarr affiche.

  La preuve est dans la marge : rapporté au prix de vente, le prix d'achat donne un
  coefficient moyen de 1,84, quand le prix de revient en donne un de plusieurs millions.
  `AR_CoutStd`, lui, égale exactement le prix d'achat sur 11 836 articles, et ne compte
  aucune valeur aberrante là où `AR_PrixRU` en a 1 628. **La règle est inversée** : coût
  standard d'abord, prix de revient en repli pour les 14 articles qui n'en ont pas.

- **`supplierprice` signale les achats au-dessus du prix de vente.** 54 articles sont
  achetés plus cher qu'ils ne sont vendus. Impossible de savoir lequel des deux prix est
  faux : ils sont listés nommément en fin de passage, pour vérification par le client.

- Le récapitulatif de `migrate.php` annonçait les lignes ignorées comme « déjà migrées ».
  C'est faux dès qu'un script écarte une ligne qu'il ne sait pas rattacher : le libellé ne
  préjuge plus de la cause, que le rapport détaille.


## [0.4.0] — 2026-07-28

### Reprise du catalogue

- **Nouveau script `category`** : les 504 rubriques de `f_catalogue` deviennent des
  catégories produit, avec leur hiérarchie sur quatre niveaux. C'est le classement
  commercial de l'ancien ERP, à ne pas confondre avec `FA_CodeFamille`, simple
  regroupement de TVA.
- **Les articles y sont rattachés** par `CL_No1`, `CL_No2` et `CL_No3` — un produit peut
  donc porter jusqu'à trois catégories, en plus de sa famille de TVA.
- **Les rubriques racines sont greffées sous « Accueil »**, la catégorie que la boutique
  impose en tête de son arborescence, pour rejoindre l'arbre existant au lieu de vivre à
  côté. La catégorie est retrouvée **par son libellé** : aucun identifiant à configurer,
  celui-ci variant d'une instance à l'autre. Le script s'arrête si plusieurs homonymes
  existent, et affiche en fin de passage la catégorie retenue.
- **Les parents sont créés à la demande**, récursivement : le parcours suit `CL_No`, pas
  la profondeur, et une rubrique se présente parfois avant son parent.
- **Sept doublons du catalogue sont fusionnés** — même libellé sous le même parent, dont
  un ne différant que par la casse et ses points de suspension. Dolibarr refuse ces
  homonymes ; la catégorie existante est réutilisée.
- La hiérarchie d'une catégorie adoptée n'est **jamais** modifiée : celle de la boutique
  fait autorité.

### Reprise des articles

- **Nouveau script `product`**, avec trois traitements selon l'origine de l'article :
  création lorsque l'article n'existe nulle part, adoption pour ceux déjà remontés par
  PrestaShop via `llx_prestasync_product`, et rapprochement de secours par la référence
  quand le lien boutique manque. Ce dernier cas est un garde-fou : sans lui, un passage
  sur la source complète créerait près de 15 000 produits en doublon.
- **L'adoption ne remplace rien** : référence, libellé, prix, statut et type restent ceux
  de la boutique. La reprise n'apporte que ce qui manque — poids, code-barres, coût de
  revient, disponibilité, suivi, catégorie —, précisément ce que la synchronisation ne
  renseigne pas.
- **Chaque produit créé est déclaré dans `llx_prestasync_product`**, comme pour les tiers,
  afin qu'une synchronisation ultérieure le retrouve au lieu d'en créer un second.
- **Format de référence** : les références numériques deviennent `#00010`, celles
  comportant des lettres restent inchangées. Vérifié au préalable : aucune référence ne
  dépasse cinq chiffres et la normalisation ne provoque aucune collision. La référence
  d'origine reste dans `ref_ext`, qui servira de clé aux reprises suivantes.
- **Prix** : `AR_PrixVen` est repris en HT ou en TTC selon `AR_PrixTTC`, le montant
  complémentaire étant recalculé. Le taux découle de la famille — `V20` → 20 %,
  `V5` → 5,5 % —, le référentiel Sage des familles n'ayant pas été importé.
- **Coût de revient** : `cost_price` reçoit `AR_PrixRU` et non le prix d'achat, qui relève
  du couple produit/fournisseur. 1 628 prix de revient étant aberrants — inférieurs au
  centime —, le coût standard prend le relais et en récupère 1 458.
- **Code douanier** : `lib_fiscal` alimente `customcode` (4 830 articles), les valeurs
  `undefined` étant écartées.
- **Auteur et éditeur** dans deux extrafields dédiés du module `aerotoolbox` — 2 923 et
  3 270 valeurs réelles, les `#N/A` filtrés.
- **Garantie** : `sous_garantie` alimente `aerotb_warranty`. La source ne porte aucune
  durée (`AR_Garantie` est vide), `aerotb_warranty_months` reste donc à renseigner.
- Les catégories de familles de TVA sont préfixées **ADD**, du nom que le client donne à
  son ancien ERP.
- **Disponibilité et suivi** alimentent les extrafields du module `aerotoolbox`, dont les
  dictionnaires correspondent exactement aux libellés de la source. Le script s'interrompt
  avec un message explicite si ces dictionnaires sont absents.
- **Familles de TVA** reprises en catégories produit (`ADD V20`, `ADD V5`…), créées via
  l'API Categorie pour rester manipulables par le client, et conservées en parallèle du
  classement commercial.
- Les articles marqués `PRESTATION` deviennent des services, les autres des produits.
- Poids, code-barres, date de création d'origine et statut sont également repris.

### Corrigé

- `--update` n'avait aucun effet sur les catégories : le cache de résolution des parents
  court-circuitait la mise à jour.
- Un déplacement de catégorie vers une place déjà occupée faisait échouer la ligne au lieu
  d'être signalé.
- L'absence de `--update` écrasait le réglage des scripts qui agissent par nature sur des
  enregistrements existants.
- La purge des catégories ne trouvait rien : la table manquait parmi les cibles connues,
  et l'entité était calculée sur le nom de table plutôt que sur l'élément Dolibarr — les
  deux diffèrent pour les catégories (`categorie` contre `category`).

### Correction du double encodage

Les champs libres de `f_article` contiennent de l'UTF-8 encodé deux fois
(`ArrÃªt Ã  Ã©puisement du stock`), ce qui faisait échouer le rapprochement de 306 suivis.
La correction ne repose pas sur la détection d'un motif — une expression régulière sur ces
octets s'est révélée peu fiable — mais sur la conversion elle-même : un texte doublement
encodé redevient de l'UTF-8 valide une fois reconverti, un texte sain non. Les chaînes
saines ressortent inchangées.


## [0.3.0] — 2026-07-28

### Reprise des désinscriptions newsletter

- **Nouveau script `newsletter`** : les 2 621 tiers portant `Unsubscribe_Newsletter` sont
  ajoutés à la liste des adresses exclues des envois en masse. Passe par
  `Societe::setNoEmail()`, qui vérifie l'existence avant d'insérer — le script est donc
  rejouable, ce qu'un second passage confirme.
- Ne parcourt que les tiers concernés (2 621 sur 157 102) plutôt que toute la table.
- 4 tiers désinscrits n'ont pas d'adresse e-mail : rien n'est reprenable pour eux, ils
  sont comptés et signalés.
### Purge spécialisable

La purge supprimait jusqu'ici les objets portant le marqueur de la reprise, dans la table
cible du script. Ce raccourci ne tient plus dès qu'un script écrit ailleurs que dans la
table des objets qu'il manipule : `purge.php newsletter` aurait supprimé les 157 102
tiers, alors qu'il n'en crée aucun.

- La purge devient une méthode du socle (`AeroMigrationRunner::purge()`), que chaque
  script peut spécialiser ; `purge.php` n'est plus qu'un lanceur.
- **La purge des désinscriptions réinscrit les adresses** exclues par la reprise, via
  `Societe::setNoEmail(0)`, sans toucher au moindre tiers. Vérifié : 2 617 réinscriptions,
  157 102 tiers intacts, et une désinscription antérieure à la reprise préservée.
- Chaque script annonce désormais ce qu'il va défaire avant de le faire.

### Performance

- **Génération des codes tiers sortie du chemin critique.** Dolibarr recalcule le prochain
  numéro à chaque création, par un `SELECT MAX(CAST(SUBSTRING(...)))` que le commentaire du
  coeur annonce comme indexé — il ne l'est pas. Mesuré à 134 ms sur 113 000 tiers, et
  croissant : le coût total de la reprise était quadratique. Le compteur est désormais lu
  une seule fois puis incrémenté en mémoire, à codes rigoureusement identiques.
  **Débit : 13,8 → 93 tiers/s**, soit une reprise complète en une dizaine de minutes au
  lieu de près de deux heures.

### Corrigé

- L'option `--update` absente écrasait le réglage des scripts qui agissent par nature sur
  des enregistrements existants : ils ignoraient alors toutes leurs lignes.


## [0.2.0] — 2026-07-27

### Rapprochement avec la boutique en ligne

La cible n'est pas vierge : le module Prestasync y a déjà créé des tiers venus de
PrestaShop. La reprise en tient compte, au lieu de les ignorer.

- **Codes clients générés par Dolibarr** au lieu d'être repris de `CT_Num`. Reprendre le
  code Sage heurtait de front l'index unique `uk_societe_code_client` sur les tiers déjà
  remontés par la boutique : la création échouait et leurs données Sage étaient perdues.
  `CT_Num` reste conservé dans `ref_ext` et pourra redevenir le code tiers plus tard.
- **Adoption des tiers déjà présents**. Un tiers reconnu via `llx_prestasync_customer`
  (`fk_customer_presta` = `f_comptet.id_externe`) n'est plus recréé : la reprise ne remplit
  que les champs laissés vides et pose son `ref_ext`. Son nom, son code client et son
  statut ne sont jamais modifiés.
- **Déclaration des nouveaux tiers à la boutique**. Chaque tiers créé est inscrit dans
  `llx_prestasync_customer`. Sans ce lien, la première commande du client ferait créer un
  second tiers : le module de synchronisation ne recherche aucun tiers existant avant d'en
  créer un, par choix de sécurité de son éditeur.
- Un identifiant boutique ne peut désigner qu'un seul tiers : les 313 valeurs
  d'`id_externe` partagées dans la source sont écartées du lien et signalées.
- Nouveau compteur **« Adoptés »**, distinct des créations, et rapport de fin de passage
  sur les liens boutique créés ou refusés.
- Le mode simulation annonce la ventilation créations / adoptions sans rien écrire.

### Corrigé

- **Adoption jamais déclenchée.** Les liens étaient indexés par `fk_soc_doli`, en supposant
  qu'un tiers n'en porte qu'un seul. C'est faux : l'index n'est pas unique et la contrainte
  porte sur le triplet `(fk_presta, fk_soc_doli, fk_customer_presta)`. Les correspondances
  s'écrasaient silencieusement. L'index va désormais de l'identifiant boutique vers le
  tiers, et un tiers ne peut plus être adopté deux fois.
- **Catégorie tarifaire absente sur les tiers adoptés.** `Societe::fetch()` ne restitue pas
  la valeur brute de `price_level` : lorsque le multiprix est actif et la colonne vide, il
  retourne `1` par défaut. Le test « la fiche a-t-elle déjà un niveau » était donc toujours
  vrai. La valeur est maintenant relue directement en base.

### Ajouté au socle

- Curseur qualifié (`srcCursorSqlField`), nécessaire dès que la source est une jointure.
- `formatPhone()` et `normalizeLabel()` remontés en commun, partagés par les scripts.
- Bornage du nombre d'erreurs conservées en mémoire : lancer les contacts avant les tiers
  accumulait jusqu'à 155 000 objets sans utilité, les compteurs restant exacts.
- `previewAction()`, surchargeable, pour que la simulation annonce l'action réelle.


## [0.1.0] — 2026-07-27

### Module initial

- **Socle de reprise** (`AeroMigrationRunner`) : parcours par lots, pagination par curseur,
  idempotence par `ref_ext`, transaction par ligne, mode simulation, reprise après
  interruption, collecte des erreurs.
- **Reprise des tiers** (`f_comptet` → `Societe`) : identité, type de tiers, adresse avec
  résolution du pays et déduction du département, coordonnées, identifiants légaux,
  statut, catégorie tarifaire, devise, note et date de création d'origine.
- **Reprise des contacts** (`f_contactt` → `Contact`) : identité, civilité déduite du
  tiers, fonction, coordonnées et adresse reprises du tiers, rattachement par `ref_ext`.
- **Lanceur en ligne de commande** (`migrate.php`) : simulation, limite, taille de tranche,
  reprise au curseur, mise à jour des enregistrements déjà repris.
- **Purge ciblée** (`purge.php`) : suppression des seuls enregistrements marqués par la
  reprise, via l'API Dolibarr, pour rejouer un passage.
- **`ANOMALIES.md`** : relevé des défauts de la source, des décisions prises et des pièges
  Dolibarr rencontrés.
