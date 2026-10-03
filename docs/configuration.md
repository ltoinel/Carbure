# Configuration

La configuration est lue dans `conf/<env>.ini`, où `<env>` vaut la variable
d'environnement `APP_ENV` (`prod` par défaut, `testing` pour les tests). Les
commentaires commencent par `;` (le `#` n'est pas accepté par PHP).

Partir de `conf/prod.sample.ini`. `conf/prod.ini` contient des secrets : il est ignoré
par Git et ne doit jamais être publié.

## `[global]`

| Clé | Valeurs | Description |
|---|---|---|
| `log_level` | `debug`, `info`, `warning`, `error` | Niveau minimal des logs (`logs/carbure_AAAAMMJJ.log`). En `debug`, les erreurs PHP sont affichées et les réponses JSON indentées. |
| `development` | `true` / `false` | Mode développement |

## `[database]`

| Clé | Description |
|---|---|
| `db_hostname` | Hôte MariaDB/MySQL |
| `db_username`, `db_password` | Identifiants |
| `db_name` | Nom de la base |

## `[cleansing]`

```ini
regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""
regex_label["/(PRLV SEPA.*)(ECH.*|MDT.*)$/"]="$1"
```

Expressions régulières appliquées, dans l'ordre, au libellé en majuscules de chaque
transaction importée.

!!! warning "Ne pas modifier sans migration"
    Le libellé nettoyé entre dans l'UUID des transactions. Changer ces règles fait
    réimporter les transactions existantes sous un autre UUID (doublons).

## `[auth]`

| Clé | Description |
|---|---|
| `jwtsecret` | Secret HMAC des JWT (durée de validité : 30 jours). **À générer** : `openssl rand -hex 32`. Le changer déconnecte tous les clients. |
| `password_salt` | Sel des anciens mots de passe SHA-256, utilisé seulement pour les migrer vers bcrypt à la connexion |
| `sync_token` | Secret permettant au planificateur d'appeler `/api/bank/sync` sans JWT. Vide = seul un utilisateur connecté peut lancer la synchro. |

## `[woob]`

| Clé | Description |
|---|---|
| `woob_path` | Commande woob : binaire local (`/usr/bin/woob`) ou conteneur (`docker run -v …:/root/ ltoinel/woob:3.7 woob`) |
| `woob_transactions` | Nombre d'opérations demandées par appel (`--count`) |
| `woob_logging` | Niveau de log de woob |
| `woob_debug` | `true` pour journaliser la sortie complète de woob |
| `woob_auto_update` | `true` pour laisser woob mettre à jour ses modules |

## `[apns]`

| Clé | Description |
|---|---|
| `apns_bundle_id` | Bundle ID de l'app iOS (sujet APNs) |
| `apns_auth_method` | `token` (clé `.p8`, recommandé) ou `certificate` |
| `apns_key_path` | Chemin de la clé `.p8` (absolu ou relatif à la racine du projet, ex. `conf/certs/AuthKey_XXXX.p8`) |
| `apns_key_id`, `apns_team_id` | Identifiants Apple Developer |
| `apns_certificate_path`, `apns_certificate_password` | Pour l'authentification par certificat |
| `apns_environment` | `production` ou `sandbox` |

Les clés `.p8` placées dans `conf/certs/` sont ignorées par Git.
