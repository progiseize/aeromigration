# Journal des modifications — Reprise de données

Toutes les évolutions notables du module sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et le module respecte le [versionnage sémantique](https://semver.org/lang/fr/).


## [0.24.0] — 2026-08-21

### Ajouté — `import_birthdays.php` : les dates de naissance de la boutique sur les contacts

L'ancien ERP n'a jamais tenu les dates de naissance (148 sur 157 803) : la source est
PrestaShop (66,9 % des clients). L'extraction
`migrationdata/naissances_contacts_*.csv` ne porte que des CLÉS PORTABLES — code tiers ADD
(99,8 % des lignes, résolu par le `ref_ext` que la reprise pose), e-mail en repli s'il ne
désigne qu'un tiers — donc valable telle quelle au jour J, à régénérer depuis la dernière
photo comme les fichiers de liaison. Le dossier `migrationdata/` rejoint le `.gitignore` :
données personnelles, jamais versionnées.

La date se pose sur le CONTACT (`llx_socpeople.birthday`, le champ natif), pour les TIERS
PARTICULIERS uniquement. Tiers multi-contacts (adresses de livraison dupliquées par la
boutique) : prénom+nom identiques = la même personne, le contact le plus ancien reçoit la
date ; nom seul à unicité stricte (couples, familles) ; aucune correspondance = écarté et
compté. Rejouable, `--limit` pour un lot d'essai. Première simulation : 102 837 dates à
poser sur 103 633 lignes (99,2 %), 5 ambigus.

## [0.23.0] — 2026-08-20

### Changé — `product --only=status` : le vide d'ADD fait foi, les couples du cliché 2019 sont retirés

Jusqu'ici un article jamais qualifié par la surcouche ADD (champs numériques à zéro) n'était
« pas touché » — et ~5 900 d'entre eux gardaient le couple hérité du cliché tableur de 2019,
posé à la reprise initiale sans validation. Résultat : le filtre « À qualifier » de la liste
produit (aerotoolbox 1.17.0) n'en montrait que 1 904 là où ADD en compte ~7 800.

Décision du 20/08/2026, prolongement de « Dolibarr doit refléter ADD » : **ADD non qualifié →
couple Dolibarr VIDÉ** (par la porte `aerotb_status_write`, qui accepte le vide). « En vente /
En achat » ne bougent pas — aucune combinaison ne correspond au vide, le no-op est celui du
coeur — et ces articles sortent du périmètre de poussée boutique, comme tout couple absent.
Un article déjà vide n'est pas retouché ; la qualification dans ADD reste le chemin du retour.

## [0.22.0] — 2026-08-20

### Ajouté — `renumber_customer_orders.php` : la série CO sur les commandes clients

Dernière renumérotation rétroactive. Même règle et même squelette que les factures, périmètre
complet confirmé par le client le 20/08/2026 : numéro ADD avant la coupure (`CI-235850` →
`CO<millésime>-235850`), séquence par exercice toutes provenances confondues après — la
boutique garde sa propre référence côté PrestaShop, et `ref_client` porte déjà le couple
« id / référence ».

Prestasync ne perd rien, vérifié dans son code : le flux vivant retrouve les commandes par
`llx_prestasync_order` (rowid), le contrôle par référence n'étant qu'un filet anti-doublon à
la création d'une commande SANS liaison. Contrainte d'ordre au jour J, déjà celle du document
de mise en production : renuméroter après le rattrapage borné et la pose des liaisons.

Les ~28 700 PDF du rattrapage boutique sont supprimés avant la passe (garde-fou) et ne se
régénèrent pas en masse : décision client du 20/08/2026, la boutique les obtiendra **à la
demande** via l'endpoint aeropresta du lot 2 — commandes ET factures.

## [0.21.0] — 2026-08-20

### Ajouté — `renumber_supplier_orders.php` : la série COF sur les commandes fournisseur

Même règle et même squelette que `renumber_invoices.php` (coupure 01/10/2023, numéro ADD
avant, séquence six chiffres par exercice après, série unique `COF`). Le numéro source vient
du `ref_ext` — jamais de la référence actuelle, hétéroclite (`CF7344`, `BCF…`, un
`undefinedBCF9900` d'incident d'écran) et assainie d'un coup. Garde-fou documents, contrôles
d'unicité, pré-phase anti-collision, brouillons `(PROV` ignorés, rejouable. Il affiche en fin
de passage la dernière référence de la série en cours, celle que le modèle COF d'aerotoolbox
1.16.0 continuera.

## [0.20.0] — 2026-08-20

### Changé — les réceptions naissent clôturées, avec leur référence définitive

Découverte au passage en revue : `llx_reception` était **vide sur les deux instances** — le
rejeu du 19/08 avait sauté les réceptions. Plutôt que de rejouer à l'identique, le script
adopte les acquis des expéditions (ANOMALIES P20) :

- **modules stock/lots neutralisés en mémoire du seul processus** : les réceptions sont
  livrées **clôturées** — elles restaient « validées » à vie, faute de pouvoir les clôturer
  sans ajouter leurs 387 502 unités au stock. Le rapport recompte `llx_stock_mouvement`
  avant/après, et le garde-fou historique sur `STOCK_CALCULATE_ON_RECEPTION` reste en place ;
- **référence définitive `REF<millésime>-…` posée à la création** (règle des factures,
  coupure 01/10/2023) : chiffres de la pièce `BLF` avant, séquence par exercice après —
  aucune renumérotation rétroactive à prévoir pour les réceptions ;
- les effets de bord de `setClosed()` sur la commande fournisseur (passage en « reçue ») sont
  défaits, comme pour les commandes clients : statut relevé avant, restauré après.

⚠ Comme les expéditions, ces réceptions doivent RESTER clôturées : rouvrir puis reclôturer
depuis l'écran ajouterait cette fois les quantités au stock.

Piège appris au passage (consigné dans ANOMALIES P20) : la neutralisation ne retire pas que
des contrôles — `Reception::addline()` ne pose le produit sur la ligne que dans son bloc
`isModEnabled('stock')` (reception.class.php:981), et `create()` refusait alors toutes les
lignes. Le script pose désormais le produit lui-même.

## [0.19.1] — 2026-08-20

### Ajouté — `close_delivered_orders.php` : les commandes intégralement livrées classées « Livrée »

Le manque relevé après le passage de `shipment` : les commandes couvertes par leurs
expéditions restaient « validées ». Deux causes se cumulaient — la comparaison du coeur exige
que TOUTE ligne de type produit soit couverte, or presque chaque commande porte une ligne que
rien n'expédie jamais (l'article « Transport » des commandes boutique, les pseudo-articles
`PORT*`/`RETRAIT*` de l'ancien ERP, le texte libre) ; et `shipment` restaure le statut posé
par `customerorder`, ce qui annulait aussi les rares clôtures légitimes.

La règle métier est donc appliquée à part, après coup : **« Livrée » quand toutes les lignes
d'articles sont couvertes par les expéditions reprises clôturées**, texte libre, services et
famille logistique (`Transport`, `PORT*`, `*RETRAIT*`, `ECART`, `EMBALLAGE`, `FRANCO`)
ignorés. Périmètre : les commandes en statut validée ou expédition en cours — les fermées le
sont déjà, annulées et brouillons intouchés, livraisons partielles listées jamais forcées.
Écriture par `Commande::cloture()`, simulation par défaut, rejouable. Mesuré au premier
passage : 27 511 classables sur 27 790 touchées, 279 partielles réelles.

## [0.19.0] — 2026-08-20

### Ajouté — `migrate.php proposal` : les devis clients

Reprise des devis (type 0 du domaine vente) en propositions commerciales, selon la grille de
statuts donnée par le client le 20/08/2026 — l'écran ADD affiche « Terminée » là où le
dictionnaire de l'export disait encore « Devis refusé », le libellé ayant été renommé :

- **1 « envoyé »** (617) → validée, ouverte ; **2 « refusé »** (13) → close non signée ;
  **9 « terminée », c'est-à-dire transformée en commande** (667) → close **signée**, la date
  de signature réécrite à la date de la pièce (`closeProposal()` la daterait du passage).
  Aucun rattachement à la commande : la source n'en conserve pas le lien (2 cas sur 667) ;
- écartés : **statut 0 « brouillon »** (un devis de 2020 et les 5 ordres de réparation OR du
  carnet atelier), les **146 annulés** (« aucun intérêt », décision client) et les 72 sans
  aucune ligne ;
- **référence définitive dès la création**, même règle que factures et expéditions :
  `DE<millésime>-<chiffres>` avant le 01/10/2023, séquence par exercice après — calculée de
  la seule source, contrôles d'unicité et de collision avant toute écriture ;
- **aucun PDF généré** : `closeProposal()` fabrique sinon le document de chaque devis clos
  (propal.class.php:2709) — `MAIN_DISABLE_PDF_AUTOUPDATE` est posée en mémoire du seul
  processus, avec `PROPALE_NOCHECK_ONSALE_PRODUCTS_ONVALID` pour les articles retirés de la
  vente que l'historique référence.

## [0.18.0] — 2026-08-20

### Ajouté — `migrate.php shipment` : les expéditions clients

Reprise des 62 519 « préparations de livraison » d'ADD (type 2 du domaine vente) en
expéditions Dolibarr, adossées ligne à ligne aux commandes clients reprises — périmètre
complet décidé le 20/08/2026, le client perdant tout accès à ADD après la bascule. Les 316
« bons de livraison » type 3, carnet atelier/SAV (ordres de réparation compris), sont écartés
du périmètre à la demande du client.

Les points structurants, arrêtés en séance :

- **le stock ne bouge pas** : le module lots force la sortie de stock à la clôture des
  expéditions, mais le coeur ne lit que la configuration EN MÉMOIRE — le script retire
  « stock », « productbatch » et `PRODUIT_SOUSPRODUITS` de la sienne, pour son seul processus.
  Rien n'est désactivé en base, l'application reste intacte pour les autres, et le rapport
  vérifie que `llx_stock_mouvement` n'a pas gagné une ligne. Corollaire au rapport : ne JAMAIS
  rouvrir puis reclôturer une expédition reprise depuis l'écran ;
- **tout est clôturé**, plus le drapeau « facturée » quand la commande est citée par une
  facture de la source — les statuts ADD ne portent rien d'exploitable (le « A préparer »
  coiffe des pièces soldées). Les effets de bord du coeur sur la commande d'origine
  (« expédition en cours » à la validation, clôture au solde) sont défaits : son statut,
  posé par `customerorder` d'après la source, est relevé avant et restauré après ;
