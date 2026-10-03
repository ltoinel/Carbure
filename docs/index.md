# Carbure

**Carbure** est un portail web de suivi du budget et des transactions bancaires du foyer,
gratuit et auto-hébergé. Les opérations sont récupérées automatiquement auprès de la
banque grâce à [woob](https://woob.tech/), nettoyées et catégorisées par mots-clés, puis
consultées :

- dans le **portail web** (`portal/`, Vue 3) : transactions, pointage, recherche,
  budgets, insights, tendances, et pour les administrateurs règles, catégories, comptes
  et utilisateurs ;
- dans l'**application iOS** Carbure, qui reçoit aussi des notifications push (APNs)
  après chaque synchronisation et pour les dépenses importantes ;
- par un **agent IA** (Claude, ChatGPT, Cursor, Copilot, Gemini…) grâce au serveur MCP
  intégré, en lecture seule.

Tous s'appuient sur la même **API REST PHP** (`src/`).

![Budget du mois](assets/screenshot-budget.png)

## Fonctionnalités

| Domaine | Ce que fait Carbure |
|---|---|
| Installation | Assistant dans le navigateur (base de données, schéma, administrateur), image Docker nginx + PHP-FPM avec woob, mises à jour de la base automatiques ou en un clic |
| Synchronisation | Opérations à venir et historique via woob, de tous les comptes ou d'un seul, progression en temps réel (SSE), date et résultat de la dernière synchronisation de chaque compte, synchronisation quotidienne planifiable |
| Comptes | Comptes du foyer, recherche des comptes dans woob et suivi en un clic, liste des banques supportées par woob |
| Transactions | Liste mensuelle, recherche par libellé avec total, pointage (vérifiée / non vérifiée), catégorisation manuelle avec proposition de règle |
| Budgets | Flux du mois (diagramme de Sankey revenus → dépenses, épargne, reste), synthèse du mois, budget par catégorie avec reste ou dépassement, sous-catégories, transactions d'une catégorie |
| Tendances | Débit, crédit, hors budget, budget planifié et épargne (catégorie « Epargne ») sur 3, 6 ou 12 mois, comparaison avec une autre période |
| Catégorisation | Catégories hiérarchiques (icône, couleur), règles par mots-clés appliquées à chaque synchronisation ou à la demande |
| Insights | Indicateurs mensuels définis par une requête SQL encadrée, historique annuel |
| Notifications | Push APNs après chaque synchronisation, et alerte pour toute nouvelle dépense au-dessus du seuil de l'utilisateur |
| Agents IA | Serveur MCP en lecture seule, activable par un administrateur, jetons d'accès à durée de validité, configurations prêtes pour six agents |
| Utilisateurs | Profils utilisateur et administrateur, langue fr/en, seuil d'alerte, appareils, dernière connexion, blocage après 5 échecs de connexion |

## Par où commencer ?

- Installer Carbure : [Installation](installation.md) ou le [tutoriel NAS Synology](synology.md)
- Utiliser le portail : [Portail web](portail.md)
- Connecter un agent IA : [Agents IA (MCP)](mcp.md)
- Comprendre le fonctionnement : [Architecture](architecture.md)
- Intégrer un client : [Référence de l'API](api.md)
- Contribuer : [Développement](developpement.md)

!!! info "Modèle « foyer »"
    Tous les utilisateurs d'une installation partagent les mêmes comptes bancaires,
    transactions, budgets et catégories. Les comptes utilisateurs servent à
    l'authentification, aux notifications, aux préférences (langue, seuil d'alerte,
    appareils, jetons d'accès) et au profil (utilisateur ou administrateur).
