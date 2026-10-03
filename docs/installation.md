# Installation

## Prérequis

- PHP **8.2** ou plus avec `mysqli`, `curl`, `openssl` et `json` ; APCu recommandé (cache
  des routes).
- MariaDB ≥ 10.3 ou MySQL 8.
- Un serveur web avec PHP-FPM (nginx, Apache…).
- [woob](https://woob.tech/) installé localement ou via Docker, configuré avec les
  backends de vos banques (`woob bank list` doit fonctionner pour l'utilisateur du
  serveur web).
- Facultatif : une clé APNs `.p8` (Apple Developer) pour les notifications iOS.

## 1. Récupérer le code

```bash
git clone https://github.com/ltoinel/Carbure.git /var/www/carbure
```

## 2. Configurer

```bash
cd /var/www/carbure
cp conf/prod.sample.ini conf/prod.ini
openssl rand -hex 32   # → jwtsecret
openssl rand -hex 32   # → sync_token
```

Renseigner au minimum la base de données, `jwtsecret` et `woob_path`. Toutes les clés
sont décrites dans [Configuration](configuration.md).

!!! danger "Ne jamais garder `jwtsecret=secret`"
    La valeur d'exemple permet à n'importe qui de fabriquer un jeton valide.

## 3. Créer la base

```bash
mysql -u root -p -e "CREATE DATABASE carbure CHARACTER SET utf8mb4"
mysql -u root -p -e "CREATE USER 'carbure'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON carbure.* TO 'carbure'@'localhost'"
mysql -u root -p carbure < sql/carbure.sql
```

`sql/carbure.sql` contient le schéma à jour. Il faut ensuite créer les catégories
(`bank_transaction_category`, dont la catégorie `0` « sans catégorie »), les mots-clés
de catégorisation et les comptes bancaires (`bank_account`) à synchroniser.

### Mettre à jour une base existante

Appliquer, dans l'ordre de leur nom, les fichiers de `sql/migrations/` qui ne l'ont pas
encore été :

```bash
mysql -u root -p carbure < sql/migrations/2026-10-03_audit.sql
mysql -u root -p carbure < sql/migrations/2026-10-04_alerts.sql
```

!!! warning "Migrations avant le code"
    Appliquer les migrations **avant** de déployer la nouvelle version du code : l'API
    lit les nouvelles colonnes (`is_admin`, `language`, `alert_threshold`) dès
    `GET /user/me`.

## 4. Configurer le serveur web

- Les URL `/api/…` **sans point** dans le chemin sont envoyées à `src/api.php` (le
  préfixe `/api` est retiré par l'API).
- `portal/` est servi en fichiers statiques (par exemple sous `/portal/`).
- `swagger/` peut être servi pour consulter la spécification OpenAPI.
- `conf/`, `logs/`, `sql/`, `tests/`, `tools/` et `woob/` ne doivent **pas** être exposés.
- Utiliser HTTPS.

Exemple nginx :

```nginx
location /api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/carbure/src/api.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_read_timeout 3600;   # la synchronisation peut être longue
    fastcgi_buffering off;       # flux SSE
}
location /portal/ { root /var/www/carbure; }
location ~ ^/(conf|logs|sql|tests|tools|woob)/ { deny all; }
```

## 5. Créer le premier utilisateur

Les utilisateurs sont gérés par un administrateur via l'API ou le portail. Pour le
premier compte, insérer un utilisateur administrateur directement en base avec un
mot de passe haché par `password_hash()` :

```bash
php -r 'echo password_hash("mon-mot-de-passe", PASSWORD_DEFAULT), PHP_EOL;'
```

```sql
INSERT INTO users (username, password, email, is_admin, language)
VALUES ('admin', '<hash>', 'admin@example.org', 1, 'fr');
```

## 6. Planifier la synchronisation

```bash
# crontab : tous les jours à 7h
0 7 * * * curl -sN -H "X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync > /dev/null
```

Après un déploiement qui modifie les routes, le cache APCu est invalidé automatiquement
(signature des fichiers de `src/resources/`).
