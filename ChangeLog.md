# Journal des modifications — Reprise de données

Toutes les évolutions notables du module sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et le module respecte le [versionnage sémantique](https://semver.org/lang/fr/).


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
