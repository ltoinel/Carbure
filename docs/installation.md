# Installation

Trois façons d'installer Carbure, de la plus simple à la plus manuelle.

## Option 1 : image Docker (recommandée)

L'image contient tout : PHP 8.3, Apache, woob et `curl_cffi`. Avec MariaDB :

```bash
git clone https://github.com/ltoinel/Carbure.git && cd Carbure
cat > .env <<'ENV'
DB_PASSWORD=un-mot-de-passe-solide
ADMIN_PASSWORD=le-mot-de-passe-de-l-admin
ENV
docker compose up -d
```

Le portail est sur `http://<hôte>:8080/`. Au premier démarrage, le conteneur crée le schéma,
le compte administrateur et `/data/conf/prod.ini` avec des secrets aléatoires.

| Variable | Rôle | Défaut |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Base de données | `db`, `3306`, `carbure`, `carbure` |
| `ADMIN_USER`, `ADMIN_PASSWORD`, `ADMIN_EMAIL` | Premier administrateur (premier démarrage) | `admin` |
| `LANGUAGE` | Langue du premier administrateur (`fr`, `en`) | `fr` |
| `SYNC_INTERVAL` | Synchronisation automatique toutes les N secondes (`86400` = une fois par jour) | désactivée |

Le volume `/data` conserve la configuration (`/data/conf/prod.ini`, clé APNs dans
`/data/conf/certs`), les logs et la configuration woob. Pour ajouter une banque :

```bash
docker compose exec -it -u www-data carbure php tools/install.php --add-bank
```

L'assistant demande le module woob de votre banque (`list` pour les afficher), puis woob
vous demande vos identifiants bancaires : ils sont conservés par woob, jamais par Carbure.
Les comptes trouvés sont listés et vous choisissez ceux à suivre ; ils apparaissent ensuite
dans le portail. Lancez une synchronisation depuis l'onglet **Synchro**. Sur un NAS Synology, le même `docker-compose.yml` s'utilise
depuis **Container Manager → Projet**.

### Récupérer l'image

L'image est publiée sur GitHub Container Registry à chaque release, pour `amd64` et `arm64`
(NAS Synology Intel comme ARM) :

```bash
docker pull ghcr.io/ltoinel/carbure:latest
```

| Tag | Contenu |
|---|---|
| `latest` | Dernière release |
| `1.2.0` | Une version précise (recommandé en production, pour maîtriser les mises à jour) |
| `1.2` | Dernière version corrective de la 1.2 |

Sans `docker compose`, avec une base MariaDB/MySQL existante (la base et son utilisateur
doivent déjà exister) :

```bash
docker run -d --name carbure --restart unless-stopped -p 8080:80 \
  -e DB_HOST=192.168.1.10 -e DB_NAME=carbure -e DB_USER=carbure -e DB_PASSWORD='…' \
  -e ADMIN_PASSWORD='…' -e SYNC_INTERVAL=86400 \
  -v carbure-data:/data \
  ghcr.io/ltoinel/carbure:latest
```

Sur un NAS Synology : **Container Manager → Registre → Paramètres → Ajouter**, URL
`https://ghcr.io`, puis recherchez `ltoinel/carbure` et téléchargez le tag voulu. Le plus
simple reste toutefois d'importer `docker-compose.yml` dans **Container Manager → Projet**.

!!! note
    L'image est publiée à partir de la première release (tag `v*`). Avant cela,
    `docker compose up -d` la construit à partir des sources (`build: .`).

### Mettre à jour

```bash
docker compose pull && docker compose up -d
```

Le volume `/data` est conservé. Si la nouvelle version apporte une migration
(`sql/migrations/`), appliquez-la sur la base **avant** de redémarrer le conteneur.

### Notifications iOS (APNs)

Copiez la clé `.p8` dans le volume, puis complétez la section `[apns]` de
`/data/conf/prod.ini` :

