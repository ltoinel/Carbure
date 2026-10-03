# Portail web

Le portail (`portal/`) est une application Vue 3 servie en fichiers statiques, sous
`https://<serveur>/portal/` (l'adresse racine y redirige). Il utilise la même API que
l'app iOS.

## Installation

Tant que Carbure n'est pas installé, le portail affiche l'**assistant d'installation**,
en trois étapes :

1. **Base de données** : serveur, port, nom de la base, utilisateur et mot de passe, déjà
   remplis avec Docker (laisser le mot de passe vide : celui de `docker/docker-compose.yml` est
   utilisé). Depuis Internet, un **code d'installation** est aussi demandé (journaux du
   conteneur ou fichier `data/conf/setup.code`).
2. **Installation** : l'assistant indique ce qu'il va faire — créer les tables d'une base
   vide, conserver une base Carbure à jour, ou mettre à jour une base Carbure existante
   (après avoir coché « J'ai une sauvegarde de ma base de données »). Il demande
   l'identifiant, le mot de passe (8 caractères minimum), l'e-mail et la langue du
   **compte administrateur** s'il n'en existe pas encore.
3. **Terminé** : connexion avec ce compte.

## Connexion et profils

L'écran de connexion demande l'URL de l'API, l'identifiant et le mot de passe. Le JWT et
l'URL sont conservés dans le navigateur (`localStorage`) jusqu'à la déconnexion ou
l'expiration de la session (30 jours). Après **5 échecs de connexion** d'affilée, le
compte est bloqué 24 heures ; un administrateur peut le débloquer.

Deux profils existent :

| Profil | Onglets |
|---|---|
| **Utilisateur** | Transactions, Budget, Insights, Tendances, Agents IA, et son profil |
| **Administrateur** | En plus : Règles, Catégories, Comptes et Utilisateurs ; ajout et modification des insights ; activation du serveur MCP ; mise à jour de la base de données |

Les pages d'administration (Règles, Catégories, Comptes, Utilisateurs) et les onglets
Insights et Agents IA suivent la même présentation : un bouton rond **+** en haut à droite
ouvre le formulaire dans une fenêtre modale, qui sert aussi à la modification.

## En-tête

- Le titre **Carbure** ramène à l'accueil : transactions du mois en cours.
- En haut à droite, le bouton affiche le **login** de l'utilisateur connecté ; son menu
  donne accès à **Mon profil** et **Déconnexion**.
- Le sélecteur de **mois** et d'**année** et le bouton rond **Actualiser** (icône seule) s'appliquent aux
  onglets Transactions, Budget et Insights. Les onglets Tendances et Règles ont leurs
  propres réglages.

!!! note "Mise à jour de la base de données"
    Quand une nouvelle version de Carbure modifie la base (migrations en attente), un
    bandeau **Mise à jour de la base de données disponible** s'affiche pour les
    administrateurs. Après avoir sauvegardé la base, le bouton **Mettre à jour** applique
    les migrations (`POST /system/migrate`) ; les données sont conservées. Avec Docker,
    elles sont de toute façon appliquées à chaque démarrage du conteneur.

## Transactions

![Transactions](assets/screenshot-transactions.png)

- Statistiques du mois : revenus, dépenses, solde.
- Liste compacte des transactions avec icône du type, libellé, date et montant.
- **Pointage** : le bouton rond à gauche de chaque transaction la marque comme vérifiée
  (coche verte) ou non vérifiée. Les transactions non vérifiées sont en gras.
- **Filtre** : à côté du nombre de transactions, un interrupteur limite la liste aux
  transactions non vérifiées (avec leur nombre).
- **Recherche** : le champ de recherche (2 caractères minimum) interroge
  `GET /transaction/search` sur tous les mois et affiche le **total** des transactions
  trouvées ; le filtre s'applique aussi aux résultats. La croix efface la recherche.
- **Catégorisation manuelle** : une transaction sans catégorie propose un sélecteur
  « Catégoriser… » ; la transaction est aussi pointée. Pour un administrateur, un bandeau
  propose ensuite de **créer une règle** pour classer automatiquement ce libellé : il ouvre
  l'onglet Règles avec le libellé et la catégorie pré-remplis, le mot-clé pouvant être
  raccourci (dates, numéros de carte…).
- **Détail d'une transaction** : un clic (ou Entrée) sur le libellé ouvre une fenêtre avec
  le montant, le libellé complet, les dates, le type, la carte et la date d'import ; la
  catégorie s'y change avec le sélecteur visuel et la transaction s'y pointe.

## Budget

![Flux du mois](assets/screenshot-flow.png)

Deux sous-onglets : **Flux** (affiché par défaut) et **Budgets**.

- **Flux du mois** (sous-onglet Flux) : diagramme de Sankey qui
  montre d'où vient l'argent du mois et où il va — revenus par catégorie → revenus du mois
  → dépenses par catégorie principale (les 8 plus importantes, le reste regroupé dans
  « Autres »), épargne et **Reste** (ou **Déficit**). Les virements internes (hors budget)
  sont exclus. « Voir le tableau » affiche les mêmes flux en tableau (`GET /budget/flow`).
- **Synthèse du mois** (sous-onglet Budgets) : dépensé, budgété, reste (ou dépassement) et jauge globale des
  dépenses budgétées.
- **Cartes par catégorie** avec l'icône et la couleur de la catégorie, le montant
  dépensé sur le budget, le reste ou le dépassement ; le fond de la carte se remplit de
  gauche à droite à mesure que le budget est consommé (vert en bonne voie, orange à partir
  de 80 %, rouge au-delà de 100 %).
- Sections séparées : **dépenses budgétées**, **revenus**, **hors budget et sans
  activité**.
- **Clic sur une catégorie** : liste de ses transactions du mois (sous-catégories
  comprises) avec leur total et le pointage ; si elle a des sous-catégories, leurs
  cartes s'affichent, avec un fil d'Ariane pour remonter.
- Bouton de réglage d'une carte : modification du montant budgété pour le mois.

## Insights

Indicateurs du mois sélectionné, sous forme de cartes (icône, couleur, montant).

Un administrateur les ajoute (bouton **+**) et les modifie (crayon de chaque carte ; la
suppression se fait dans la modale) : un nom (20 caractères au plus), une couleur parmi
13, une icône et une requête SQL `SELECT … AS amount` où `{month}` et `{year}` sont
remplacés par le mois affiché. L'éditeur colore la syntaxe SQL et fait vérifier la requête
par le serveur pendant la saisie : erreur (mot-clé interdit, syntaxe MariaDB…) ou résultat
pour le mois affiché. La requête est exécutée en lecture seule et ne peut pas lire les
tables `users`, `api_tokens` et `devices` (voir [Sécurité](securite.md#insights-sql-stocke)).

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
- **Épargne mensuelle** : montants versés sur la catégorie **Epargne** et ses
  sous-catégories, moins les retraits (barres bleues, rouges pour un mois de retrait net).
  Le nom de la catégorie se règle avec `savings_category` (casse et accents ignorés). Le
  taux d'épargne est `épargne / revenus`.
- Vue **tableau** des données mois par mois.

## Règles (administrateurs)

Catégorisation automatique : une transaction dont le libellé contient le mot-clé reçoit
la catégorie choisie.

- **+** : ajout d'une règle (mot-clé + catégorie ou sous-catégorie) dans une modale. Le
  sélecteur de catégorie affiche l'icône, la couleur et le parent de chaque catégorie,
  regroupées par type (dépenses, revenus, hors budget), avec une recherche.
- Liste des règles regroupées par catégorie, filtrable, avec suppression.
- **Resynchroniser** : applique toutes les règles à toutes les transactions de la base,
  déjà catégorisées comprises, et affiche le nombre de transactions recatégorisées. Créer ou
  modifier une règle l'applique aussi immédiatement à tout l'historique (ce qui remplace une
  catégorie choisie à la main sur une transaction concernée) ; les nouvelles transactions
  sont catégorisées à chaque synchronisation.
- Un clic sur une règle ouvre sa fenêtre : modifier le mot-clé et la catégorie, voir combien
  de transactions contiennent le mot-clé (et combien sont déjà dans la catégorie), la
  supprimer (corbeille), et cocher **Notifier le foyer** : chaque nouvelle transaction
  synchronisée qui correspond à la règle envoie une notification à tous les utilisateurs du
  foyer (cloche sur la règle).

## Catégories (administrateurs)

Arborescence des catégories (un niveau de sous-catégories) regroupée par type (dépenses,
revenus, hors budget). **+** ajoute une catégorie, le crayon la modifie (dans une modale),
la corbeille la supprime. L'icône et la couleur se choisissent parmi celles que
l'application iOS sait afficher, avec un aperçu. Supprimer une catégorie fait passer ses
transactions en « Non catégorisé » et supprime ses budgets et règles ; une catégorie qui a
des sous-catégories ne peut pas être supprimée. La catégorie « Non catégorisé » (0) est
protégée.

## Comptes (administrateurs)

- **Comptes du foyer** (partagés par tous les utilisateurs) : chaque compte suivi avec
  « Ajouté par » (l'utilisateur qui l'a ajouté), la date et le statut de sa dernière
  synchronisation (Synchronisé, Échec ou Jamais synchronisé ; le détail au survol), et
  trois actions : **synchroniser ce compte**, modifier, ne plus suivre (les transactions
  déjà importées sont conservées).
- **+** (fenêtre modale) : « Rechercher mes comptes dans woob » liste les comptes des
  banques configurées dans woob (libellé, solde) et les suit en un clic ; sinon choisir la
  banque (configurée dans woob, ou parmi toutes celles que woob supporte) et saisir
  l'identifiant du compte. Pour une banque pas encore configurée, le formulaire demande les
  identifiants attendus par son module woob (numéro client, code, type de compte…) :
  **Connecter la banque** les confie à woob (Carbure ne les enregistre ni ne les journalise)
  et affiche les comptes trouvés, à suivre en un clic.
- **Synchronisation** : progression en direct, statut OK/KO de chaque étape par compte
  (opérations à venir, historique, notifications), nombre de transactions reçues et
  nouvelles, journal détaillé. En cas d'échec woob, un lien ouvre la recherche des tickets
  woob sur le module concerné. Pendant une synchronisation, l'icône de l'onglet tourne.

La synchronisation de tous les comptes est planifiée côté serveur (`SYNC_INTERVAL` avec
Docker, ou une tâche cron).

## Agent (agents IA)

Connexion d'un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) au serveur MCP de
Carbure, en lecture seule. Voir [Agents IA (MCP)](mcp.md).

- **Serveur MCP activé** : interrupteur réservé aux administrateurs (désactivé par
  défaut) ; les utilisateurs voient seulement son état.
- **+** : création d'un jeton d'accès (nom et durée de validité : 30 jours, 90 jours, 1 an
  ou sans expiration), possible seulement quand le serveur est activé. Le jeton et la
  configuration prête à copier pour chaque agent ne sont affichés qu'une fois.
- **Mes jetons d'accès** : date de création, dernière utilisation, expiration (ou
  « Expiré ») ; la corbeille révoque un jeton.

## Utilisateurs (administrateurs)

Liste des utilisateurs avec leur **profil** (Utilisateur ou Administrateur) et leur
**dernière connexion** ; un compte bloqué après trop d'échecs de connexion affiche
« Bloqué jusqu'au … » et un bouton **Débloquer**. **+** crée un utilisateur ; le crayon le
modifie (dont son profil) ; la corbeille le supprime. Le dernier administrateur ne peut
pas perdre son rôle, et un utilisateur propriétaire de transactions ne peut pas être
supprimé.

## Logs (administrateurs)

Les entrées des fichiers de log du serveur (`data/logs/carbure_AAAAMMJJ.log`), les plus
récentes d'abord : choix du fichier, niveau minimal (DEBUG, INFO, WARN, ERROR) et recherche
de texte. Un clic sur l'identifiant d'une requête affiche toutes ses entrées. Seule la fin
d'un gros fichier (2 Mo) est lue ; les jetons de session et des agents IA sont masqués.

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

## Pied de page

Lien vers le dépôt GitHub de Carbure et **version** de l'instance (`v1.2.0`, ou `dev` hors
release), lue dans `GET /api/health`.

## Accessibilité

Le portail vise le niveau AA des WCAG (score Lighthouse « Accessibilité » de 100 sur les
onglets et les fenêtres) :

- contrastes suffisants (rouge de la marque assombri à 5:1 sur blanc) et focus clavier
  toujours visible ;
- lien « Aller au contenu », repères de page (bannière, navigation, contenu principal) et
  onglet courant annoncé ;
- icônes décoratives masquées aux lecteurs d'écran, boutons à icône seule nommés, champs
  associés à leur libellé, fenêtres modales annoncées comme dialogues et fermées avec
  **Échap** ;
- messages (toasts, erreurs) annoncés par les lecteurs d'écran, langue de la page suivant
  celle de l'utilisateur ;
- graphiques doublés d'un tableau (Tendances, Flux du mois) et animations coupées quand le
  système demande moins de mouvements.

## Mode debug

Ajouter `?debug=true` à l'URL affiche un panneau avec la dernière requête API et active
des logs dans la console du navigateur.

## Organisation du code

| Fichier | Rôle |
|---|---|
| `index.html` | Gabarit Vue (tous les écrans, dont l'assistant d'installation) |
| `app.js` | Application racine : authentification, onglets (onglets d'administration réservés), initialisation |
| `modules/*.js` | Mixins par domaine : `setup` (assistant), `transaction`, `budget`, `insights`, `trends`, `rules`, `categories`, `accounts`, `sync`, `agents`, `user`, `profile` (profil, appareils, mise à jour de la base) |
| `services/apiService.js` | Appels HTTP vers l'API |
| `stores/budgetStore.js` | Navigation dans l'arborescence des budgets |
| `components/*.js` | Composants : fil d'Ariane et élément de budget, modale de budget, diagramme de flux (`FlowChart`), sélecteur de catégorie (`CategoryPicker`), éditeur SQL (`SqlEditor`) |
| `i18n.js` | Traductions `fr` et `en` |
| `utils/formatters.js`, `utils/categoryIcons.js` | Formatage des montants, dates, icônes ; icônes et couleurs des catégories |
| `vendor/` | Vue, CodeMirror (éditeur SQL) et polices, servis localement |
| `style.css` | Styles (variables CSS dans `:root`) |
