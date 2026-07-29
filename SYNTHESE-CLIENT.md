# Reprise de vos données ADD — point de situation

*Document destiné à vous tenir informé de l'avancement et à recueillir vos décisions sur
les points qui ne peuvent être tranchés que par vous.*

---

## Où nous en sommes

Vos données ont été transférées depuis ADD vers Dolibarr :

| | |
|---|---:|
| Clients et fournisseurs | 157 102 |
| Contacts | 154 412 |
| Désinscriptions newsletter | 2 621 |
| Catégories de produits | 504 |
| Articles | 15 811 |
| Tarifs fournisseurs | 15 950 |

Les articles déjà présents dans la boutique en ligne n'ont **pas** été dupliqués : ils ont
été reconnus et complétés avec les informations que seul ADD possédait. Il en va de même
pour vos clients.

L'opération est **rejouable** : nous pouvons la relancer autant de fois que nécessaire d'ici
la mise en service, sans créer de doublons ni écraser vos corrections manuelles.

---

## Ce sur quoi nous avons besoin de vous

### 1. Des articles achetés plus cher qu'ils ne sont vendus

**155 articles** ont un prix d'achat supérieur à leur prix de vente. Exemples :

| Article | Prix d'achat | Prix de vente |
|---|---:|---:|
| Réf. 00328 | 437,06 € | 354,17 € |
| Réf. 00700 | 818,51 € | 662,46 € |
| Réf. 00453 | 41,85 € | 24,17 € |

Impossible de savoir depuis les données lequel des deux prix est erroné : tarif fournisseur
augmenté sans que le prix de vente suive, prix de vente jamais réajusté, ou vente à perte
volontaire sur du déstockage.

Nous les avons repris tels quels, sans y toucher. Ils apparaîtront donc avec une marge
négative, ce qui les rendra faciles à repérer.

**Ce que nous attendons de vous :** la liste complète vous sera transmise pour vérification.
Dites-nous si vous préférez que nous corrigions certains prix avant la mise en service.

### 2. Des remises fournisseurs dont la nature est incertaine

**58 lignes de tarif** portent une remise, avec des valeurs comprises entre 2 et 33,5. Rien
dans ADD ne précise s'il s'agit d'un **pourcentage** ou d'un **montant en euros**.

Nous les avons reprises comme des pourcentages, ce qui est le plus probable.

**Ce que nous attendons de vous :** une confirmation. Si ce sont des montants, la correction
est simple à appliquer.

### 3. Environ 13 500 fiches clients en double

Votre base compte 156 713 comptes clients pour 143 174 noms différents. Les cas les plus
fréquents : `anonymized client` (102 fiches), `particulier` (44), `airbus` (35), `testing`
(34).

Nous n'avons **rien fusionné**. Deux fiches portant le même nom ne sont pas nécessairement
la même personne, et une fusion automatique aurait mélangé des historiques de commandes.

**Ce que nous attendons de vous :** Dolibarr dispose d'un outil de fusion de fiches, à
utiliser au cas par cas après la mise en service. Nous pouvons vous former dessus, ou
traiter ensemble les cas les plus évidents.

### 4. Les durées de garantie

L'information « sous garantie oui/non » a bien été reprise. En revanche, **ADD ne contient
aucune durée de garantie** — le champ prévu pour cela est vide sur la totalité des articles.

**Ce que nous attendons de vous :** si vous souhaitez des durées de garantie dans Dolibarr,
il faudra les saisir. Dites-nous si une règle générale s'applique (par exemple 24 mois par
défaut sur une famille d'articles), nous pourrons l'appliquer en une fois.

### 5. Un point de vigilance avant votre première campagne e-mail

Les 2 621 désinscriptions newsletter ont été reprises. Dolibarr raisonne par **adresse
e-mail** et non par fiche client.

Conséquence : **9 adresses e-mail sont partagées** entre un client désinscrit et un client
qui ne l'est pas. Ces 9 adresses seront exclues de vos envois pour les deux fiches.

C'est le fonctionnement normal de Dolibarr, mais mieux vaut le savoir que le découvrir.

---

## Ce que nous avons corrigé automatiquement

Ces points sont traités, ils ne demandent aucune action de votre part. Nous les signalons
parce qu'ils touchent à la qualité de vos données.

### Des coûts de revient périmés

**2 767 articles** affichaient dans ADD un coût de revient inférieur à leur propre prix
d'achat — l'article 10514 était à 35,26 € pour un achat à 75,92 €. Ce champ n'était plus
recalculé lorsque le tarif fournisseur augmentait.

Sans correction, Dolibarr aurait affiché des marges **artificiellement gonflées** sur 20 %
de votre catalogue. Nous utilisons désormais le coût standard, qui suit le prix d'achat
réel.

