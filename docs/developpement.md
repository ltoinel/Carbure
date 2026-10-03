# Développement

## Organisation du dépôt

| Chemin | Contenu |
|---|---|
| `src/lib/` | Socle technique (configuration, base, logs, JWT, routage, woob, APNs, assistant d'installation, migrations, réglages) |
| `src/resources/` | Ressources de l'API |
| `portal/` | Portail web Vue 3 |
| `swagger/` | Générateur OpenAPI et Swagger UI |
| `sql/` | Schéma et migrations |
| `tests/unit/`, `tests/integration/` | Tests PHPUnit |
| `tools/migrate.php` | Migrations du schéma (`--status`, `--dry-run`, `--baseline`) |
| `tools/phpunit.phar`, `tools/coverage-check.php` | PHPUnit 11 et contrôle du seuil de couverture |
| `docker/` | `Dockerfile`, `docker-compose.yml` (avec MariaDB), configuration nginx et PHP-FPM, `entrypoint.sh` |
| `data/` (non versionné) | Données de l'instance : `conf/` (`prod.ini`, `certs/`), `logs/`, `cache/`, `woob/` (banques et identifiants) |
| `VERSION` | Version de Carbure (`dev` dans le dépôt, écrite par le build de release) |
| `docs/`, `mkdocs.yml` | Cette documentation |

Règles détaillées pour les contributeurs et agents de code : `AGENTS.md`.
Tâches ouvertes : `TODO.md`.

## Ajouter une route

```php
final class Transaction {

    /**
     * Search transactions by label.
     *
     * @param string $query The text to find in the label
     * @param int    $limit The maximum number of transactions
     * @return array The list of transactions
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search($query, $limit = 100)
    {
        // ...
    }
}
```

- La méthode doit être **publique et statique**, dans une classe de `src/resources/`
  (nom de fichier = nom de classe).
- Les clés de la requête sont passées **par nom** : le nom des paramètres fait partie
  du contrat d'API.
- `public: true` désactive le contrôle du JWT ; `stream: true` produit une réponse SSE ;
  `raw: true` renvoie tel quel le texte retourné par la méthode (serveur MCP). Un test
  unitaire vérifie la liste des routes publiques.
- Route d'administration : appeler `User::requireAdmin()` en premier (`403` sinon), et
  ajouter l'onglet ou l'action du portail à la liste réservée aux administrateurs.
- Erreur client : `throw new Error("message", 400)` ; erreur serveur : `Exception`.
- Utilisateur courant : `Jwt::getUserIdFromToken()`.

!!! warning "Compatibilité"
    L'API est utilisée par l'app iOS, mise à jour indépendamment du serveur. N'ajouter
    que des routes ou des paramètres facultatifs ; ne jamais renommer ni supprimer.

## Tests

```bash
# Unitaires : sans dépendance externe
php tools/phpunit.phar --testsuite unit

# Unitaires + intégration : MariaDB/MySQL requis
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root php tools/phpunit.phar
```

- `APP_ENV=testing` (défini dans `phpunit.xml`) charge `conf/testing.ini`.
- Les tests d'intégration recréent la base `carbure_test` depuis `sql/carbure.sql`
  (`tests/bootstrap.php`).
- Objectif : **≥ 90 % de couverture** du code de `src/`, mesurée en CI sur l'ensemble
  des suites.

## Lint

```bash
find src tests swagger -name '*.php' -print0 | xargs -0 -n1 php -l
for f in $(find portal -name '*.js'); do cp "$f" /tmp/check.mjs && node --check /tmp/check.mjs; done
```

Les fichiers du portail sont des modules ES : `node --check` doit les lire en `.mjs`.

## Hooks Git

```bash
git config core.hooksPath .githooks
```

| Hook | Vérification |
|---|---|
| `pre-commit` | `php -l` et `node --check` sur les fichiers indexés |
| `pre-push` | Tests unitaires |

## Intégration et livraison continues

| Workflow | Déclencheur | Étapes |
|---|---|---|
| CI | Pull request, push sur `main` | Lint PHP/JS/CSS, tests unitaires et d'intégration (MariaDB), couverture ≥ 90 %, scan de secrets (gitleaks) ; image Docker : hadolint, build, scan Trivy, démarrage avec `docker compose`, installation par l'assistant et test de fumée |
| Release | Release publiée sur GitHub (tag `1.2.0` ou `v1.2.0`), ou lancement manuel avec le tag | CI, archive de l'application (`src`, `portal`, `swagger`, `sql`, `tools/migrate.php`, `VERSION` écrit depuis le tag) jointe à la release ; image Docker `amd64`/`arm64` publiée sur Docker Hub (`ltoinel/carbure`) (version passée par `CARBURE_VERSION`), avec SBOM et provenance |
| Docs | Push sur `main` | Construction MkDocs et déploiement GitHub Pages |

Publier une version : sur GitHub, **Releases → Draft a new release**, créer le tag (par
exemple `1.2.0`) sur `main` et publier ; ou en ligne de commande :

```bash
gh release create 1.2.0 --target main --generate-notes
```

Le workflow joint les archives à la release et publie l'image Docker Hub `ltoinel/carbure`
(`1.2.0`, `1.2` et `latest`, sauf pour une pré-release). **Actions → Release → Run
workflow** reconstruit une release existante (champ `tag`).

La publication sur Docker Hub (`ltoinel/carbure`) utilise les secrets du dépôt
`DOCKERHUB_USERNAME` et `DOCKERHUB_TOKEN` (jeton d'accès Docker Hub en lecture/écriture, dans
**Settings → Secrets and variables → Actions**).

## Migrations de base

Le schéma est versionné : la table `schema_migrations` liste les migrations appliquées, la
version du schéma est la dernière. `tools/migrate.php` applique celles qui manquent, dans
l'ordre de leur nom ; le conteneur Docker le lance à chaque démarrage, l'assistant
d'installation migre une base existante, et un administrateur peut les appliquer depuis le
bandeau du portail (`POST /api/system/migrate`).

1. Créer `sql/migrations/AAAA-MM-JJ_description.sql`.
2. Y déclarer **obligatoirement** une ligne `-- applied-if: <requête>` qui renvoie un nombre
   non nul quand la migration est déjà en place (par exemple une colonne présente dans
   `information_schema.COLUMNS`) : une base créée depuis `sql/carbure.sql`, ou migrée à la
   main, est alors reconnue au lieu d'échouer. Un test le vérifie.
3. Reporter la modification dans `sql/carbure.sql` (installations neuves et tests) et
   ajouter la version de la migration à la liste `schema_migrations` en fin de fichier.
4. Tant que la migration peut ne pas être appliquée (instance hors Docker pas encore mise à
   jour), le code qui en dépend doit rester tolérant (par exemple en
   interceptant l'erreur de la requête), ou n'être déployé qu'après `php tools/migrate.php` ; le noter dans
   `TODO.md`.

Quand toutes les bases connues ont passé un ensemble de migrations, celles-ci peuvent être
retirées : `sql/carbure.sql` les contient déjà (c'est ce qui a été fait pour la version 1.0,
voir `sql/migrations/README.md`).

## Documentation

```bash
pip install -r docs/requirements.txt
mkdocs serve      # http://127.0.0.1:8000
```

## Conventions

- PHP : PHPDoc sur chaque méthode, requêtes SQL préparées uniquement.
- Portail : textes via `t('clé')`, clés ajoutées en `fr` **et** en `en` dans `i18n.js`.
- Fins de ligne LF (`.gitattributes`), messages de commit en anglais.