```bash
docker compose cp AuthKey_XXXXXXXXXX.p8 carbure:/data/conf/certs/
docker compose exec carbure vi /data/conf/prod.ini   # apns_key_path=conf/certs/AuthKey_XXXXXXXXXX.p8
docker compose restart carbure
```

### Maintenance

```bash
docker compose logs -f carbure                                    # logs Apache et démarrage
docker compose exec carbure ls /data/logs                         # logs de Carbure
docker compose exec carbure php tools/install.php --rotate-jwt-secret   # nouveau jwtsecret
```

!!! note "Modules woob"
    woob télécharge ses modules (dont celui de votre banque) dans `/data/woob` et les met
    à jour automatiquement. Un correctif de module pas encore publié (par exemple pour la
    BNP) peut être copié dans `/data/woob/.local/share/woob/modules/3.7/woob_modules/` ;
    `curl_cffi`, nécessaire au module BNP récent, est déjà installé dans l'image.

## Option 2 : script d'installation

Sur un serveur avec PHP et MariaDB/MySQL :

```bash
git clone https://github.com/ltoinel/Carbure.git /var/www/carbure && cd /var/www/carbure
php tools/install.php
```

Le script vérifie PHP et ses extensions, crée la base et son utilisateur (si vous lui donnez un
compte administrateur MySQL), importe le schéma, crée le premier administrateur et génère
`conf/prod.ini` avec des secrets aléatoires (`jwtsecret`, `password_salt`, `sync_token`). Il
n'écrase jamais un fichier existant sans `--force`.

Il propose enfin de **configurer une banque** : woob demande vos identifiants (conservés par
woob, jamais par Carbure), puis les comptes trouvés sont ajoutés à Carbure. Lancez le script
avec l'utilisateur du serveur web (par exemple `sudo -u www-data php tools/install.php`) :
c'est lui qui exécute woob lors des synchronisations et doit donc retrouver sa configuration.

| Commande | Rôle |
|---|---|
| `php tools/install.php --add-bank` | Ajouter une banque et ses comptes plus tard |
| `php tools/install.php --rotate-jwt-secret` | Générer un nouveau `jwtsecret` (sessions à rouvrir) |
| `php tools/install.php --help` | Toutes les options, dont l'installation non interactive |

Il reste à configurer le serveur web (voir « Serveur web » plus bas) et woob.

## Option 3 : installation manuelle

### Prérequis

- PHP **8.2** ou plus avec `mysqli`, `curl`, `openssl` et `json` ; APCu recommandé (cache
  des routes).
- MariaDB ≥ 10.3 ou MySQL 8.
- Un serveur web avec PHP-FPM (nginx, Apache…).
- [woob](https://woob.tech/) installé localement ou via Docker, configuré avec les
  backends de vos banques (`woob bank list` doit fonctionner pour l'utilisateur du
  serveur web). Le module BNP récent nécessite `curl_cffi` (`pip install "curl_cffi>=0.7"`).
- Facultatif : une clé APNs `.p8` (Apple Developer) pour les notifications iOS.

### 1. Récupérer le code

```bash
git clone https://github.com/ltoinel/Carbure.git /var/www/carbure
```

### 2. Configurer

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

### 3. Créer la base

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
mysql -u root -p carbure < sql/migrations/2026-10-05_schema.sql
```

!!! warning "Migrations avant le code"
    Appliquer les migrations **avant** de déployer la nouvelle version du code : l'API
    lit les nouvelles colonnes (`is_admin`, `language`, `alert_threshold`) dès
    `GET /user/me`.

### 4. Configurer le serveur web

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

### 5. Créer le premier utilisateur

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

### 6. Planifier la synchronisation

```bash
# crontab : tous les jours à 7h
0 7 * * * curl -sN -H "X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync > /dev/null
```

Après un déploiement qui modifie les routes, le cache APCu est invalidé automatiquement
(signature des fichiers de `src/resources/`).
