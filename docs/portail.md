# Portail web

Le portail (`portal/`) est une application Vue 3 servie en fichiers statiques, par
exemple sous `https://<serveur>/portal/`. Il utilise la même API que l'app iOS.

## Connexion

L'écran de connexion demande l'URL de l'API, l'identifiant et le mot de passe. Le JWT et
l'URL sont conservés dans le navigateur (`localStorage`) jusqu'à la déconnexion.

Les pages d'administration (Règles, Catégories, Comptes, Utilisateurs) suivent la même
présentation : un bouton **+** en haut à droite ouvre le formulaire dans une fenêtre
modale, qui sert aussi à la modification.

Deux profils existent : **utilisateur** (transactions, budget, tendances, analyses,
synchronisation) et **administrateur**, qui gère en plus les onglets **Règles**,
**Catégories**, **Comptes** et **Utilisateurs** (invisibles pour un utilisateur).

Le sélecteur de mois et d'année en haut de page s'applique aux onglets Transactions,
Budget et Analyses. Les onglets Tendances et Règles ont leurs propres réglages.

## En-tête et menu utilisateur

Le titre **Carbure** ramène à l'accueil (transactions du mois en cours).
En haut à droite, le bouton affiche le **login** de l'utilisateur connecté. Son menu
déroulant donne accès à :

- **Mon profil** ;
- **Déconnexion**.

## Transactions

![Transactions](assets/screenshot-transactions.png)

- Statistiques du mois : revenus, dépenses, solde.
- Liste compacte des transactions avec icône du type, libellé, date et montant.
- **Pointage** : le bouton rond à gauche de chaque transaction la marque comme vérifiée
  (coche verte) ou non vérifiée. Les transactions non vérifiées sont en gras.
- **Filtre** : à côté du nombre de transactions, un interrupteur on/off limite la liste
  aux transactions non vérifiées (avec leur nombre).
- **Recherche** : le champ de recherche (2 caractères minimum) interroge
  `GET /transaction/search` sur tous les mois et affiche le **total** des transactions
  trouvées ; le filtre s'applique aussi aux résultats. La croix efface la recherche.
- **Catégorisation manuelle** : une transaction sans catégorie propose un sélecteur
  « Catégoriser… ». Après le choix (la transaction est aussi pointée), un bandeau
  propose de **créer une règle** pour classer automatiquement ce libellé : il ouvre
  l'onglet Règles avec le libellé et la catégorie pré-remplis, le mot-clé pouvant être
  raccourci (dates, numéros de carte…).

## Budget

![Budget](assets/screenshot-budget.png)

- **Synthèse du mois** : dépensé, budgété, reste (ou dépassement) et jauge globale des
  dépenses budgétées.
- **Cartes par catégorie** avec l'icône et la couleur de la catégorie, le montant
  dépensé sur le budget, le reste ou le dépassement et une jauge colorée (en bonne voie,
  à partir de 80 %, au-delà de 100 %).
- Sections séparées : **dépenses budgétées**, **revenus**, **hors budget et sans
  activité**.
- **Clic sur une catégorie** : liste de ses transactions du mois (sous-catégories
  comprises) avec leur total et le pointage ; si elle a des sous-catégories, leurs
  cartes s'affichent, avec un fil d'Ariane pour remonter.
- Bouton de réglage d'une carte : modification du montant budgété pour le mois.

## Tendances

![Tendances](assets/screenshot-trends.png)

- Période de **3, 6 ou 12 mois** se terminant au mois courant.
- **Comparer à** : sans comparaison, la période précédente ou la même période l'an
  dernier.
- **Chiffres clés** : épargne cumulée, épargne moyenne par mois, taux d'épargne,
  dépenses moyennes par mois face au budget planifié ; en comparaison, l'écart (flèche,
  couleur et pourcentage) avec la période de référence.
- **Comparaison des totaux** (en mode comparaison) : crédit, débit, hors budget, budget
  planifié et épargne des deux périodes.
- **Revenus, dépenses et budget planifié** : barres mensuelles crédit / débit / hors
  budget et ligne du budget planifié, avec infobulle détaillée au survol.
- **Épargne mensuelle** : montants versés sur la catégorie **Épargne** et ses
  sous-catégories, moins les retraits (barres bleues, rouges pour un mois de retrait net).
  Le nom de la catégorie se règle avec `savings_category`. Le taux d'épargne est
  `épargne / revenus`.
- Vue **tableau** des données mois par mois.

## Règles

Gestion de la catégorisation automatique : une transaction dont le libellé contient le
mot-clé reçoit la catégorie choisie.

- Ajout d'une règle (mot-clé + catégorie ou sous-catégorie) dans une modale. Le sélecteur
  de catégorie affiche l'icône, la couleur et le parent de chaque catégorie, regroupées par
  type (dépenses, revenus, hors budget), avec une recherche.
