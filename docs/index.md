# Carbure

**Carbure** est un portail web de suivi du budget et des transactions bancaires du foyer.
Les opérations sont récupérées automatiquement auprès de la banque grâce à
[woob](https://woob.tech/), nettoyées et catégorisées par mots-clés, puis consultées :

- dans le **portail web** (`portal/`, Vue 3) : transactions, pointage, recherche,
  budgets, tendances, règles de catégorisation, utilisateurs et profil ;
- dans l'**application iOS** Carbure, qui reçoit aussi des notifications push (APNs)
  après chaque synchronisation et pour les dépenses importantes.

Les deux s'appuient sur la même **API REST PHP** (`src/`).

![Budget du mois](assets/screenshot-budget.png)

## Fonctionnalités

| Domaine | Ce que fait Carbure |
|---|---|
| Synchronisation | Opérations à venir et historique via woob, progression en temps réel (SSE), verrou anti-synchros concurrentes |
| Transactions | Liste mensuelle, recherche par libellé avec total, pointage (vérifiée / non vérifiée), catégorisation manuelle |
| Budgets | Synthèse du mois, budget par catégorie avec reste ou dépassement, sous-catégories, transactions d'une catégorie |
| Tendances | Débit, crédit, hors budget, budget planifié et épargne sur 3, 6 ou 12 mois, comparaison avec une autre période |
| Catégorisation | Règles par mots-clés, appliquées à chaque synchronisation ou à la demande |
| Analyses | Indicateurs mensuels (insights) et historique annuel |
| Notifications | Push APNs après chaque synchronisation, et alerte pour toute nouvelle dépense au-dessus du seuil de l'utilisateur |
| Utilisateurs | JWT, rôle administrateur, profil (langue fr/en, seuil d'alerte), appareils enregistrés |

## Par où commencer ?

- Comprendre le fonctionnement : [Architecture](architecture.md)
- Installer un serveur : [Installation](installation.md) puis [Configuration](configuration.md)
- Intégrer un client : [Référence de l'API](api.md)
- Utiliser le portail : [Portail web](portail.md)
- Contribuer : [Développement](developpement.md)

!!! info "Modèle « foyer »"
    Tous les utilisateurs d'une installation partagent les mêmes transactions, budgets
    et catégories. Les comptes utilisateurs servent à l'authentification, aux
    notifications et aux préférences (langue, appareils).
