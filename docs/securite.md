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

## Routes publiques

Seules deux routes ne demandent pas de JWT (un test unitaire le vérifie) :

- `POST /user/login` ;
- `GET /bank/sync`, qui exige **soit** un JWT, **soit** le `sync_token` de la
  configuration (en-tête `X-Sync-Token` de préférence, ou `?token=`). Sans `sync_token`
  configuré, seul un utilisateur connecté peut lancer la synchronisation.

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
