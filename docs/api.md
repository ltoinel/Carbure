# Référence de l'API

- **Base** : `https://<serveur>/api`
- **Format** : JSON en entrée (corps) et en sortie. Les paramètres peuvent aussi être
  passés en query string ; corps et query string sont fusionnés.
- **Authentification** : en-tête `Authorization: Bearer <token>` sur toutes les routes,
  sauf `POST /user/login` et `GET /bank/sync` (voir ci-dessous).
- **Paramètres nommés** : chaque clé de la requête correspond à un paramètre de la
  méthode PHP. Une clé inconnue renvoie une erreur 400.

## Erreurs

| Code | Cas |
|---|---|
| 400 | Paramètre manquant, inconnu ou invalide ; JSON invalide |
| 401 | Identifiants invalides |
| 403 | JWT absent/invalide, ou action réservée à un administrateur |
| 404 | Route ou ressource introuvable |
| 409 | Conflit (ex. suppression d'un utilisateur propriétaire de transactions) |
| 500 | Erreur serveur (base de données, woob, APNs…) |

```json
{ "error": "Unauthorized - Invalid or missing JWT token", "code": 403, "uid": "6ac106d3912d9" }
```

`uid` identifie la requête dans `logs/carbure_AAAAMMJJ.log`.

## Utilisateurs

### `POST /user/login` — publique

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `username` | string | oui | Identifiant |
| `password` | string | oui | Mot de passe |
| `device` | objet `{name, token}` | non | Appareil iOS à enregistrer pour les notifications |

```bash
curl -X POST https://exemple.fr/api/user/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"…","device":{"name":"iPhone","token":"<token APNs>"}}'
```

```json
{ "exp": 1762000000, "sub": 1, "iat": 1759400000, "token": "eyJ0eXAiOiJKV1Qi…" }
```

Le jeton est valable 30 jours.

### `GET /user/me`

Profil de l'utilisateur connecté : `id`, `username`, `firstname`, `lastname`, `email`,
`is_admin`, `language`, `alert_threshold` (`null` si les alertes sont désactivées).

```bash
curl https://exemple.fr/api/user/me -H "Authorization: Bearer $TOKEN"
```

### `GET /user`

Liste des utilisateurs pour un administrateur ; l'utilisateur lui-même sinon.

### `POST /user` — administrateur

`username`, `password`, `email` (requis), `firstname`, `lastname` (facultatifs).

### `PUT /user`

| Paramètre | Requis | Description |
|---|---|---|
| `id` | oui | Utilisateur à modifier (soi-même, ou n'importe qui pour un administrateur) |
| `email`, `firstname`, `lastname` | non | Nouvelles valeurs |
| `password` | non | Nouveau mot de passe (ignoré si vide) |
| `language` | non | `fr` ou `en` |
| `alertThreshold` | non | Seuil d'alerte en euros pour les nouvelles dépenses ; `""` ou `0` désactive les alertes |

```bash
curl -X PUT "https://exemple.fr/api/user?id=1" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"language":"en"}'
```

Renvoie l'utilisateur mis à jour.

### `DELETE /user` — administrateur

`id` : impossible de se supprimer soi-même (400) ou de supprimer un utilisateur
propriétaire de transactions (409).

## Comptes et synchronisation

### `GET /bank`

Comptes bancaires de l'utilisateur :

```json
[{ "bankId": "12345678@bnp", "account_number": "12345678", "bank_name": "bnp" }]
```

### `GET /bank/sync` — flux SSE

Lance la synchronisation de **tous** les comptes. Accès autorisé avec :

- un JWT valide, **ou**
- le `sync_token` de la configuration, dans l'en-tête `X-Sync-Token` ou le paramètre
  `?token=`.

La réponse est un flux `text/event-stream` : messages `data: …` (progression, libellés
importés) et commentaires `: heartbeat` toutes les 15 s pendant l'exécution de woob. Une
seule synchronisation peut tourner à la fois.

```bash
curl -N -H "X-Sync-Token: $SYNC_TOKEN" https://exemple.fr/api/bank/sync
```

Pendant la synchronisation, chaque **nouvelle** dépense (transaction absente jusque-là)
dont le montant atteint le seuil `alert_threshold` d'un propriétaire du compte déclenche
une notification « Dépense importante à vérifier » (dans la langue de l'utilisateur).

```text
data: Syncing 12345678@bnp (coming)...
data: Syncing 12345678@bnp (history)...
data: Notifying users of 12345678@bnp...
data: Updating missing categories...
data: Synchronization complete
```

## Transactions

### `GET /transaction`

| Paramètre | Requis | Description |
|---|---|---|
| `month`, `year` | non | Mois des transactions (mois courant par défaut) |
| `category` | non | Limite aux transactions de cette catégorie **et de ses sous-catégories** |

Les transactions sont triées par date réelle décroissante. Champs : `id`, `uuid`,
`imported`, `date`, `rdate`, `type`, `label`, `category`, `amount`, `card`, `pointed`,
`user`.

```bash
curl "https://exemple.fr/api/transaction?month=9&year=2026&category=1" -H "Authorization: Bearer $TOKEN"
```

Types : 1 virement, 2 prélèvement, 3 chèque, 4 remise de chèque, 5 remboursement,
6 retrait DAB, 7 facture carte, 8 dépense, 9 commissions, 12 carte en cours.

### `GET /transaction/search`

| Paramètre | Requis | Description |
|---|---|---|
| `query` | oui | Texte recherché dans le libellé (2 caractères minimum, insensible à la casse) |
| `limit` | non | Nombre maximal de résultats (1 à 500, défaut 100) |

```bash
curl "https://exemple.fr/api/transaction/search?query=amazon" -H "Authorization: Bearer $TOKEN"
```

### `PUT /transaction/category`

`id`, `category` : affecte la catégorie et pointe la transaction.

### `PUT /transaction/pointed`

`id`, `pointed` (booléen, défaut `true`) : marque la transaction comme vérifiée ou non.

```bash
curl -X PUT https://exemple.fr/api/transaction/pointed -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"pointed":false}'
```

## Budgets et analyses

### `GET /budget`

`month`, `year`, `category` (catégorie parente, `0` = racine). Pour chaque catégorie :
`id`, `name`, `type`, `icon`, `color`, `budget`, `consummed` (somme absolue des
transactions de la catégorie et de ses sous-catégories), `progress` (%).

### `POST /budget`

`category`, `amount` (requis), `month`, `year` (mois courant par défaut) : crée ou met à
jour le budget de la catégorie pour le mois.

### `GET /budget/trends`

| Paramètre | Requis | Description |
|---|---|---|
| `months` | non | Nombre de mois (1 à 60, défaut 24) |
| `offset` | non | Nombre de mois entre le mois courant et le dernier mois de la période (défaut 0). Sert aux comparaisons : `offset=months` pour la période précédente, `offset=12` pour la même période l'an dernier |

Renvoie un élément par mois (du plus ancien au plus récent, mois vides inclus) :

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `debit` | Dépenses du mois, catégories hors budget exclues (montant positif) |
| `credit` | Revenus du mois, catégories hors budget exclues |
| `offBudget` | Solde des catégories `HORS-BUDGET` (ex. virements vers l'épargne) |
| `planned` | Somme des budgets des catégories de premier niveau |
| `savings` | `credit - debit` (hors budget exclu) |

```bash
curl "https://exemple.fr/api/budget/trends?months=6&offset=12" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2025-05", "debit": 2130.37, "credit": 5114, "offBudget": -500, "planned": 2330, "savings": 2983.63 }]
```

### `GET /budget/insights`

`month`, `year` : indicateurs définis dans la table `budget_insight` (`id`, `name`,
`color`, `amount`).

### `GET /budget/insights/history`

`year` : pour chaque indicateur, la liste `history` des montants mois par mois.

## Catégories

### `GET /category`

Toutes les catégories : `id`, `name`, `parent_category`, `type` (`DEBIT`, `CREDIT`,
`HORS-BUDGET`), `icon`, `color`.

### `GET /category/keyword`

Règles de catégorisation automatique : `id`, `keyword`, `category`, `category_name`.

### `POST /category/keyword`

`keyword` (1 à 60 caractères, enregistré en majuscules), `category` : ajoute une règle.
Erreurs : 400 mot-clé vide, 404 catégorie inconnue, 409 mot-clé déjà existant.

```bash
curl -X POST https://exemple.fr/api/category/keyword -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"keyword":"decathlon","category":9}'
```

### `DELETE /category/keyword`

`id` : supprime une règle (404 si inconnue).

### `POST /category/keyword/apply`

Applique les règles à toutes les transactions sans catégorie. Renvoie
`{ "updated": <nombre de transactions catégorisées> }`.

## Appareils

### `GET /device`

Appareils de l'utilisateur connecté : `id`, `user_id`, `name`, `token`, `lastLogin`.

### `DELETE /device`

`id` : supprime un appareil de l'utilisateur connecté (il ne recevra plus de
notifications). 404 si l'appareil n'existe pas ou appartient à un autre utilisateur.

### `POST /device/push`

Envoie une notification de test à tous les appareils de l'utilisateur connecté.

## Spécification OpenAPI

`swagger/swagger.php` génère une spécification OpenAPI 3 par réflexion sur les
attributs `#[ApiRoute]` ; `swagger/index.html` l'affiche avec Swagger UI.