- **référence définitive dès la création** — aucune expédition n'existait en cible : même
  règle que les factures (client, 20/08/2026), `BL<millésime>-<chiffres ADD>` avant le
  01/10/2023, séquence à six chiffres par exercice après, calculée de la seule source donc
  stable de passage en passage. `create()` et `valid()` respectent une référence posée
  d'avance : le module de numérotation n'est jamais sollicité ;
- **rattachement article + prix** : la source ne garde pas le numéro de la ligne de commande
  d'origine. L'appariement se fait par article, départagé par le prix unitaire (615 des 864
  doublons article/commande ont des prix distincts), à égalité dans l'ordre du document en
  suivant les quantités restantes — y compris celles servies aux passages précédents ;
- **rien ne se perd** : les 6 797 lignes sans article — numéros de série livrés, compositions
  de produits montés, franco — sont recopiées dans la note privée de l'expédition. Les 361
  documents sans commande se réduisent à 296 coquilles vides et ~65 pièces dont une vingtaine
  se rattrape par `DO_NoWeb` ; le reliquat est écarté et listé.

## [0.17.0] — 2026-08-19

### Ajouté — `renumber_invoices.php` : la numérotation définitive du parc

Règle client : **coupure au 1er octobre 2023** (début de l'exercice 2023-2024, les exercices
allant d'octobre à septembre). Avant, chaque facture reprend son numéro d'émission d'origine —
`FA<millésime>-<chiffres du numéro ADD>`, partie chiffrée telle quelle (FAC216762 →
FA1516-216762), avoirs en `AV<millésime>-`. Après, séquence chronologique à 000001 par exercice
et par série, toutes provenances confondues, compteur à six chiffres — celui que
`mod_facture_aero` continue au quotidien.

Le script refuse de tourner si des documents de factures subsistent (répertoires,
`llx_ecm_files`, `last_main_doc`) : décision du 19/08/2026, les PDF des instances de test —
jamais transmis qu'à la boutique de test — ont tous été supprimés, et se régénèrent à la
demande APRÈS renumérotation, avec le numéro définitif. La référence est réécrite par UPDATE
direct, exception assumée : le cœur n'offre aucune API pour renommer une facture validée, et
`uk_facture_ref` protège l'opération.

Les cibles sont calculées de données stables (numéro source, date, type) : déterministe et
rejouable, contrôle d'unicité totale avant toute écriture, `--limit` pour un lot d'essai. Une
**pré-phase** déplace en temporaire les références actuelles qui occupent déjà une cible — les
avoirs récents portent le format définitif (`AV2526-000094`) dès leur création par
`mod_facture_aero`, et l'ordre des renommages passerait sinon par des états intermédiaires en
collision (18 avoirs l'ont prouvé au premier passage). Exécuté en local le 19/08 : 183 457
renommées, 19 séries de FA1516 à AV2526, zéro doublon, zéro reste.

## [0.16.3] — 2026-08-19

### Retiré — les catégories de familles (ADD V20, V5, DIVERS, AREAFFECTER)

Le client y a renoncé le 19/08/2026 : la reprise `product` ne crée plus ces quatre catégories et
n'y rattache plus rien — seul le **classement commercial du catalogue** (CL_No1-3, script
`category`) subsiste. Les quatre catégories existantes et leurs 15 907 rattachements ont été
supprimés des deux instances par l'API Categorie. La note de mise en production gagne au passage
le pré-requis PHP CLI (`-d max_execution_time=0` : sous Linux la limite compte le temps CPU et
fauche les passages calculatoires, vécu sur le test).

## [0.16.2] — 2026-08-19

### Ajouté — `product --only=status` : le couple disponibilité/suivi aligné sur ADD

**La source fait foi** (décision client du 19/08/2026) : le couple vient des champs numériques
`disponibilite_origine`/`suivi_origine` de `f_article` — l'origine brute, pas l'état de rupture.
Les identifiants ADD 1 à 7 coïncident avec le dictionnaire aerotoolbox, l'id 8 d'ADD est notre 10
(voir ANOMALIES A3). L'écriture passe par `aerotb_status_write()`, l'unique porte : le suivi des
lots reste dérivé des composants (ADD ignoré sur cette dimension, neuf lots concernés), et
« En vente / En achat » suivent la combinaison. Les 7 845 articles jamais qualifiés par la
surcouche (champs à zéro, vérifiés vides à l'écran ADD sur dix sondages) ne sont **pas
touchés** : le client les qualifie dans ADD (`rapports/ARTICLES_A_QUALIFIER_ADD_*.csv`), et un
passage suivant les reflète. Premier passage local : 2 990 alignés, 5 073 déjà conformes,
rejouable à zéro. Au passage, l'énigme des couples « hors grille » est résolue : les 1 410
produits qui en portent sont tous des « jamais qualifiés » remplis jadis depuis le cliché
tableur (les deux extrafields posés sans validation du couple) — la qualification dans ADD
les résorbera, aucune combinaison à créer.

### Corrigé — la reconstruction des prix de l'ère Sage native se fonde sur les montants de ligne

La reconstruction `PUTTC / (1 + taxe)` de la 0.16.1 était juste sur 62 196 pièces et fausse sur
783 (891 k€ cumulés), pour deux raisons vérifiées sur pièces : des remises de 100 % dont le prix
ne vit que dans le montant (PUTTC à zéro, `DL_MontantTTC` rempli), et des lignes **dupliquées par
l'export avec montants à NULL**, facturées deux ou trois fois au PUTTC. Le montant de ligne fait
désormais foi : `DL_MontantHT` (remise reconstituée), à défaut `DL_MontantTTC` détaxé — et une
ligne sans aucun montant vaut zéro, c'est un doublon d'export, jamais un repli sur le PUTTC. Cas
limite traité : une quantité à zéro dont le montant est porté (une pièce) est reprise en une
unité au montant. Le périmètre de `fix_invoice_zero_amounts.php` passe de « reprises à zéro » à
« en écart avec la source » sur les pièces à PU HT NULL, et converge : rejoué après réparation
complète en local (62 658 + 777 + 1 pièces), il rend **zéro**.

## [0.16.1] — 2026-08-19

### Corrigé — 62 716 factures de l'ère Sage native reprises à 0,00 € : 6,79 M€ restitués

**Toutes les lignes de l'ère `FAC` (oct. 2015 → mars 2019) ont leur prix unitaire HT à NULL** —
seul `DL_PUTTC` est rempli. La reprise construisait depuis le HT : l'ère entière est entrée à
0,00 €, sans une erreur, et le contrôle des montants qui le montrait (« 62 407 écarts FAC,
cumul 6,8 M€ ») avait été mal interprété. Voir ANOMALIES D1.

`mapLine()` reconstruit désormais le prix du TTC et de la TVA de ligne quand le HT est NULL —
vérifié au centime contre `DL_MontantHT` ; un HT réellement à zéro (ligne offerte) reste à
zéro. Le nouveau `scripts/fix_invoice_zero_amounts.php` répare l'existant : périmètre
dynamique (zéro Dolibarr + signature « PU NULL, PUTTC rempli » + source valorisée — les
ventes magasin réglées par avoir, légitimement nulles, sont épargnées), suppression par le
chemin PROV, puis rejeu de `migrate.php invoice` qui recrée au bon montant.

### Changé — les factures aux clients disparus sont reprises, rattachées à « Clients Anonymisés »

311 factures réelles de 2015-2019 (53 460,39 € TTC) étaient écartées : leur client a disparu
de `f_comptet` lui-même — sinistre ADD de 2019 et anonymisations (des fiches au nom brouillé
et un « Anonymized Client » figurent dans la source). La facture se conserve dix ans, la
personne non : **décision client du 19/08/2026**, elles sont désormais créées et rattachées à
un tiers générique **« Clients Anonymisés »** (`ref_ext SAGE:CLIENT_ANONYME`, créé par
`Societe::create()` au premier besoin, idempotent et purgeable comme le reste de la reprise).
Le code client ADD d'origine est conservé dans la note privée de chaque facture. Le contrôle
« document sans ligne » passe désormais avant la résolution du client, pour ne pas créer le
tiers générique au service d'un document vide.

## [0.16.0] — 2026-08-19

### Ajouté — `relink_prestasync.php` : les liaisons boutique survivent à une reprise sur base neuve

Prestasync tient ses correspondances par rowid Dolibarr (`llx_prestasync_customer.fk_soc_doli`,
`llx_prestasync_supplier.fk_soc`) : sur une base neuve, elles sont toutes caduques, et sans elles
il recrée un tiers à chaque commande — les **317 fournisseurs de l'instance de test ont tous été
dédoublés** ainsi, faute de précaution au premier rejeu.

Les liaisons de l'instance actuelle sont exportées en **clés stables** — le code tiers ADD, que la
reprise repose dans `ref_ext` (`SAGE:<CT_Num>`) : `data/liaison_presta_tiers_*.csv` (155 673
clients, couverture totale moins 2 nés-boutique repris par e-mail — jamais par le code client,
généré par Dolibarr à l'import donc différent d'une base à l'autre) et
`data/liaison_presta_fournisseurs_*.csv` (317, dont 292 rattachés au jumeau ADD par le nom —
les liens de test pointaient vers les doublons nés de la boutique). Le script résout chaque clé
vers le rowid de la base courante et repeuple les deux tables de liaison. Simulation par défaut,
`--confirm`, rejouable (les liaisons en place sont ignorées) ; l'écriture directe dans les tables
`llx_prestasync_*` est assumée — elles appartiennent au module de liaison, qui n'offre aucune API.

S'exécute après `migrate.php thirdparty` et **avant tout démarrage de Prestasync** — étape 6 de
MISE_EN_PRODUCTION.md. Les adresses de livraison ne sont pas rattachées : nées de la boutique,
sans clé stable, Prestasync les recrée à la demande.

## [0.15.0] — 2026-08-18

### Ajouté — `align_invoices_add.php` : la règle « ADD fait foi » appliquée au parc de factures

Le client a posé la règle les 17 et 18 août : **avant 2026, la base ADD fait foi sur tout**. Une
facture qui n'existe pas dans ADD n'a pas lieu d'être dans Dolibarr ; une facture qui y existe à
l'état annulé doit exister ici au statut « abandonnée ». À partir de 2026, une vente absente d'ADD
peut être une commande pas encore honorée : signalée, jamais touchée. Depuis la bascule du flux web
vers la boutique (juin 2026), ADD ne fait plus foi.

Le script redérive son périmètre **de la base source au moment où il tourne** — jamais de liste
figée : la base ADD est rafraîchie à volonté depuis l'export CSV, et tout sera rejoué sur la vraie
production. Trois passes, simulation par défaut, `--confirm` pour écrire :

1. **Suppression** des factures nées dans Dolibarr qu'ADD n'a jamais émises — commande annulée dans
   ADD, jamais facturée, ou absente d'ADD. Au 18/08 : 40 factures, 7 084,55 € TTC. Les encaissées
   partent aussi, règlements retirés d'abord — décision client, ces encaissements relèvent du
   prestataire de paiement. Le script englobe et remplace `delete_invoices_cancelled_orders.php`
   (les 311), dont il reprend le chemin de suppression : brouillon, référence `(PROVDEL<rowid>)`,
   `delete()`, répertoire de documents.
2. **Alignement** des factures reprises encore actives alors que leur pièce ADD est annulée — 45 au
   18/08 (18 932,31 €), toutes passées par l'adoption. Règlements retirés, statut abandonnée, comme
   les 666 que le chemin de création avait correctement classées. 40 des 45 ont une facture de
   remplacement active sur la même commande : leur encaissement comptait double.
3. **Signalement** des ventes de 2026 sans facture ADD : 3 expédiées jamais facturées (dont
   2 716,42 € de créances aéroclubs à recouvrer) et 5 commandes en attente.

### Corrigé — deux pièces source se disputaient la même facture adoptée

`loadExistingInvoices()` indexait toutes les factures liées à une commande, **marquées ou non**.
Une commande portant deux pièces source (facture puis corrective, ou avoir) voyait la seconde
pièce arracher le marqueur `ref_ext` posé par la première sur la même facture cible ; à chaque
passage, la pièce dépossédée ré-adoptait — 271 marqueurs rebondissaient ainsi d'un passage à
l'autre, sans dégât mais sans jamais représenter la seconde pièce. Seules les factures **sans
marqueur** sont désormais adoptables : la première pièce adopte, les suivantes créent leur
propre facture, comme la source le raconte. Le rejeu du 18/08 a créé les 271 manquantes
(dont 189 annulées à la source, nées abandonnées) et le passage suivant rend zéro.

### Corrigé — l'adoption d'une facture annulée dans ADD la laissait active et payée

`adoptInvoice()` ne posait que `ref_ext`, fidèle à « marquée, non retouchée ». Mais une facture
créée par la boutique puis annulée dans ADD gardait alors son statut et ses règlements — les 45
de la passe 2 viennent de là (rattrapages Prestasync des 12/06 et 13/08). L'adoption applique
désormais la même règle que la création : règlements retirés, facture abandonnée. Un règlement
partagé avec une facture hors périmètre arrête l'alignement et se signale au rapport.

## [0.14.0] — 2026-08-17

### Ajouté — `sync_kit_tracking.php` : aligner les produits composés sur leurs composants

Le module aerotoolbox 1.13.0 pose une règle : **un lot n'a pas d'état propre, il reflète ses
composants.** Son suivi vient d'eux, un composant qu'on ne commercialise plus rend le lot invendable,
et un lot qu'aucun stock ne permet d'assembler devient indisponible.

Cette règle s'applique à chaque enregistrement — donc **seulement aux fiches qu'on rouvre**. Les lots
déjà en base gardent l'état qu'ils portaient : un lot inassemblable depuis toujours n'a jamais été
examiné par personne, et le catalogue l'affiche « Disponible ». Ce script les reprend d'un coup.

Deux passes. La première aligne le **suivi**, la seconde confronte la **disponibilité** à l'état des
composants puis au stock assemblable. Sans `--confirm`, il détaille ce qu'il ferait sans rien écrire ;
`--tracking-only` laisse les disponibilités de côté ; `--quiet` réduit la sortie au bilan.

Il ne touche jamais deux choses : une disponibilité plus contraignante que ne l'exigent les
composants — elle vient d'une décision, et le calcul ne fait que durcir — et les lots dont le couple
disponibilité/suivi cible n'existe pas dans la configuration.

Rejouable, idempotent : relancé après coup, il ne trouve plus rien à faire. Sur le catalogue repris,
**12 suivis** et **17 disponibilités** corrigés, 16 lots laissés en l'état, 12 hors périmètre faute de
disponibilité posée.

**Le module aerotoolbox doit être réactivé avant de le lancer** : la mémorisation de la disponibilité
d'origine, qui permet à un lot temporairement indisponible de revenir seul, repose sur un champ créé
à l'activation.

## [0.13.3] — 2026-08-17

### `--only=VOLETS` : réécrire un champ sans rejouer tout le mapping

`product --update` rejouait `mapFields()` puis `update()` sur les 15 909 articles pour corriger
une seule colonne. Long, et surtout disproportionné : des dizaines de champs réécrits là où un
seul était en cause.

```
php migrate.php product --only=datec --dry-run    mesure le rattrapage
php migrate.php product --only=datec              l'applique
```

**Trois volets pour `product`** : `fields` (la fiche, via `mapFields()` + `update()`), `category`
(le rattachement aux catégories) et `datec` (la date de création). Ce dernier est le cas qui a
motivé l'option — `Product::update()` n'écrit jamais `datec`, seul `setValueFrom()` le peut.

**L'option implique `--update` et ne visite QUE l'existant.** Créer un objet absent en prétendant
ne toucher qu'un champ serait contradictoire ; sur `product`, cela ferait naître des articles au
milieu d'un simple rattrapage de dates.

**Un script qui ne sait pas l'honorer refuse de démarrer**, au lieu de l'ignorer :

```
$ php migrate.php productkit --only=datec
Le script « productkit » ne gère pas --only.
Sans cette option, --update réécrit l'ensemble des champs.

$ php migrate.php product --only=nawak
Volet inconnu pour « product » : nawak
Volets acceptés : fields, category, datec
```

Une option silencieusement sans effet ferait croire à une reprise ciblée là où tout aurait été
réécrit — l'inverse de ce qu'on demandait.

**La simulation dit ce que le passage réel ferait.** En `--only=datec`, elle compare la date en
base à celle de la source et n'annonce que les écarts, sans charger les fiches : `Product::fetch()`
coûte une dizaine de requêtes par article, et la simulation aurait duré plus longtemps que la
reprise qu'elle annonce. Mesuré sur 300 articles : **294 à corriger, 6 déjà justes, 0 création**.

De même, un produit dont la valeur était déjà juste est compté « ignoré » et non « mis à jour » :
le rapport ne gonfle pas d'un travail qui n'a pas eu lieu.

**Contrôle colonne par colonne**, photographie de la table avant et après sur un échantillon :
seules `datec`, `tms` et `fk_user_modif` changent. Les deux dernières sont les traces inévitables
de toute écriture — `tms` sur n'importe quel `UPDATE`, `fk_user_modif` posé par `setValueFrom()`.
**Aucun champ métier n'est touché.**


## [0.13.2] — 2026-08-16

### `productkit` échouait en ligne, et nulle part ailleurs

En ligne, le script s'arrêtait sur `Source : -1 enregistrement(s)` et une seule erreur :

```
Illegal mix of collations (utf8mb3_general_ci,IMPLICIT)
                     and (utf8mb3_unicode_ci,IMPLICIT) for operation '='
```

**La cause n'est pas dans les données.** Le script compare `AR_Ref`, un `varchar`, à `NO_RefDet`,
un **entier**. Or `CAST(<entier> AS CHAR)` ne tient pas sa collation d'une table : il prend celle
de la **connexion**. Dès qu'elle diffère de la collation des tables tout en partageant leur jeu de
caractères, MySQL refuse la comparaison. C'est le cas de l'instance en ligne — tables en
`utf8mb3_general_ci`, connexion Dolibarr en `utf8mb3_unicode_ci`.

**Et l'anomalie se cache remarquablement bien :**

```
en développement ... tables utf8mb4, connexion utf8mb3  → charsets différents, MySQL convertit, passe
dans phpMyAdmin .... connexion utf8mb4                  → même raison, la requête rend bien 181
depuis Dolibarr .... tables utf8mb3, connexion utf8mb3  → même charset, collations différentes, REFUS
```

La même requête réussissait donc dans phpMyAdmin et échouait dans le script, sur la même base.
Trois `ALTER TABLE` successifs sur `f_nomenclat` n'y ont rien changé, et ne pouvaient rien y
changer : la collation fautive n'appartenait à aucune table.

Le correctif passe par une méthode unique, `refExpr()`, qui enveloppe toute référence dans
`BINARY CAST(… AS CHAR)` — hors de toute collation, et **sans écrire de nom de collation en dur**,
qui serait faux sur la prochaine installation. Les quatre comparaisons du script y passent :
filtre source, condition des lignes retenues, décomptes du rapport, exclusion de l'article 14749.

**Ce que `BINARY` change :** la comparaison devient sensible à la casse. Vérifié avant de
l'adopter — **aucune** référence de `f_article` ni de `f_nomenclat` ne se distingue d'une autre par
la seule casse. Une comparaison numérique aurait été plus naturelle, mais 22 des 15 909 références
ne le sont pas.

L'anomalie a été reproduite en développement, message d'erreur à l'identique, sur une base créée
en `utf8mb3_general_ci` ; le correctif la lève et laisse les résultats inchangés sur les données
réelles : **181 articles composés, 489 liaisons, 3 / 6 / 12 lignes écartées**.

Voir **P19** dans [ANOMALIES.md](ANOMALIES.md).


## [0.13.1] — 2026-08-16

### Les factures émises sur des commandes annulées sont supprimables

En juin 2026, la synchronisation de la boutique a rattrapé tout l'historique PrestaShop et créé
**28 474 factures d'un coup**, datées de 2023 à 2026. Parmi les commandes ainsi facturées,
**345 étaient annulées dans ADD depuis des mois** — mais l'annulation n'avait jamais été poussée
vers PrestaShop, où elles figuraient toujours comme validées.

La liaison a donc travaillé sur une donnée fausse en amont : **ce n'est pas un défaut du module
de synchronisation**, et il n'y a rien à corriger de ce côté. La divergence est entre ADD et
PrestaShop.

`scripts/delete_invoices_cancelled_orders.php` supprime celles qui n'ont aucune raison d'exister.

```
php delete_invoices_cancelled_orders.php                    dénombre et liste
php delete_invoices_cancelled_orders.php --confirm          applique
php delete_invoices_cancelled_orders.php --limit=20 --confirm    par petits lots
```

**Six conditions, toutes requises.** La facture est rattachée à une commande reprise d'ADD ; la
commande y est marquée annulée ; la facture n'a pas de `ref_ext` de reprise — elle est née dans
Dolibarr et n'a donc aucune contrepartie dans l'ancien ERP ; elle est validée et non payée ; elle
ne porte aucun règlement ; **et la commande n'a aucune préparation de livraison dans ADD.**

La sixième est la moins évidente et la plus importante. Une préparation de livraison signifie que
la marchandise a bougé : la vente a probablement eu lieu et l'annulation dans ADD n'est qu'une
écriture administrative. Croisée avec le règlement, elle trie le gisement :

```
sans préparation, non payée ... 311 factures, 37 917,44 €  → supprimées
préparée ET payée ...........  15 factures,  2 270,01 €  → ventes réelles, épargnées
payée sans préparation ......   9 factures,  2 275,60 €  → épargnées, à arbitrer
préparée sans paiement ......   1 facture,     310,00 €  → épargnée, à arbitrer
```

Sur 336 factures, **dix seulement demandent un arbitrage**. Les 311 ont été supprimées ; le parc
passe de 182 483 à 182 172 factures, sans laisser une ligne, un lien ni un règlement orphelin.

### Comment la suppression passe, là où la 0.12.5 la disait impossible

`fix_invoice_signs.php` notait qu'`is_erasable()` refuse de supprimer une facture qui n'est pas la
dernière de sa séquence (-2, `commoninvoice.class.php:869`). C'est exact — mais la même méthode
**retourne 1 sans aucun autre contrôle** quand la facture est un brouillon dont la référence
commence par « PROV » (ligne 827). Le chemin existe donc, et c'est celui du coeur :

1. `setDraft()` — ne touche que `fk_statut`, la référence y survit ;
2. la référence devient `(PROVDEL<rowid>)` — préfixe distinct des trois `(PROV…)` déjà en base,
   pour ne pas heurter la contrainte unique `uk_facture_ref` ;
3. `delete()` — `is_erasable()` répond 1, la pièce et ses liens partent.

Le répertoire de documents est retiré **après**, à partir de la référence d'origine : `delete()`
le cherche sous le nom temporaire et laisserait l'ancien orphelin.

Rien ne remonte vers PrestaShop, vérifié avant d'écrire : le trigger `aeropresta` n'écoute que
catégories, produits et stock, celui de `prestasync` que des événements de commande. En revanche
`prestasync` **a** un `orderCancel` — annuler les 345 commandes, elle, propagerait bien
l'annulation vers la boutique.

### Le script sait où lire la source, comme les autres

`--source-db=NOM` et la constante `AEROMIG_SOURCE_DB` sont honorées avec la même résolution que
les scripts de reprise, et l'accès à la table source est contrôlé avant tout travail : sans ce
contrôle, l'erreur ne surgissait qu'au milieu du repérage, avec un message SQL que rien ne relie à
un problème de configuration.


## [0.13.0] — 2026-08-14

### Les articles composés sont liés

Nouveau script `productkit` : `f_nomenclat` → `llx_product_association`. Les 184 articles
composés de l'ancien ERP sont des **lots commerciaux** — « Lot des 4 cartes IGN-OACI »,
« Pochette VFR complète » —, pas des fabrications. Rien n'est transformé : on vend ensemble des
articles qui existent séparément au catalogue et se vendent aussi à l'unité, dix-sept fois plus
que les lots. C'est la définition du **kit** de Dolibarr, et non celle d'une nomenclature.

L'option `PRODUIT_SOUSPRODUITS` était déjà active, et les deux extrémités toutes reprises :
184 composés et 251 composants retrouvés par leur `ref_ext`. Le module BOM n'est pas requis.

**Sur 508 lignes de composition, 19 sont écartées :**

```
 3  l'article se contient lui-même
 6  ligne de composition en sommeil
12  article 14749, mis de côté
```

Les **auto-références** ne sont pas une précaution théorique. `3887` ne contient que lui-même,
deux fois ; `6571` se contient une fois en plus de son vrai composant. Une composition récursive
fait boucler tout système qui la déroule, et **le cœur ne s'en protège pas** : le contrôle
anti-boucle d'`add_sousproduit()` exécute bien sa requête mais **n'en lit jamais le résultat**
(product.class.php:4984). Aucun cycle n'est refusé — c'est à l'appelant de le faire.

Les **lignes en sommeil** sont écartées sur décision du client. Elles règlent au passage un faux
problème : `4111` portait ses deux composants en double, une version en sommeil et une active.

L'**article 14749** est mis de côté sur décision du client : son composant `9592` y figure dix
fois en quantité 1, soit dix tailles d'un vêtement gérées comme des articles distincts. La source
sait pourtant exprimer une quantité — elle va jusqu'à 100 ailleurs —, ce qui confirme que dix
lignes à 1 ne valent pas « dix exemplaires ». Le cas se traitera à part.

Reste **489 liaisons sur 181 articles**, sans un seul couple en double.

### Le stock des composants suit celui du lot

`incdec = 1`, le défaut de Dolibarr : vendre le lot décrémente chaque composant. Le lot n'a pas
de stock propre, il n'existe qu'à la vente. Le choix est réversible — `add_sousproduit()`
commençant par supprimer le lien, un rejeu avec l'autre valeur le remplace.

### Vérifications

```
181  articles composés, 489 liaisons        (0,1 s)
  0  liaison sans correspondance dans la source
  0  auto-référence, 0 boucle père ↔ fils
  3  liaisons préexistantes reconnues, pas redoublées
```

Trois associations avaient été saisies à la main sur `#03881` avant toute reprise ; elles sont
conformes à la source et le script les a laissées telles quelles.

**Second passage : 0 écriture, 181 ignorés.** L'idempotence porte sur le lien lui-même,
`llx_product_association` n'ayant ni `ref_ext` ni `import_key`.

`6571` ressort avec son seul vrai composant, l'auto-référence retirée.

### Conséquence à connaître sur le lot le plus vendu

Écarter les lignes en sommeil retire à **`3444`** — le lot le plus vendu du catalogue — l'un de
ses trois composants (`3441`, Rapp'Aéro rigide). Il n'en garde que deux. La ligne est bien en
sommeil dans la source, donc le script est fidèle à ce qu'on lui a demandé, mais la composition
publiée sera incomplète tant que le client n'aura pas tranché.

`6849` perd de même son unique composant et n'est donc plus un kit — sans conséquence, son
libellé commençant par « NE PAS UTILISER » pour deux ventes en tout.

Les quatre autres lignes en sommeil sont sans effet : deux auto-références et deux doublons de
lignes actives.

`PRODUITS_COMPOSES.md` porte cette alerte en tête et passe au bilan de ce qui a été repris. Deux
constats de sa première version y sont corrigés, que la reprise a démentis : `3887` ne contient
pas « deux sous-composants » mais uniquement lui-même, et il n'y a **qu'un** cas de composition
à deux niveaux — le second n'était qu'un effet de cette auto-référence.


## [0.12.8] — 2026-08-14

### Les tarifs repris sont pilotés par le niveau 1

Un prix figé se démode. Au premier changement du tarif de base, les sept autres niveaux
restaient en arrière et la politique commerciale se perdait — c'est exactement ce que faisait
l'ancien ERP, où la remise était réappliquée à la main, article par article.

Le script pose désormais, en même temps que chaque prix, la règle de pilotage d'aerotoolbox :
le drapeau `aerotb_price_follow` et l'écart au niveau 1 dans `aerotb_price_pct`. **Le prix
écrit ne change pas d'un centime** ; il devient seulement dérivable.

**Le taux est dérivé des deux prix, jamais recopié depuis la source.** Quand la source porte une
remise de famille à 5 %, le prix qui en découle a été arrondi au centime et l'écart réel vaut
5,01 % ou 4,98 %. C'est ce chiffre-là qu'il faut garder : stocker 5 % rond ferait sauter
l'arrondi d'étiquette au premier changement du tarif de base. C'est la doctrine d'aerotoolbox —
le prix fait foi, le taux mémorise l'écart.

**Aucune écriture directe dans ces deux colonnes.** La règle est passée au trigger par
`$product->context['aerotb_price_rule']`, et c'est lui qui l'inscrit sur la ligne de prix qu'il
voit naître. Quand seul le pilotage manque — le prix étant déjà juste —, `aerotbPriceRuleWrite()`
le pose directement.

### Une grille déjà en place se branche sans être refaite

C'est la conséquence qui compte. La comparaison ne portait que sur le prix : un catalogue déjà
tarifé était sauté d'entrée, et n'aurait jamais reçu ses règles. Elle porte maintenant sur le
**triplet** — prix, taux, drapeau — et sépare deux cas : le prix à corriger, et le prix juste
auquel il ne manque que son pilotage.

Le second ne crée **aucune ligne d'historique** : le cœur n'en insère que si le prix a changé
(product.class.php:2916). Mesuré directement — 1 000 articles traités, 7 000 règles posées,
`llx_product_price` inchangée à la ligne près.

Il n'y a donc pas de purge à jouer, ni de script de rattrapage à écrire.

### Vérifications

Passage complet sur les 15 908 articles, en 41,6 s :

```
14 408  articles branchés          100 856 règles  (14 408 × 7)
     0  ligne d'historique créée
91 079  niveaux alignés sur le tarif de base, taux nul
    50  niveaux plus chers, taux négatif
 5 915  niveaux sans taux calculable — 845 articles sans prix de base
```

**Propagation vérifiée de bout en bout.** Le niveau 1 d'un article passé de 120,00 à 150,00 TTC
entraîne le niveau 3 à 132,00 (−12 %) et le niveau 4 à 104,81 (−30,125 %) ; les niveaux à taux
nul suivent à 150,00. Article remis dans son état ensuite.

**Second passage : 0 écriture, 15 908 ignorés, 3,8 s.**

**Aucune ligne active n'échappe au pilotage.** Douze lignes des niveaux 2 à 8 portent encore un
drapeau à zéro : toutes sont de l'historique, qui doit rester tel qu'il était.

### Le script continue sans aerotoolbox

Le pilotage tient à deux choses — la bibliothèque `aeroprice.lib.php` et ses deux colonnes —, et
l'absence de l'une des deux n'est pas une erreur : les tarifs se reprennent très bien sans, ils
sont seulement figés. Le rapport le dit et invite à relancer une fois le module en place, ce qui
posera le pilotage sans retoucher un seul prix.

Éprouvé pour de vrai : la bibliothèque a disparu en cours de route, la copie de travail
d'aerotoolbox étant passée sur une branche antérieure à sa 1.9.0. Le script a repris les tarifs
et l'a signalé, au lieu de tomber.

### Ce qui reste hors du champ

Les 1 004 produits qui ne viennent pas d'ADD ne sont pas touchés — ni leurs prix, ni leur
pilotage. Poser une politique tarifaire sur des articles que la reprise ne connaît pas relève de
l'exploitation, pas de la reprise : c'est à aerotoolbox de le faire, à côté du mécanisme lui-même.


## [0.12.7] — 2026-08-13

### Les produits naissent à leur date d'origine

La fiche produit affichait la date de la reprise — **14 899 articles créés le 4 juin 2026** —
alors que la source porte leur véritable date de création dans `AR_DateCreation`, de fin 2019
à juillet 2026. L'ancienneté du catalogue était perdue.

`Product::create()` respecte la date qu'on lui pose et ne retombe sur l'heure courante que si
elle est vide (`product.class.php:1105`) : à la création, il suffit de la renseigner.

**`Product::update()`, lui, n'écrit jamais `datec`.** Les articles déjà repris ne pouvaient
donc pas être corrigés par un simple rejeu : le champ passe désormais par `setValueFrom()`,
qui journalise l'auteur dans `fk_user_modif`. Un passage `product --update` rattrape ainsi tout
le catalogue — **environ 15 300 fiches**.

La date est relevée **avant** le mapping, faute de quoi la comparaison porterait sur la valeur
que le mapping vient d'écraser. L'écriture n'a lieu qu'en cas d'écart réel, à la seconde près :
sans cette garde, chaque passage réécrirait quinze mille lignes sans rien changer. Vérifié,
second passage sans aucune écriture.

**408 articles n'ont aucune date dans la source** et gardent celle de la reprise. Le rapport
les dénombre, comme il dénombre les dates réalignées.

Les produits adoptés de la boutique sont traités comme les autres : l'article existait dans
l'ancien ERP avant que PrestaShop ne le connaisse, et c'est cette antériorité qui fait foi.

Sans effet sur les tarifs : `Product::update()` n'écrit ni `price` ni `price_ttc`, les niveaux
posés par `customerprice` sont hors d'atteinte.


## [0.12.6] — 2026-08-12

### L'agenda automatique est coupé pendant une reprise

Dolibarr consigne dans l'agenda chaque validation de document et chaque règlement. Sur une
reprise, cela se paie trois fois — et la première a fait échouer un document en production.

**Des échecs.** `llx_actioncomm` devient un point de contention, et l'insertion finit par se
heurter aux autres :

```
[FAC253291] Validation refusée : Failed to insert event :
            Deadlock found when trying to get lock; try restarting transaction
```

Le document entier est annulé par le rollback, alors qu'il n'avait rien de fautif.

**Du temps.** Deux écritures de plus par facture, dans une table qui comptait déjà
**976 072 lignes** avant que la reprise commence, dont 267 321 rattachées à une facture.

**Un agenda inutilisable.** Les 182 781 factures et leurs 110 000 règlements y ajouteraient
près de **300 000 événements** « Facture validée » datés de 2015 à 2026. Ils n'apprennent rien
à personne et noient l'historique réel du client.

La coupure est faite **en mémoire, jamais en base** : l'application retrouve son agenda intact
dès la requête suivante. Aucune donnée métier n'y est perdue — un événement d'agenda constate
une action, il ne la porte pas.

Elle vise **toutes** les constantes `MAIN_AGENDA_ACTIONAUTO_*` sans les énumérer : elles
varient selon les modules activés, et en oublier une suffirait à ramener le problème. Sur
l'instance de reprise, **153 étaient actives**.

Placée dans le socle et non dans un script : commandes, réceptions et factures produisent
toutes des événements.


## [0.12.5] — 2026-08-12

### Quantité et prix tous deux négatifs : Dolibarr se trompe de signe

`Facture::addline()` écrit un total de ligne **négatif** là où deux facteurs négatifs donnent
un produit positif. Isolé sur une facture jetable, les trois combinaisons :

```
qty<0 et prix<0 ... attendu +96, obtenu -96   ← le seul cas faux
qty>0 et prix<0 ... attendu -96, obtenu -96
qty<0 et prix>0 ... attendu -96, obtenu -96
```

Le calcul du coeur est pourtant juste : `calcul_price_total(-1, -96)` renvoie bien **+96**. Le
signe se perd entre cet appel et l'écriture de la ligne.

**22 lignes** sont dans ce cas sur l'ensemble du gisement, pour **1 708,67 €**. Comptés en
négatif, l'écart vaut le double : mesuré en production, **3 265 € de manque sur 20 000
factures**, et jusqu'à 455,89 € sur un seul document — `F020045084`, dont la source vaut 5,57 €
et que Dolibarr affichait à −450,32 €.

La source elle-même ne calcule pas ces lignes : `DL_MontantHT` y vaut 0 quand `DL_Qte = -1` et
`DL_PrixUnitaire = -96`.

**Le contournement ne dénature rien** : inverser les deux facteurs laisse leur produit
inchangé, et Dolibarr calcule alors juste. La ligne affiche une quantité positive au lieu de
« −1 × −96 », ce qui est de surcroît plus lisible. Le rapport les dénombre.

Placé **après** le report de la remise négative sur le prix : celui-ci peut lui-même rendre un
prix négatif, et le contournement doit voir la valeur définitive.

### Un script ponctuel pour réparer les factures déjà reprises

La correction ci-dessus n'agit qu'à l'écriture : les factures déjà reprises gardent leur total
faux. `scripts/fix_invoice_signs.php` les répare.

```
php fix_invoice_signs.php              dénombre et liste, sans rien modifier
php fix_invoice_signs.php --confirm    applique
```

**Il ne supprime rien.** `is_erasable()` refuse de supprimer une facture qui n'est pas la
dernière de sa séquence (commoninvoice.class.php:871) : au milieu de soixante mille documents,
recréer les fautives supposerait d'emporter tout ce qui suit.

La correction se fait donc en place — rouvrir si close, repasser en brouillon (seul état où
`updateline()` travaille), inverser les deux signes, revalider, restaurer l'état payé. Chaque
facture dans sa propre transaction : un échec n'entraîne que la sienne.

**La référence survit**, et le script s'en assure : `setDraft()` ne touche que `fk_statut`, et
`validate()` ne renumérote que si la référence commence par « PROV » (facture.class.php:3581).
Un changement de référence fait échouer la facture concernée plutôt que de passer inaperçu.

Éprouvé sur une facture validée reproduisant le cas :

```
AVANT   FA2608-53556   total -423,74 €
APRÈS   FA2608-53556   total  +32,16 €   ← la valeur source exacte
```

Un second passage ne trouve plus rien : les lignes corrigées portent une quantité positive et
ne remontent donc plus au repérage.


## [0.12.4] — 2026-08-12

### Un règlement sans date n'est plus perdu

`Paiement::create()` refusait l'écriture — « Incorrect datetime value: '' for column `datep` » —
et le règlement disparaissait, alors que son montant était parfaitement lisible. Rencontré en
production sur `F990006079` : un virement de 91,20 €, sans date et sans libellé.

Cinq lignes sont dans ce cas sur les **109 938 règlements** du domaine vente : quatre sans
date, une datée de l'an 7. La date de la facture leur sert désormais de repli — elle est
toujours connue, et un encaissement daté du jour de sa facture reste plus juste que pas
d'encaissement du tout. Le rapport les dénombre.

Le script encaissait déjà l'échec sans interrompre la facture : seul le règlement manquait,
et l'erreur était nommée. Le comportement ne change que pour ces cinq lignes.

### Documentation

Le README n'imposait de suspendre Prestasync que pour la purge des tarifs. C'est insuffisant :
**la synchronisation doit être coupée avant `pricelevel` et rallumée après `customerprice`**,
purge ou non. Trois raisons désormais explicitées, dont deux subsistent sans purge — les prix
changent article par article pendant un quart d'heure, et entre les deux scripts les clients
basculés vers le niveau 1 n'ont pas encore de tarif, donc seraient facturés zéro.

L'ordre d'exécution porte la mention en regard des deux lignes concernées, et le rappel de
**rejouer la synchronisation** une fois les deux passés : la boutique publie
`llx_product.price`, c'est-à-dire le niveau 1, qui vient de changer de sens.


## [0.12.3] — 2026-08-12

### La remise « de type 1 » corrigée aussi sur les commandes

Même défaut que dans `MigrationInvoice`, corrigé à la source : `DL_Remise01REM_Type = 1` n'est
pas une remise en montant, c'est un pourcentage comme les autres.

L'ampleur, mesurée avant de corriger, est sans commune mesure avec celle des factures :

| | Lignes de type 1 remisées | Conformes au pourcentage |
|---|---:|---:|
| Commandes clients | **16** sur 202 427 | 16 sur 16 |
| Commandes fournisseur | **0** sur 24 545 | — |

Côté fournisseur, la correction est **préventive** : aucune ligne n'est concernée. C'est
pourtant de ce script que l'hypothèse était partie — formulée sur « seize lignes de tout le
jeu », elle y était sans conséquence, et l'est devenue en migrant vers les factures où elle
touchait 7 927 lignes.

Les commandes déjà reprises ne sont pas rejouées : seize lignes sur 202 427, sur des documents
qui font office d'historique et non de pièce comptable. La reprise définitive partira des
scripts corrigés.

**Retiré** : le compteur `amountDiscountLines` des deux scripts. Plus alimenté, il serait resté
à zéro dans les rapports sans que sa disparition s'explique.

Les remises 2 et 3 de la source ne sont, elles, jamais renseignées — vérifié sur les
525 519 lignes de facture comme sur les 226 972 lignes de commande.


## [0.12.2] — 2026-08-12

### Le rapport de purge distinguait mal ce qu'il détruit de ce qu'il conserve

`purge.php invoice` annonçait un total unique — 1 993 « traités » là où il n'y avait que
**1 829 suppressions et 164 démarquages**. Les factures produites par Prestasync et seulement
adoptées par la reprise ne sont jamais supprimées : elles perdent leur `ref_ext` et restent en
place. Le code le faisait déjà correctement ; c'est le compteur qui les additionnait.

Confondues dans un même nombre, elles laissaient craindre une destruction qui n'a pas lieu —
et devant 164 factures de la boutique, l'hésitation est légitime.

```
Traités   : 1993
  dont supprimé(s)  : 1829
  dont démarqué(s)  : 164 (objet conservé, marqueur de reprise retiré)
```

### Un mode de repli pour les règlements sans mode connu

Rien à déployer, c'est un réglage — mais il change ce que la reprise conserve.

`AEROMIG_PAYMENT_FALLBACK_CODE` existait sans être utilisé : faute de mode désigné, un
règlement dont le mode reste introuvable n'était pas repris. En y pointant un mode créé pour
cela — `INDET`, « Mode indéterminé (reprise) » —, ces règlements entrent en base et se
retrouvent d'un filtre.

Cela concerne les **110 règlements de l'indice 6**, absent du dictionnaire de la source, pour
15 114,21 € — soit 0,13 % des encaissements. Population mixte, et c'est ce qui justifie de les
garder plutôt que de les écarter en bloc :

```
43  sans libellé              12 625,42 €   dont un seul de 11 727,08 €
14  « RBSMT PORTWD »               82,96 €
 8  « Annulée sous Vision »         0,00 €
 4  « Remise marché non appliquée » 1 243,03 €
 1  « Chèque cadeau SUFT-4VR3… »   95,00 €
```

Les gestes commerciaux et détaxes ne sont pas des encaissements. Mais les lignes sans libellé
— 12 625 € — ressemblent à de vrais règlements dont le mode a été perdu, et un chèque cadeau
en est un. Les écarter laissait des factures impayées à tort.

Le tri fin reste à faire, mais il se fera sur des données présentes plutôt que sur un compteur
d'anomalies.


## [0.12.1] — 2026-08-12

Correctifs de mise en ligne. Aucune fonctionnalité nouvelle, mais **un défaut de calcul qu'il
ne fallait pas laisser passer en production**.

### La remise « de type 1 » est un pourcentage, pas un montant

`DL_Remise01REM_Type = 1` était tenu pour une remise exprimée en montant, et **abandonnée** :
Dolibarr n'accepte qu'un taux à la ligne, et convertir un montant aurait faussé le prix
unitaire. L'hypothèse venait de `MigrationSupplierOrder`, où elle ne portait que sur seize
lignes et ne se voyait pas.

Elle est fausse, et la source le démontre elle-même. Sur les **7 925 lignes de facture de
type 1** où le calcul est vérifiable, en confrontant `DL_MontantHT` aux deux formules :

```
interprétée en POURCENTAGE ... 7 851 lignes conformes
interprétée en MONTANT .......     6 lignes conformes
```

La facture `F010000781` suffit à trancher : prix 790,00, remise 5,063291 de type 1, et
`DL_MontantHT` vaut **750,00** — soit exactement 790 × (1 − 5,063291 / 100).

**7 927 lignes de facture perdaient ainsi leur remise**, la facture étant écrite au prix plein.
Mesuré sur les 24 993 factures du premier lot : **3 898 documents en écart de plus d'un euro**,
jusqu'à **790 €** sur une seule facture.

Les 74 lignes qui ne collent à aucune des deux formules portent des remises de 5 % et 50 % —
des pourcentages ordinaires dont `DL_MontantHT` n'a pas été recalculé après coup.

**Vérifié après correction**, sur 400 factures rejouées avec les mêmes données :

| | avant | après |
|---|---:|---:|
| écart supérieur à 1 € | 3 898 | **0** |
| écart maximal | 790,00 € | **0,05 €** |

Les écarts résiduels sont sous le centime et demi : la source stocke ses prix à six décimales,
Dolibarr arrondit à la ligne.

⚠️ **`MigrationCustomerOrder` et `MigrationSupplierOrder` portent le même défaut** et ne sont
pas encore corrigés. Leurs remises de type 1 sont perdues de la même façon — 61 693 commandes
clients et 2 567 commandes fournisseur sont concernées. À rejouer.

### L'indice de règlement 10 était ignoré

« CB Portable / extérieur » figure au dictionnaire de la source mais manquait à la table de
correspondance : **34 règlements, 6 908,84 €**, entre mars 2024 et mars 2025. Leurs libellés ne
les rattrapaient pas — 28 sont vides — et ils tombaient en mode inconnu, sans être repris. Ils
rejoignent la carte magasin : un terminal portable encaisse en présentiel.

### La page de configuration ne peut plus bloquer

Elle mesurait l'avancement des seize scripts **à chaque affichage**. Tant que source et cible
partagent un serveur, cela se compte en secondes ; dès qu'elles sont séparées — deux bases,
deux collations —, en minutes. La page devenait inutilisable, et le réglage qu'on venait y
chercher inaccessible : précisément celui qui aurait corrigé la situation.

Le calcul se demande désormais, par un bouton. La page s'affiche instantanément.

`MigrationPriceLevel::countMigrated()` en était la principale cause : il appariait chaque tiers
à sa ligne source par `CONCAT('SAGE:', CT_Num) = ref_ext`, expression que MySQL ne sait pas
indexer — hash join de 157 582 lignes contre 155 898. Il procède maintenant par deux agrégats
indépendants rapprochés en mémoire. La mesure devient approchée — deux tiers mal aiguillés en
sens inverse se compenseraient, l'écart constaté est d'une unité sur 157 188 — mais son coût ne
dépend plus du produit des deux tables.

### Corrigé

- **Le champ « Base source » laissé vide ne s'enregistrait pas.** `dolibarr_set_const()` ne
  stocke pas les valeurs vides — `if (strcmp($value, ''))` encadre son unique `INSERT`
  (admin.lib.php:722) —, si bien que le réglage disparaissait au lieu d'être posé, et que les
  scripts retombaient sur la base qu'ils déclarent en dur. Le champ vide enregistre désormais le
  nom de la base de Dolibarr, que le socle ramène de toute façon à « pas de préfixe ».
- **Le contrôle de source faisait seize requêtes** pour poser seize fois la même question. Il
  n'en fait plus qu'une, mise en cache et partagée entre les scripts.
- **Une source refusée se distingue d'une source absente** : les erreurs MySQL 1044 et 1142
  produisent un message qui parle de droits — le module lit la source par la connexion de
  Dolibarr, il n'y a pas de second identifiant à renseigner.
- Le registre des scripts annonçait que `location` lisait `f_emplacements` ; c'est
  `f_depotempl` depuis la 0.11.0.


## [0.12.0] — 2026-08-12

Trois chantiers, restés jusqu'ici hors publication : la reprise des **factures clients et de
leurs règlements**, celle des **tarifs de vente par catégorie**, et celle des **réceptions
fournisseur**.

---

### Réceptions fournisseur

Nouveau script `reception`, sur le **type 13** du domaine achat — la source ne numérote pas ses
types comme le domaine vente, où la commande est 12 et la facture 16. **2 852 documents retenus,
22 849 lignes, 387 502 unités.**

**Les factures fournisseur ne sont pas reprises, et ne le seront pas.** Le client ne les a
jamais gérées, ce que la source confirme : sur ses 2 139 factures d'achat, `z_docregl_global`
ne porte **qu'un seul règlement** — 478,19 €, et sur une commande. `f_docregl` est vide,
`DR_Regle` vaut 0 partout, `Z_Solde` vaut « N » sur l'intégralité du gisement.

#### Le stock ne bouge pas, et c'était la condition de tout

`Reception::valid()` ne mouvemente le stock que si `STOCK_CALCULATE_ON_RECEPTION` est posée.
Elle ne l'est pas, et le script **refuse de démarrer** si elle venait à l'être : sans cette
garantie, les 22 849 lignes s'ajouteraient au stock d'ouverture déjà repris et le doubleraient
de 387 502 unités, sans que rien ne le signale avant l'inventaire suivant. Vérifié après
reprise : 165 588 unités avant, 165 588 après.

⚠️ **Ne clôturez pas ces réceptions.** `STOCK_CALCULATE_ON_RECEPTION_CLOSE` vaut 1 — non par
configuration mais parce que le coeur la force dès que le module lots/séries est actif
(conf.class.php:963). L'écrire à 0 en base ne sert à rien, la valeur étant réimposée à chaque
chargement. Elle ne joue qu'à la clôture, que le script ne fait pas ; la faire à l'écran, si.
Le rapport le rappelle à chaque passage.

#### Deux façons d'écrire une ligne

Dolibarr adosse structurellement une ligne de réception à une **ligne de commande** :
`addline()` prend un `fk_commandefourndet` qu'elle relit pour en déduire l'article. C'est la
voie complète — 20 109 lignes, avec entrepôt et lien.

Mais 327 réceptions ne citent aucune commande. Leurs 2 563 lignes passent par `addlinefree()`,
qui accepte un article et une quantité sans commande, au prix d'une limite assumée : **elle
n'écrit ni l'entrepôt ni le prix**. Sans conséquence ici, les réceptions étant toutes sur le
dépôt principal.

L'appariement se fait sur l'article, la source ne conservant pas le numéro de la ligne
d'origine. **448 couples (commande, article) portent plusieurs lignes** — un même article
commandé deux fois, à deux prix : la première l'emporte, par `rowid` croissant.

#### Ce qui est écarté

**291 réceptions annulées.** L'objet Reception n'a que trois statuts — brouillon, validée,
close — et **aucun équivalent d'« abandonnée »**, contrairement aux factures. Les faire figurer
validées laisserait croire à une entrée de marchandise qui n'a pas eu lieu.

**Quatre documents sans ligne**, que `valid()` refuse et qui n'apprennent rien.

#### Les statuts de commande sont recalculés

Valider une réception fait recalculer par le coeur ce qui a été reçu. Les statuts posés par
déduction lors de la reprise des commandes cèdent la place aux réceptions réelles : 422
commandes passent en « reçue partiellement », 1 610 en « reçue totalement ».

---

### Factures clients et règlements

Nouveau script `invoice`, sur `f_docentete_global` : **182 832 factures et 525 402 lignes**.
La source les tient en deux populations — `DO_Type = 6` pour les factures courantes,
`DO_Type = 7` pour les comptabilisées. Les deux sont des factures réelles et leurs numéros ne
se recoupent jamais ; le type 7 couvre 2015 → 2024, le type 6 2019 → 2026.

Les factures sont **rattachées à leur commande** quand la source le permet.

#### Reconnaître ce que Prestasync a déjà facturé

Le module facture chaque commande de la boutique par `Facture::createFromOrder()`. Ces
factures sont **adoptées** : reconnues, marquées d'un `ref_ext`, et laissées intactes pour
tout le reste.

Le rapprochement ne peut pas se faire par la référence, contrairement aux commandes :
Prestasync impose la sienne sur la commande mais laisse la facture prendre le numéro du
compteur Dolibarr. Il passe donc par la commande, dont le `ref_ext` porte la clé source.

Il ne peut pas non plus se faire sur l'origine du document, et c'est le piège qu'il fallait
éviter :

```
factures issues d'une commande boutique .................. 55 131
dont la commande porte DÉJÀ une facture Dolibarr ......... 28 313   → adoption
dont la commande n'en porte AUCUNE ....................... 31 832   → création
```

Prestasync n'a facturé que depuis 2023. Écarter toutes les commandes de la boutique ferait
disparaître **31 832 factures antérieures**. C'est l'état réel de la cible qui décide.

#### Un avoir se reconnaît à son total, et le seuil compte

La source n'a pas de type distinct : un avoir est une facture dont le total est négatif —
1 934 documents. Encore faut-il qu'il le soit franchement.

Dolibarr force chaque ligne d'un avoir à être négative : quantité en valeur absolue, prix
unitaire et total en négatif (facture.class.php:4415-4427). **Une ligne positive y est
structurellement irreprésentable** : négative à la source, elle devient positive et s'ajoute
au remboursement au lieu de s'en retrancher. Mesuré sur `AF990026466`, dont le total passait
de 0 à −89,82 €.

Or **1 024 documents totalisent quelques millièmes d'euro négatifs** — un résidu d'arrondi,
la source stockant ses prix à six décimales. Les classer en avoir sur la foi de ce signe les
aurait tous abîmés. Le seuil les laisse en factures ordinaires, où les lignes gardent leur
signe et le total reste juste.

#### Les règlements viennent de deux endroits

La vente au comptoir encaisse sur la facture ; la vente en ligne encaisse sur la commande,
avant facturation. Sur **110 103 règlements** : 54 064 sur des factures, 55 337 sur des
commandes, 12 496 sur un document qui n'existe plus. Dolibarr ne sait rattacher un paiement
qu'à une facture : les deux gisements y convergent, les orphelins sont comptés sans être
repris. Ceux des factures adoptées ne sont jamais recréés — Prestasync les a déjà posés.

**Le mode de règlement est déduit du libellé quand le code manque.** 1 540 règlements portent
l'indice 0, absent du dictionnaire de la source : le champ n'était pas alimenté avant 2021.
Leur moyen de paiement n'est pas perdu pour autant — il est écrit en toutes lettres à côté.
**1 539 annoncent « Carte Bancaire Internet », pour 141 005 €**. Lire le libellé vaut mieux
que se rabattre sur un mode choisi d'avance.

L'ancien ERP distingue **quatre** canaux de carte — magasin, internet, téléphone, et un
terminal portable pour les ventes hors des murs — là où Dolibarr n'a qu'un code `CB`. Les modes
`CBNET` et `CBMAG` posés par `aerotoolbox` 1.5.7 sont employés s'ils existent et sont actifs ;
sinon tout retombe sur `CB` sans que la reprise s'interrompe. La distinction est une
amélioration, pas une condition.

Deux indices de la source n'ont **aucune entrée dans son propre dictionnaire** :

- **l'indice 0**, 1 540 règlements pour 141 051,05 €, de 2019 à 2020 — le champ n'était pas
  alimenté avant 2021. Ils sont rattrapés par le libellé (voir ci-dessus) ;
- **l'indice 6**, 110 règlements pour 15 114,21 €, de 2019 à 2022 : un fourre-tout de
  régularisations — « Remise marché non appliquée », « Geste commercial », « DETAXE accordée
  le 22/08 », et 43 lignes sans libellé. Ce ne sont pas des encaissements ; ils sont écartés.

Restent les indices 5 et 9, qui sont des avoirs et relèvent de la compensation entre pièces,
non du paiement. La couverture est donc complète et chaque manque est intentionnel.

#### Purger dans l'ordre inverse, et rouvrir avant de défaire

`is_erasable()` refuse de supprimer une facture qui n'est pas la dernière de sa séquence
(commoninvoice.class.php:871) — pour ne pas trouer la numérotation. La purge parcourt donc
les factures **en ordre décroissant**.

Et un règlement rattaché à une facture close ne se supprime pas
(`ErrorDeletePaymentLinkedToAClosedInvoiceNotPossible`) : la facture est rouverte par
`setUnpaid()` avant qu'on y touche.

#### État

Premier lot passé sur l'instance de reprise : **24 993 factures — 24 829 créées, 164
adoptées —, 0 erreur, 1 669 031,18 € HT**. Aucun écart supérieur à 1 € entre le total source
et le total Dolibarr ; écart maximal **0,12 €**. Comptez huit heures pour le reste.

**311 factures sont écartées**, faute de client : ils ont disparu d'ADD lui-même, ce que le
client a confirmé. 2015 → 2019, **9 695,29 € HT**.

---

### Tarifs de vente par catégorie

Les **tarifs de vente par catégorie de client** sont repris, et les deux premières catégories
sont permutées.

#### Un niveau de prix non renseigné facture zéro

C'est le fait qui commande toute la conception, et il ne produit aucune erreur.
`Product::fetch()` pose `multiprices[$i] = null` quand un niveau n'a pas de ligne, et
`getSellPrice()` l'utilise sans repli : le client se voit proposer 0,00 € sur chaque ligne de
document. Ni message, ni code de retour négatif — le devis est valide, simplement gratuit.

Avant cette version, **146 498 clients étaient rattachés au niveau 2, qui portait cinq
articles**. Toute la boutique aurait facturé zéro.

`customerprice` écrit donc les huit niveaux pour tous les articles. Ceux qui n'ont aucune
dérogation reçoivent le prix de leur fiche. D'où le volume : **127 264 lignes de prix pour
15 908 articles**.

#### Les catégories 1 et 2 sont permutées

Dans l'ancien ERP, la 1 est le comptoir et la 2 le site. En cible, le tarif du site devient le
**niveau 1**, pour une raison qu'il faut connaître avant d'y toucher : le trigger
`PRODUCT_PRICE_MODIFY` d'`aerotoolbox` recopie le niveau 1 dans `llx_product.price`, et
Prestasync publie ce champ vers la boutique. Le niveau 1 est **le prix que voit le client sur
PrestaShop**. Il se trouve que c'est aussi le tarif de 146 388 clients sur 157 189.

| Ancien ERP | Dolibarr | Libellé | Clients |
|---|---|---|---:|
| 2 — site | **1** | Défaut / Site | 146 388 |
| 1 — Comptoir | **2** | Comptoir | 10 210 |
| 3 à 8 | 3 à 8 | inchangés | 591 |

La correspondance est portée par `aeromigration_price_level()`, **en un seul endroit**.
`MigrationThirdparty` et le nouveau `MigrationPriceLevel` l'appellent tous les deux : les
faire diverger reviendrait à facturer une partie du fichier client au mauvais tarif sans que
rien ne le signale.

#### Deux scripts

| Script | Source | Résultat |
|---|---|---|
| `pricelevel` | `f_comptet` | catégorie tarifaire des clients repris |
| `customerprice` | `f_article` + `z_tarifparticulier` | les huit niveaux de chaque article |

`pricelevel` corrige plutôt qu'il ne reprend. Rejouer `thirdparty --update` aurait refait,
pour chacun des 157 000 tiers, un mapping complet et un `Societe::update()` — des dizaines de
requêtes par fiche pour n'en changer qu'un entier. `Societe::setPriceLevel()` n'a besoin que
de l'identifiant : deux requêtes, et seulement pour les tiers qui changent.

`customerprice` part de la **fiche article**, non de la table des tarifs. Parcourir les 27 591
lignes de tarif obligerait à revenir plusieurs fois sur le même article sans jamais savoir
quand ses niveaux sont tous posés — et laisserait sans prix les 10 000 articles sans
dérogation, donc facturés zéro.

#### Quatre colonnes de la source écartées, et pourquoi

**La catégorie 0 n'est pas le prix public.** Elle couvre 15 059 articles et ressemble à un
tarif général. Elle coïncide avec `f_article.AR_PrixVen` sur 15 149 des 15 885 articles
comparables ; ce sont les 736 écarts qui tranchent : **735 fois, c'est la fiche article qui a
bougé en dernier**. `f_article` compte 15 238 lignes modifiées en 2026 contre 570 pour la
catégorie 0, figée en 2023.

**`coeff` ne reconstitue aucun prix.** Article 78 : 3,0159 × 2,99 = 9,02 pour un tarif réel de
9,50. Article 2502 : 2,3174 × 11,20 = 25,95 contre 29,95. C'est un indicateur de marge, laissé
à zéro sur 22 217 lignes.

**`statut` ne corrèle avec rien.** Le filtre naturel `statut = 'O'` serait un désastre
silencieux : en catégorie Comptoir, **3 717 lignes sur 5 217 ont un statut vide**. Il en
perdrait 71 %.

**Soixante et une règles par famille ne font rien** — `remise = 0`, `coeff = 1`. Sept sont
agissantes : Aéro-Clubs à −5 % sur 1 943 articles, Marché Enac à −9 % et −20 %. Un article
relevant de plusieurs familles remisées reçoit **la remise la plus forte**, faute de quoi son
prix dépendrait de l'ordre des colonnes `CL_No1..4`.

#### Idempotence sans marqueur

`llx_product_price` déclare bien un `import_key`, mais `Product::_log_price()` ne l'écrit
jamais. Le script se passe donc de marqueur et **compare les valeurs** : `updatePrice()` n'est
appelée que si le prix calculé diffère de plus d'un demi-centime de celui en base.

Ce seuil n'est pas une commodité — `llx_product.price` est arrondi au centime là où
`llx_product_price` conserve huit décimales. Comparer strictement rejouerait huit lignes
d'historique par article à chaque passage.

C'est aussi plus sûr qu'un marqueur : un prix corrigé à la main puis rejoué revient à sa
valeur source, là où un marqueur aurait fait sauter la ligne.

#### Deux précautions d'écriture

Les niveaux 2 à 8 sont écrits **avant** le niveau 1. `updatePrice()` recopie le prix dans
`llx_product` sans jamais regarder le niveau qu'elle écrit : finir par le premier laisse le
prix de base juste, y compris si le trigger de réalignement venait à être désactivé.

L'autogénération est coupée (`$ignore_autogen = 1`) : elle régénérerait les niveaux 2 à 8 à
partir du premier et écraserait tout le travail.

⚠️ **`purge.php customerprice` remet les prix à zéro.** Entre la purge et la fin du rejeu,
`llx_product.price` vaut 0 pour tout le catalogue repris : **Prestasync doit être suspendu**.
Comptez un quart d'heure.

#### Corrigé

- **Huit scripts sur seize ne savaient plus lire leur source.** `thirdparty`, `contact`,
  `newsletter`, `category`, `product`, `supplierprice`, `supplierorder` et `customerorder`
  n'avaient pas de `$sourceDb` et cherchaient les tables `f_*` dans la base de Dolibarr, où
  elles ne sont plus depuis que l'éditeur a livré son dossier entier. Lancés tels quels, ils
  échouaient — leur page d'administration affichait un comptage négatif sans dire pourquoi.

  Poser la propriété ne suffisait pas : quatre lectures échappaient encore à `src()`.
  Elles étaient invisibles à une recherche sur le nom des tables, car écrites
  `FROM '.$this->srcTable`. La plus coûteuse était celle de `product`, qui rendait
  `prepare()` muet et faisait lire **zéro ligne** sur 15 908.

  Trois cas particuliers ont demandé davantage :

  - `contact` a pour source une **jointure**, que `src()` ne pouvait pas qualifier — elle
    n'aurait préfixé que la première table. La jointure est désormais bâtie dans une
    surcharge de `src()`, appelée au bon moment : `--source-db` n'est appliquée qu'après
    l'instanciation, une composition faite dans le constructeur garderait l'ancienne base ;
  - `newsletter` joignait `f_comptet` en dur dans sa purge ;
  - `stock` faisait de même avec `f_artstock`, alors qu'il portait pourtant `$sourceDb`.

  Les seize scripts comptent maintenant leur source et déroulent une simulation sans erreur.

- **La base source se règle depuis la configuration du module**, et non plus dans le code. La
  constante `AEROMIG_SOURCE_DB` prime sur la valeur que chaque script déclare ; désigner la
  base de Dolibarr, ou laisser le champ vide, revient à ne plus qualifier les lectures — le
  cas d'un hébergement mono-base comme Plesk, qui donne une base par site avec son propre
  phpMyAdmin. L'option `--source-db=` reste prioritaire le temps d'un lancement.

  « Constante vide » et « constante jamais posée » sont deux choses différentes : la première
  est un réglage, la seconde une absence de réglage. Le socle les distingue par une sentinelle,
  sans quoi l'hébergement mono-base aurait été inatteignable.

- **Une source injoignable est désormais annoncée.** Avant tout parcours, le script vérifie que
  sa table source répond, et s'arrête sinon en disant quelle base il a interrogée et quoi
  corriger. Auparavant, une base mal réglée ne produisait qu'un « 0 enregistrement » — qui se
  confond avec une reprise déjà faite — et un état « Indéterminé » sur la page d'administration,
  lequel pouvait tout aussi bien signaler une dépendance absente. C'est ce silence qui avait
  laissé huit scripts inopérants sans que personne le remarque.

- **`migrate.php` relève `memory_limit` à 2 Go.** Le script `invoice` précharge 525 402 lignes
  et mourait à 512 Mo — sans autre trace qu'une sortie tronquée et un code 255, l'erreur fatale
  échappant au bloc `try` donc au rapport. Une limite déjà plus généreuse est respectée.

- `MigrationThirdparty::applyPriceLevel()` recopiait `N_CatTarif` tel quel dans `price_level`.
  Elle passe désormais par `aeromigration_price_level()`.
- `MigrationInvoice` ignorait **l'indice de règlement 10** — « CB Portable / extérieur »,
  34 règlements pour 6 908,84 €, de mars 2024 à mars 2025. Il figure pourtant au dictionnaire
  de la source ; c'est la table de correspondance qui l'omettait. Ses libellés ne l'auraient pas
  rattrapé — 28 sont vides — et il tombait donc en mode inconnu, sans être repris. Il rejoint la
  carte magasin : un terminal portable encaisse en présentiel. Aucune perte constatée, le premier
  lot de 24 993 factures ne couvrant que des exercices antérieurs.


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
