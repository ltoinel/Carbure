# AGENTS.md

Instructions pour les agents de code (et les contributeurs) travaillant sur Carbure.
Un éventuel `CLAUDE.md` local (non versionné) peut compléter ces règles pour Claude Code.

## Le projet

Carbure est un portail web de suivi du budget et des transactions bancaires du foyer :
un **portail** `portal/` (Vue 3, sans build) et une **API REST PHP 8.2+** `src/` (sans
framework, sans Composer), aussi consommée par **l'app iOS**. Les transactions sont
synchronisées par [woob](https://woob.tech/), catégorisées par mots-clés, et des
notifications APNs sont envoyées à l'application iOS. Un serveur MCP en lecture seule
(`/api/mcp`) ouvre les données aux agents IA. Déploiement recommandé : image Docker
(nginx + PHP-FPM + woob), installée depuis le navigateur.

## Organisation

| Chemin | Contenu |
|---|---|
| `src/api.php` | Point d'entrée HTTP (`/api/*`) ; sans `conf/<env>.ini`, ne sert que l'assistant d'installation (`/api/setup`) |
| `src/autoload.php` | Chargement des classes et de la configuration (`APP_ENV`) |
| `src/lib/` | Socle : `Config`, `Db` (mysqli), `Logger`, `Jwt`, `Webservice` (dont les en-têtes de sécurité), `ApiResolver`, `ApiRoute`, `Woob`, `Apns`, `Setup` et `Installer` (assistant d'installation), `Migrator` (versions du schéma), `Setting` (réglages en base) |
| `src/resources/` | Ressources de l'API : `User`, `Bank`, `Transaction`, `Budget`, `Category`, `Insight`, `Device`, `ApiToken`, `Mcp`, `OAuth`, `System` |
| `portal/` | Portail web (Vue 3 global build, mixins dans `modules/`, i18n fr/en dans `i18n.js`) |
| `swagger/` | Génération OpenAPI par réflexion + Swagger UI |
| `sql/carbure.sql` | Schéma complet (installation neuve) |
| `sql/migrations/` | Migrations datées pour les bases existantes (appliquées par `Migrator`, table `schema_migrations`) |
| `tools/migrate.php` | Migrations du schéma en ligne de commande (lancé par l'image Docker à chaque démarrage) ; tout le reste se fait dans le portail |
| `docker/` | `Dockerfile` (nginx + PHP-FPM + woob, `HEALTHCHECK` sur `/api/health`), `docker-compose.yml`, `docker-compose.dev.yml` (surcharge de développement, lancée par `start.sh`) |
| `start.sh` | Démarre l'environnement de développement (image de production, code monté, données d'exemple) |
| `data/` | Données de l'instance, non versionnées : `conf/prod.ini`, `conf/certs/`, `logs/`, `cache/`, `woob/` |
| `conf/*.sample.ini`, `conf/testing.ini`, `conf/e2e.ini` | Configuration d'exemple et de test (`prod.ini` n'est jamais versionné) |
| `tests/unit/`, `tests/integration/` | Tests PHPUnit (unitaires sans dépendance ; intégration avec MariaDB) |
| `tests/portal/` | Tests unitaires du portail (`node:test`, sans dépendance) |
| `tests/e2e/` | Tests de bout en bout du portail (Playwright, vraie API, base recréée à chaque test) ; `server/seed.php` est aussi le jeu de données de `start.sh` |
| `tools/phpunit.phar` | PHPUnit 11 |
| `VERSION` | Version (`dev` dans le dépôt, écrite par la release) |
| `docs/`, `mkdocs.yml` | Documentation GitHub Pages (MkDocs Material) |
| `TODO.md` | Tâches restantes |

## Lancer le projet

```bash
./start.sh            # http://localhost:8000/portal/ (admin / admin-password, marie / marie-password)
```

Image Docker de production et MariaDB 11, code monté en direct, données d'exemple ;
`--reset`, `--build`, `--stop`, `--clean` (détails : docs/developpement.md).

## Lancer le lint et les tests

```bash
find src tests swagger -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l
for f in $(find portal -name '*.js' -not -path '*/vendor/*'); do cp "$f" /tmp/check.mjs && node --check /tmp/check.mjs; done

php tools/phpunit.phar --testsuite unit
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root php tools/phpunit.phar   # unit + intégration
node --test 'tests/portal/*.test.mjs'                                    # portail, unitaires

cd tests/e2e && npm ci && npx playwright install chromium                # e2e, une fois
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root npx playwright test           # e2e du portail
```

Les tests d'intégration recréent la base `carbure_test` à partir de `sql/carbure.sql` ;
les tests e2e recréent `carbure_e2e` avant chaque test (PHP avec `mysqli` et MariaDB
requis, voir docs/developpement.md). Hooks Git : `git config core.hooksPath .githooks`
(lint en pre-commit, tests unitaires PHP et du portail en pre-push).

## Conventions de code

- Une route = une méthode **publique statique** d'une classe de `src/resources/`, annotée
  `#[ApiRoute('/chemin', method: 'GET', public: false, stream: false, raw: false)]`.
- Action d'administration : `User::requireAdmin()` en premier ; dans le portail, onglet
  ou bouton visible seulement si `isAdmin`.
- Les paramètres de requête (JSON + query string) sont injectés **par nom** dans les
  paramètres de la méthode : le nom d'un paramètre fait partie du contrat d'API.
- Erreurs : `Error` avec un code HTTP 4xx pour les erreurs client, `Exception` pour les
  erreurs serveur.
- SQL uniquement en requêtes préparées (`Db::execute`, `Db::queryOne`).
- PHPDoc sur chaque méthode ; style du code existant (accolades, commentaires courts).
- Portail : chaque texte passe par `t('clé')` ; ajouter la clé en `fr` **et** en `en`.
- Fins de ligne LF ; messages de commit en anglais.

## Règles de sécurité et de compatibilité

- **Dossier de production** : sur l'installation de l'auteur, le dépôt de travail est le
  dossier servi en production. Toute modification y est en ligne immédiatement ; ne pas
  changer de branche ni réécrire l'arbre de travail dans ce dossier.
- **Rétrocompatibilité** : l'app iOS n'est pas mise à jour en même temps que le serveur.
  N'ajouter que des routes ou des paramètres facultatifs ; ne jamais renommer, supprimer
  ou changer le format d'une réponse existante.
- **Schéma** : toute modification passe par un fichier `sql/migrations/AAAA-MM-JJ_nom.sql`
  (avec sa ligne `-- applied-if: …` obligatoire, vérifiée par un test, voir
  docs/developpement.md) **et** par `sql/carbure.sql`. Les migrations sont appliquées au
  démarrage du conteneur Docker, par l'assistant d'installation ou depuis le portail
  (bandeau administrateur) ; `tools/migrate.php` en ligne de commande. Le code ne doit pas
  dépendre d'une migration non appliquée.
- **Secrets** : jamais de secret, d'identifiant bancaire, d'e-mail personnel ou de log
  dans le dépôt (`data/` est ignoré : configuration, clés, logs, identifiants woob). Les
  fichiers d'exemple ne contiennent que des valeurs factices.
- **UUID des transactions** : le libellé nettoyé entre dans l'UUID ; modifier
  `Transaction::cleanLabel()` ou les `regex_label` crée des doublons sans migration.
- Les routes publiques sont limitées à `POST /user/login`, `GET /bank/sync` (protégée
  par JWT ou `sync_token`), `POST /mcp` et `GET /mcp` (jeton d'accès ou JWT),
  `GET /health` et les routes OAuth du serveur MCP (`/.well-known/oauth-*`,
  `POST /oauth/register`, `GET /oauth/authorize`, `POST /oauth/token`) ; un test unitaire
  échoue si une nouvelle route publique apparaît.
- Les en-têtes de sécurité de l'API sont posés par PHP ; ceux du portail par nginx
  (`docker/nginx.conf`, pas de `.htaccess`).

## Définition de « terminé »

- [ ] Lint PHP et JS sans erreur.
- [ ] Tests unitaires verts (et tests ajoutés pour le code nouveau).
- [ ] Fonctionnalité du portail ajoutée ou modifiée : test Playwright dans
      `tests/e2e/specs/`, suite e2e verte.
- [ ] Couverture PHP ≥ 90 % en CI (unit + intégration).
- [ ] Migration SQL fournie si le schéma change, et signalée comme action de déploiement.
- [ ] `TODO.md` mis à jour (tâches cochées / ajoutées).
- [ ] Documentation mise à jour (`README.md`, `docs/`) si le comportement ou l'API change.
