<p align="center">
  <img src="portal/icon.png" alt="Carbure" width="96">
</p>

<h1 align="center">Carbure</h1>

<p align="center">
  <strong>Le budget de votre foyer, enfin sous contrôle.</strong><br>
  Vos comptes bancaires synchronisés automatiquement, vos dépenses catégorisées,<br>
  vos budgets suivis au jour le jour — sur le web et sur iPhone.
</p>

<p align="center">
  <a href="https://github.com/ltoinel/Carbure/actions/workflows/ci.yml"><img src="https://github.com/ltoinel/Carbure/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/ltoinel/Carbure/releases"><img src="https://img.shields.io/github/v/release/ltoinel/Carbure" alt="Release"></a>
  <a href="https://github.com/ltoinel/Carbure/actions/workflows/ci.yml"><img src="https://img.shields.io/badge/coverage-%E2%89%A5%2090%25-brightgreen" alt="Coverage"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://mariadb.org/"><img src="https://img.shields.io/badge/MariaDB%20%2F%20MySQL-003545?logo=mariadb&logoColor=white" alt="MariaDB"></a>
  <a href="https://ltoinel.github.io/Carbure/"><img src="https://img.shields.io/badge/docs-GitHub%20Pages-EF4860" alt="Docs"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue" alt="License: MIT"></a>
</p>

<p align="center">
  <img src="docs/assets/screenshot-trends.png" alt="Tendances sur 12 mois comparées à la période précédente" width="820">
</p>

## Pourquoi Carbure ?

- 🔄 **Zéro saisie** : vos opérations arrivent toutes seules depuis votre banque grâce à
  [woob](https://woob.tech/), chaque jour.
- 🏷️ **Catégorisation automatique** : des règles simples (« CARREFOUR → Supermarché ») classent
  chaque transaction. Une nouvelle ? Choisissez sa catégorie, Carbure vous propose la règle.
- 🎯 **Budgets mensuels** : dépensé, restant, dépassement, catégorie par catégorie, d'un coup d'œil.
- 📈 **Tendances** : revenus, dépenses, épargne sur 3, 6 ou 12 mois, comparés à la période
  précédente ou à l'an dernier.
- ✅ **Pointage** : vérifiez vos transactions en un clic, filtrez celles qui restent à contrôler.
- 🔔 **Alertes sur iPhone** : un push après chaque synchronisation, et dès qu'une dépense dépasse
  le seuil que vous avez choisi.
- 👨‍👩‍👧 **Pensé pour le foyer** : plusieurs utilisateurs, des données partagées, chacun sa langue
  et ses appareils.
- 🔒 **Chez vous** : auto-hébergé (un NAS suffit), vos données bancaires ne quittent pas votre réseau.

<table>
  <tr>
    <td><img src="docs/assets/screenshot-budget.png" alt="Budgets du mois par catégorie"></td>
    <td><img src="docs/assets/screenshot-transactions.png" alt="Transactions du mois, recherche et pointage"></td>
  </tr>
  <tr>
    <td align="center"><em>Budgets du mois</em></td>
    <td align="center"><em>Transactions, recherche et pointage</em></td>
  </tr>
</table>

## Comment ça marche

```mermaid
flowchart LR
    bank[(Banques)] -->|woob| sync[Synchronisation]
    subgraph Carbure
        sync --> rules[Règles de<br/>catégorisation] --> db[(MariaDB)]
        api[API REST PHP] <--> db
    end
    portal[Portail web] <--> api
    ios[App iOS] <--> api
    sync -->|APNs| push[Notifications iPhone]
```

## Démarrage rapide

```bash
git clone https://github.com/ltoinel/Carbure.git
cd Carbure
cp conf/prod.sample.ini conf/prod.ini        # base de données, jwtsecret, woob, APNs
mysql carbure < sql/carbure.sql              # schéma
```

Servez le dossier avec PHP ≥ 8.2 (Nginx/Apache + PHP-FPM), routez `/api/*` vers `src/api.php`,
puis ouvrez `/portal/`. Planifiez `/api/bank/sync` (avec votre `sync_token`) pour la synchronisation
quotidienne.

👉 **Installation détaillée, configuration, référence de l'API, modèle de données et sécurité :
[la documentation](https://ltoinel.github.io/Carbure/).**

## Contribuer

Les contributions sont les bienvenues : lisez [AGENTS.md](AGENTS.md) (conventions, tests,
définition de « terminé ») et la liste des tâches ouvertes dans [TODO.md](TODO.md).

## Licence

[MIT](LICENSE) © Ludovic Toinel