Il reste environ 1 300 articles dont le coût demeure inférieur au prix d'achat, y compris
dans cette seconde source. Nous ne les avons pas modifiés : cela aurait consisté à inventer
une valeur absente de vos données.

### Des références fournisseur utilisées plusieurs fois

**Un millier de lignes** partageaient une même référence fournisseur entre plusieurs
articles — jusqu'à 17 articles sur la référence `BAL-A P488-029` chez un même fournisseur.
Environ 2 100 autres n'avaient aucune référence.

Dolibarr n'accepte qu'un article par référence chez un fournisseur donné. Nous avons ajouté
la référence de l'article entre parenthèses pour les distinguer : `Chemise (#10475)`. Aucun
tarif n'a été perdu.

### Des taux de change incohérents

**614 tarifs** sont libellés en dollars, dollars canadiens ou livres. Les taux de change
enregistrés dans ADD variaient de 0,28 à 2,17 pour une même devise, ce qui est impossible.

Nous conservons le taux d'ADD lorsqu'il est crédible — les prix sont alors identiques au
centime — et appliquons le taux officiel dans les 91 cas manifestement faux.

### Des civilités erronées

Le champ « civilité » d'ADD ne contenait pas une civilité mais une classification interne.
S'en servir aurait abouti à **adresser environ 2 865 clientes en « Monsieur »**.

Nous avons reconstitué les civilités à partir d'un autre champ, fiable celui-là.

### Des pays écrits de 166 façons différentes

`Grande-Bretagne`, `ANGLETERRE`, `Royaume uni`, `UK`, `ENGLAND` désignaient le même pays.
Tout a été harmonisé, à 22 fiches près (voir plus bas).

### Des catégories en double

7 rubriques de votre catalogue portaient le même nom sous la même rubrique parente. Elles
ont été fusionnées.

---

## Ce qui n'a pas pu être repris

### 957 contacts sans nom

Ce sont des fiches créées dans ADD puis jamais renseignées : **environ 930 sont
intégralement vides**, sans nom, sans e-mail, sans téléphone. Les reprendre aurait encombré
vos listes de fiches sans contenu.

Une vingtaine d'entre elles portent malgré tout un e-mail ou un téléphone. Elles restent
récupérables si vous le souhaitez.

### 22 fiches sans pays identifiable

Les mentions `Antilles françaises` (18 fiches, sans précision entre Guadeloupe et
Martinique), `ARMEES` (2), `SP 55047` (1) et une valeur corrompue n'ont pas d'équivalent.
Le pays est laissé vide plutôt que deviné.

### Les photos des articles

11 686 articles portent une adresse de photo dans ADD. La reprise des images n'est pas
incluse à ce stade — à prévoir séparément si vous le souhaitez.

### Quelques références isolées

5 références d'articles et 4 codes fournisseurs cités dans vos tarifs n'existent nulle part
ailleurs dans ADD. Trois des codes fournisseurs sont manifestement des noms saisis à la
place d'un code (`Andre Rooney`, `DENOD`, `Le comptoir du li`).

---

## Ce que nous avons repris tel quel, volontairement

Nous avons pris le parti de **ne pas corriger vos données à votre place**. Les éléments
suivants sont donc transférés en l'état, avec leurs défauts :

- **Numéros de téléphone manifestement faux** : `555-666-0606` apparaît sur 165 fiches,
  `0000000000` sur 16.
- **34 adresses e-mail invalides** (sans arobase, ou domaine incomplet), et 527 adresses
  utilisées par plusieurs clients.
- **Environ 170 adresses** dont le code postal ne correspond pas à la ville — par exemple
  `31000 MONTAUBAN`, alors que 31000 est Toulouse.

Ces corrections sont possibles, mais elles relèvent de votre décision : nous ne pouvons pas
deviner la bonne valeur.

---

## Une remarque sur votre catalogue

Vos articles déclinés en tailles ou en variantes ont été créés dans ADD de **deux façons
différentes**, parfois pour un même produit.

Exemple réel : le chemisier avion rouge F.F.A. existe en une fiche « avec tailles »
(XS, S, M, XL) et, à côté, en six fiches distinctes portant la taille dans leur nom. Le
fournisseur, lui, ne connaît qu'une seule référence.

Cela concerne peu de fiches structurées correctement — 209 articles sur 15 811 — et
explique l'essentiel des références fournisseur en double signalées plus haut.

Dolibarr sait gérer proprement les déclinaisons. Regrouper vos articles de cette façon
serait un vrai gain de lisibilité, mais c'est un **chantier à part entière** : il faudrait
fusionner des fiches déjà utilisées par votre boutique en ligne, avec leurs commandes et
leur historique. Nous ne l'avons pas entrepris.

**À votre appréciation**, si le sujet vous intéresse pour plus tard.

---

*Nous restons à votre disposition pour tout éclaircissement.*
