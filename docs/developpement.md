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
| `tools/carbure.php` | Maintenance : `add-bank` (configurer une banque dans woob et suivre ses comptes), `rotate-jwt-secret` |
| `tools/migrate.php` | Migrations du schéma (`--status`, `--dry-run`, `--baseline`) |
| `tools/phpunit.phar`, `tools/coverage-check.php` | PHPUnit 11 et contrôle du seuil de couverture |
| `Dockerfile`, `docker/`, `docker-compose.yml` | Image nginx + PHP-FPM + woob, et déploiement avec MariaDB |
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
| Release | Tag `v*` | CI, archive de l'application (`src`, `portal`, `swagger`, `sql`, `tools/carbure.php`, `tools/migrate.php`, `VERSION` écrit depuis le tag) et release GitHub ; image Docker `amd64`/`arm64` publiée sur `ghcr.io` (version passée par `CARBURE_VERSION`), avec SBOM et provenance |
| Docs | Push sur `main` | Construction MkDocs et déploiement GitHub Pages |

Publier une version :

```bash
git tag v1.2.0
git push origin v1.2.0
```

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
3. Reporter la modification dans `sql/carbure.sql` (installations neuves et tests).
4. Tant que la migration peut ne pas être appliquée (instance hors Docker pas encore mise à
   jour), le code qui en dépend doit rester tolérant (voir `Setting::get` ou
   `User::login`), ou n'être déployé qu'après `php tools/migrate.php` ; le noter dans
   `TODO.md`.

## Documentation

```bash
pip install -r docs/requirements.txt
mkdocs serve      # http://127.0.0.1:8000
```

## Conventions

- PHP : PHPDoc sur chaque méthode, requêtes SQL préparées uniquement.
- Portail : textes via `t('clé')`, clés ajoutées en `fr` **et** en `en` dans `i18n.js`.
- Fins de ligne LF (`.gitattributes`), messages de commit en anglais.