- Liste des règles regroupées par catégorie, filtrable, avec suppression.
- **Resynchroniser** : applique les règles aux transactions non catégorisées, immédiatement
  l'historique et affiche le nombre de transactions mises à jour. Les règles sont
  aussi appliquées automatiquement après chaque synchronisation (dernier mois).

## Catégories

Arborescence des catégories (un niveau de sous-catégories) regroupée par type (dépenses,
revenus, hors budget), avec ajout et modification dans une modale, et suppression. L'icône et la couleur se choisissent parmi celles que l'application iOS sait
afficher (SF Symbols, couleurs SwiftUI), avec un aperçu. Supprimer une catégorie fait passer
ses transactions en « Non catégorisé » et supprime ses budgets et règles ; une catégorie qui
a des sous-catégories ne peut pas être supprimée. La catégorie « Non catégorisé » (0) est
protégée.

## Comptes

- **Comptes du foyer** (partagés par tous les utilisateurs) : chaque compte suivi avec
  l'utilisateur qui l'a ajouté, la date et le statut de sa
  dernière synchronisation (le détail de l'erreur au survol), et trois actions :
  synchroniser ce compte seul, modifier, ne plus suivre (les transactions déjà importées sont
  conservées).
- **Ajouter un compte** (fenêtre modale) : « Rechercher mes comptes dans woob » liste les
  comptes des banques configurées dans woob (libellé, solde) et remplit le formulaire en un
  clic ; sinon choisir la banque (configurées dans woob, ou toutes celles que woob supporte),
  et l'identifiant du compte. Les identifiants bancaires ne passent jamais par le
  portail : une nouvelle banque se configure avec `php tools/carbure.php add-bank`.
- **Synchronisation** : bouton de synchronisation de chaque compte, avec progression en direct, statut OK/KO de chaque
  étape par compte (opérations à venir, historique, notifications) et nombre de transactions
  reçues et nouvelles. En cas d'échec woob, un lien ouvre la recherche des tickets woob sur le
  module concerné.

## Analyses

Indicateurs du mois sélectionné (table `budget_insight`). Un administrateur les ajoute
(bouton **+**) et les modifie (crayon de chaque carte ; la suppression se fait dans la
modale) : un nom, une couleur, une icône et une requête SQL `SELECT … AS amount` où
`{month}` et `{year}` sont remplacés par le mois affiché. L'éditeur colore la syntaxe SQL et
fait vérifier la requête par le serveur pendant la saisie : erreur de syntaxe de MariaDB, ou
résultat pour le mois affiché.

## Utilisateurs (administrateurs)

L'onglet n'est visible que pour un administrateur (`is_admin`) : liste, création,
modification (dont le profil utilisateur ou administrateur) et suppression des utilisateurs.
Le dernier administrateur ne peut pas perdre son rôle.

## Mon profil

- Modification de l'e-mail, du prénom, du nom et du mot de passe (laisser vide pour le
  conserver). L'identifiant n'est pas modifiable.
- **Langue** (français / anglais) : enregistrée sur le compte et appliquée à chaque
  connexion, sur tous les navigateurs.
- **Seuil d'alerte (€)** : notification push sur l'iPhone pour toute nouvelle dépense
  supérieure à ce montant, détectée lors d'une synchronisation. Vide = désactivé.
- **Mes appareils** : appareils iOS enregistrés à la connexion depuis l'app, avec la
  date de dernière connexion et le token APNs, masqué par défaut. Les boutons permettent
  de l'afficher en entier, de le copier (HTTPS requis ; sinon le token est affiché) ou
  de **supprimer** un ancien appareil.

## Agents IA

Connexion d'un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) au serveur MCP de
Carbure, en lecture seule : un administrateur active ou désactive le serveur ; chaque
utilisateur crée ses jetons d'accès (le jeton et la configuration de chaque agent ne sont
affichés qu'une fois) et les révoque à tout moment. Voir [Agents IA (MCP)](mcp.md).

## Mode debug

Ajouter `?debug=true` à l'URL affiche un panneau avec la dernière requête API et active
des logs dans la console du navigateur.

## Organisation du code

| Fichier | Rôle |
|---|---|
| `index.html` | Gabarit Vue (tous les écrans) |
| `app.js` | Application racine : authentification, onglets, initialisation |
| `modules/*.js` | Mixins par domaine : transactions, budget, analyses, tendances, règles, utilisateurs, profil |
| `services/apiService.js` | Appels HTTP vers l'API |
| `stores/budgetStore.js` | Navigation dans l'arborescence des budgets |
| `components/*.js` | Composants (fil d'Ariane, élément de budget, modale) |
| `i18n.js` | Traductions `fr` et `en` |
| `utils/formatters.js` | Formatage des montants, dates, icônes |
| `style.css` | Styles (variables CSS dans `:root`) |
