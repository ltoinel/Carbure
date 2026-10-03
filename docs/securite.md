# Sécurité

## Authentification

- `POST /user/login` vérifie le mot de passe et renvoie un **JWT HS256** signé avec
  `jwtsecret`, valable **30 jours** (`sub` = identifiant de l'utilisateur).
- Les autres routes exigent `Authorization: Bearer <token>` ; la signature est comparée
  en temps constant et l'expiration est contrôlée.
- Les mots de passe sont hachés avec `password_hash()` (bcrypt). Les anciens hachages
  SHA-256 salés sont vérifiés une dernière fois puis remplacés à la connexion.

## Autorisations

| Rôle | Droits |
|---|---|
| Utilisateur | Données du foyer (transactions, budgets, tendances, analyses), pointage et catégorie des transactions, synchronisation, son profil, ses appareils |
| Administrateur (`users.is_admin = 1`) | En plus : règles de catégorisation, catégories, comptes bancaires suivis et utilisateurs (dont leur profil) |

Un administrateur ne peut pas supprimer son propre compte ; un utilisateur propriétaire
de transactions ne peut pas être supprimé ; le dernier administrateur ne peut pas perdre
son rôle.

## En-têtes HTTP

| En-tête | API (PHP, toute installation) | Portail (nginx de l'image, `.htaccess` Apache) |
|---|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | ressources du serveur uniquement ; `'unsafe-eval'` (Vue compile les modèles dans le navigateur) et styles en ligne |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `Referrer-Policy` | `no-referrer` | `no-referrer` |
| `Cache-Control` | `no-store` (données bancaires) | — |
| `Permissions-Policy`, `Cross-Origin-Opener-Policy` | — | caméra, micro, géolocalisation, paiement désactivés ; `same-origin` |

L'API ne révèle pas la version de PHP (`X-Powered-By` retiré) et nginx pas la sienne
(`server_tokens off`). **HSTS** est à activer sur le proxy HTTPS placé devant Carbure (proxy
inversé du NAS, Traefik, Caddy…), le conteneur servant du HTTP. Sur un serveur nginx sans
Docker, reprendre les en-têtes de `docker/nginx.conf`.

## Assistant d'installation

Tant que `conf/prod.ini` n'existe pas, l'API ne répond qu'à `/api/setup` (le reste renvoie
`503`). Depuis le réseau local, l'assistant est accessible sans code, comme la plupart des
applications auto-hébergées ; depuis Internet (adresse publique), ou dès que le fichier
`conf/setup.code` existe, chaque étape exige le **code d'installation** de ce fichier, aussi
écrit dans les journaux du serveur (`docker logs`). Une erreur de code est ralentie. Le mot
de passe de la base fourni par Docker (`DB_PASSWORD`) n'est utilisé que pour la base de
l'environnement, jamais pour un autre serveur saisi dans l'assistant. Une fois la
configuration écrite, l'assistant disparaît et le code est supprimé.

## Insights (SQL stocké)

Les insights sont des requêtes SQL stockées en base et exécutées par le serveur. Seul un
administrateur peut les gérer, et chaque requête est encadrée :

- à l'enregistrement : un unique `SELECT`, sans `;` ni commentaire, sans mot-clé d'écriture,
  d'administration, de fichier (`INTO OUTFILE`, `LOAD_FILE`) ou de temporisation (`SLEEP`,
  `BENCHMARK`), sans accès aux tables `users`, `api_tokens`, `devices` ni aux schémas système ;
  la requête est testée avant d'être enregistrée ;
- à l'exécution : dans une **transaction en lecture seule** (`START TRANSACTION READ ONLY`),
  et une requête en échec donne `0` sans bloquer les autres.

## Jetons d'accès (MCP)

Le serveur MCP (`/api/mcp`) accepte des jetons d'accès créés par chaque utilisateur dans
son profil : 192 bits aléatoires, préfixe `cbt_`, **seule l'empreinte SHA-256 est stockée**,
révocables, avec la date de dernière utilisation. Ils ne donnent accès qu'aux outils en
lecture seule du serveur MCP, pas au reste de l'API. Le serveur refuse les requêtes
portant un en-tête `Origin` d'un autre site (protection contre le DNS rebinding).

## Routes publiques

Seules ces routes ne demandent pas de JWT (un test unitaire le vérifie) :

- `POST /user/login` ;
- `GET /bank/sync`, qui exige **soit** un JWT, **soit** le `sync_token` de la
  configuration (en-tête `X-Sync-Token` de préférence, ou `?token=`). Sans `sync_token`
  configuré, seul un utilisateur connecté peut lancer la synchronisation ;
- `POST /mcp` et `GET /mcp` (serveur MCP), qui exigent un jeton d'accès ou un JWT ;
- `GET /health` (supervision), qui ne renvoie que l'état de la base et la version du schéma.

## Données sensibles

- Les logs masquent les champs `password` et `token` des requêtes.
- Les messages d'erreur ne renvoient ni hachage ni secret ; l'`uid` permet de retrouver
  le détail dans les logs serveur.
- `conf/prod.ini`, `conf/certs/*.p8`, `logs/` et `woob/` (identifiants bancaires woob)
  sont exclus du dépôt Git.

## Recommandations de déploiement

!!! danger "Secret JWT"
    Générer `jwtsecret` avec `openssl rand -hex 32`. Une valeur faible ou la valeur
    d'exemple permet de forger un jeton pour n'importe quel utilisateur. Changer le
    secret invalide toutes les sessions (app iOS et portail).

- Servir l'API et le portail **uniquement en HTTPS** (JWT et mots de passe transitent
  dans les requêtes ; la copie du token dans le portail exige HTTPS).
- Ne pas exposer `conf/`, `logs/`, `sql/`, `tests/`, `tools/` ni `woob/` par le serveur
  web.
- Générer un `sync_token` long et le passer en en-tête plutôt qu'en query string (les
  URL peuvent être journalisées).
- Restreindre l'utilisateur MySQL à la base `carbure`.
- Surveiller `logs/carbure_AAAAMMJJ.log` (erreurs de synchronisation, accès refusés).

## Sécurité de l'image Docker

Chaque build de la CI analyse l'image :

- **hadolint** vérifie les bonnes pratiques du `Dockerfile` ;
- **Trivy** recherche les vulnérabilités connues des paquets Debian, des dépendances PHP
  et Python (woob, `curl_cffi`) et les secrets restés dans l'image. Une vulnérabilité
  **critique pour laquelle un correctif existe** fait échouer le build (et donc la
  release) ; le rapport complet (critique, haute, moyenne) est publié dans l'onglet
  **Security** du dépôt.

Une vulnérabilité sans impact sur Carbure peut être acceptée dans `.trivyignore`, avec sa
justification. Les images publiées embarquent leur SBOM et leur provenance de build
(`docker buildx imagetools inspect ghcr.io/ltoinel/carbure:latest --format '{{ json .SBOM }}'`).
Pour analyser une image localement :

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy image ghcr.io/ltoinel/carbure:latest
```

## Signaler une vulnérabilité

Merci de ne pas ouvrir d'issue publique : contacter le mainteneur via GitHub
(<https://github.com/ltoinel>).
