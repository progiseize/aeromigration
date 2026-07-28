# Journal des modifications — Reprise de données

Toutes les évolutions notables du module sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et le module respecte le [versionnage sémantique](https://semver.org/lang/fr/).


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
