---
title: "Changelog : les versions de Carbure"
description: "Toutes les versions de Carbure, de la 1.0.0 à aujourd'hui : nouveautés, corrections et ce qu'il faut faire en mettant à jour."
icon: material/history
---

# Changelog

Les versions de Carbure, de la plus récente à la plus ancienne. Le détail de chaque
version (toutes les pull requests) est dans ses
[notes de version sur GitHub](https://github.com/ltoinel/Carbure/releases).

Avec Docker, la mise à jour se fait par `docker compose pull && docker compose up -d` (voir
[Mettre à jour](installation.md#mettre-a-jour)) : les migrations de la base sont appliquées
au démarrage du conteneur, et le code fonctionne avant elles. Sur un serveur web existant,
appliquez-les depuis le bandeau du portail ou avec `php tools/migrate.php`. Sur YunoHost,
la mise à jour du paquet s'en charge (voir [YunoHost](yunohost.md#mettre-a-jour-carbure)).
Sauvegardez la base avant chaque mise à jour.

## 1.11.0 — 27 octobre 2026

**Nouveautés**

- **Portail sur téléphone** : les onglets du quotidien passent dans une barre en bas de
  l'écran, les autres, le profil, le thème et la déconnexion dans son menu **Plus** ;
  l'en-tête tient sur une ligne.
- **Calendrier** des transactions récurrentes du mois dans l'onglet Transactions.
- **Règles** : le réglage **Conserver les catégories existantes** (désactivé par défaut)
  limite les règles aux transactions non catégorisées.
- **Données à jour sans recharger** : chaque réponse de l'API porte les versions des
  données (en-tête `X-Carbure-Changes`) ; le portail recharge l'onglet ouvert quand un
  autre membre du foyer ou une synchronisation a changé ce qu'il affiche.
- **Messages d'erreur traduits** : le portail affiche les erreurs de l'API dans la langue
  de l'utilisateur. L'API ajoute un code stable (`error_code`, `params`) à ses erreurs,
  sans changer les champs existants (voir [API](api.md#code-stable-dune-erreur)).
- **Appareils** : une application mobile connectée sans jeton de notification apparaît
  dans **Mon profil → Mes appareils** ; son jeton s'enregistre dès qu'elle en reçoit un.
- **Port publié** : `CARBURE_PORT` (dans `docker/.env`) choisit le port de
  `docker/docker-compose.yml`, `8080` par défaut.
- Le [paquet YunoHost](yunohost.md) est disponible.

**Corrections**

- **Banques** : les banques qui demandent une validation dès la première connexion
  (Crédit Mutuel…) se connectent depuis le portail ; les modules qui demandaient des
  paquets Python absents (Banque Populaire, Caisse d'Épargne, Société Générale…) se
  chargent ; le choix de la région de Banque Populaire est accepté. À la première banque,
  un avertissement rappelle que la synchronisation dépend des connecteurs de woob.
- Les messages s'affichent au-dessus des fenêtres ; un interrupteur refusé par l'API
  revient à sa position.

**Sécurité** : nginx accepte 30 tentatives de connexion par minute et par client
(`/api/user/login`, `/api/user/login/mfa`, `/api/oauth/token`), au-delà il répond `429`.

**À la mise à jour**

- Deux migrations : `2026-10-28_device_without_token` et `2026-10-29_data_change`.
- **Derrière un proxy inverse**, l'adresse du client est lue dans `X-Forwarded-For` quand
  le proxy a une adresse privée (par défaut) : si votre proxy a une autre adresse,
  renseignez `TRUSTED_PROXIES`, sinon tout le foyer partage la même limite de connexions.
  Après la mise à jour, vérifiez que les logs montrent bien les adresses des clients. Voir
  [Limitation des connexions](securite.md#limitation-des-connexions).

## 1.10.0 — 23 octobre 2026

**Nouveautés**

- **Alertes de budget** avec un **seuil global** (tous les budgets), désactivable, et des
  **seuils par catégorie** (**Mon profil → Notifications**).
- **Salaire de fin de mois** : un virement reçu daté **du 25 ou après** est rattaché au
  mois suivant (auparavant à partir du 26). Le jour se règle avec `next_month_day`
  (**Administration → Paramètres**, `0` pour ne jamais déplacer un virement).
- **Nouvelle version** : un bandeau prévient les administrateurs quand une version de
  Carbure est publiée. Le serveur demande la dernière version à l'API publique de GitHub
  une fois par jour, sans rien envoyer sur l'instance ; `update_check=false` le désactive.
- **Installation scriptée** : `tools/install.php` installe Carbure sans l'assistant web
  (voir [Lancer l'assistant](installation.md#3-lancer-lassistant)) ; il sert au
  [paquet YunoHost](yunohost.md).
- Les applications mobiles sont publiées : liens App Store et Google Play dans **Mon
  profil → Mes appareils**.

**À la mise à jour**

- Migration `2026-10-27_budget_alert_threshold` : les réglages des alertes sont repris ;
  un utilisateur qui surveillait un seul budget garde ce budget, à son seuil, sans seuil
  global.
- Le nouveau jour du salaire ne concerne que les transactions importées ou synchronisées
  après la mise à jour.
- **Première installation avec Docker Compose** : `docker compose pull && docker compose up -d`
  utilise l'image publiée (sans `pull`, Compose construisait l'image depuis le clone).

## 1.9.1 — 21 octobre 2026

**Sécurité** : l'image exige PHP 8.5.12, qui corrige la faille PHP2Shell. Mettez à jour
sans attendre ; aucune migration.

## 1.9.0 — 20 octobre 2026

**Nouveautés**

- **Devise** : nouveau réglage facultatif `currency` (code ISO 4217, `EUR` par défaut)
  dans **Administration → Paramètres**, pour afficher les montants dans une autre devise
  (aucune conversion).

**Corrections**

- **Synchronisation** : chaque compte commence par la liste des comptes de sa banque
  (`woob bank list`) avant ses opérations ; certains modules (Crédit Mutuel) ne trouvaient
  pas le compte sans elle. Un compte que la banque ne liste plus finit en erreur, avec un
  message explicite, au lieu de « Account not found ».

**À la mise à jour** : aucune migration.

## 1.8.0 — 18 octobre 2026

**Nouveautés**

- **Administration → Push mobile** : l'inscription de l'instance à Carbure Push, sa clé et
  l'état du service.
- **Mes appareils** liste aussi les navigateurs web connectés au portail.
- Un **QR code** dans **Mes appareils** ouvre les applications mobiles sur ce serveur.

**Corrections** : un réglage facultatif de woob laissé vide n'est plus écrit.

**À la mise à jour**

- Migration `2026-10-26_user_browser` (sans elle, aucun navigateur n'est listé).
- Le réglage `push_url` disparaît : le service de notifications est toujours Carbure Push
  (`https://api.carbure.org`), une valeur restée dans `/data/conf/prod.ini` est ignorée.
- Le QR code donne aux applications mobiles l'adresse de l'API : renseignez `public_url`
  (**Administration → Paramètres**) si le portail est aussi ouvert par une adresse locale.

## 1.7.0 — 18 octobre 2026

**Nouveautés** : l'[image de démonstration](installation.md#essayer-carbure-demo) crée un
compte `demo` / `carbure` et un mot de passe administrateur aléatoire, et tourne en lecture
seule, sans privilèges (`docker/demo`).

**Sécurité**

- Les requêtes des insights ne lisent que les tables du budget, avec une durée limitée.
- Les identifiants bancaires ne passent plus sur la ligne de commande de woob.

**À la mise à jour** : aucune migration.

## 1.6.0 — 17 octobre 2026

**Nouveautés**

- **Image de démonstration** avec MariaDB et des données d'exemple.
- **Double authentification** dans son propre onglet du profil, avec la liste des
  navigateurs de confiance.
- **Carbure Push** : `https://api.carbure.org` par défaut, et un consentement demandé
  avant l'inscription.
- Les catégories passent dans **Administration**.
- L'onglet **API** signale quand un proxy inverse bloque Swagger UI.

**Sécurité** : les données personnelles ne sont plus écrites dans les logs du serveur.

**À la mise à jour** : migration `2026-10-25_device_model` (modèle, système et version de
l'application de chaque appareil mobile, inconnus jusqu'à sa prochaine connexion). Elle
corrige aussi la plateforme des appareils Android déjà enregistrés, notés iOS jusque-là :
leurs notifications push repartent.

## 1.5.0 — 17 octobre 2026

**Nouveautés**

- **Authentification à deux facteurs** (TOTP), qu'un administrateur peut imposer.
- **Sauvegarde et restauration** des données du foyer dans **Administration →
  Sauvegarde** (un ZIP ; l'import **remplace** les données de chaque type importé).
- **Langues** : espagnol, italien, allemand et portugais.
- **Notifications push** par le service Carbure Push, pour iOS et Android.
- Profil en onglets, avec le choix du budget surveillé par les alertes.
- Documentation de l'API (Swagger UI) dans **Administration → API**.
- Un insight de départ « matelas de sécurité » et le [recueil de recettes
  d'insights](insights.md).

**À la mise à jour**, **dans cet ordre** :

1. **Sauvegardez la base**.
2. **Notifications push** : la 1.5.0 n'envoie plus elle-même les notifications à Apple
   (APNs) mais passe par Carbure Push. Tant que l'instance n'y est pas inscrite,
   **aucune notification push n'est envoyée** (le reste fonctionne normalement). En 1.5.0,
   l'adresse par défaut du service (`https://push.carbure.org`) n'est pas la bonne :
   renseignez `push_url` = `https://api.carbure.org` (adresse par défaut à partir de la
   1.6.0, réglage supprimé en 1.8.0). Inscrivez l'instance juste après la mise à jour
   (voir [Notifications push](installation.md#notifications-push-carbure-push)), puis
   retirez la section `[apns]` et la clé `.p8` de `/data/conf`.
3. **Mettez à jour** l'image. Trois migrations : `2026-10-23_device_platform` (`ios` pour
   les appareils existants), `2026-10-23_user_budget_alert_category` (tous les budgets
   surveillés pour les utilisateurs existants) et `2026-10-24_user_mfa` (tables vides).
4. **Vérifiez** que **Administration → API** affiche Swagger UI (sinon, le proxy inverse
   ajoute un en-tête `X-Frame-Options: DENY` à retirer pour `/swagger/`), et que le seuil
   des dépenses importantes est dans **Mon profil → Notifications**.

Avant d'imposer la double authentification (`mfa_required`), configurez-la sur votre
propre compte et gardez vos codes de récupération ; en cas de perte :
`mfa_required=false` dans `/data/conf/prod.ini`. Sur une instance qui n'est pas en
français, renseignez la catégorie d'épargne des **Tendances** (`savings_category`).
L'exemple de configuration ne contient plus la règle de nettoyage
`regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""` : gardez-la dans votre `prod.ini`
si elle y est (la retirer créerait des doublons de transactions par carte).

## 1.4.0 — 16 octobre 2026

**Nouveautés**

- **Import des relevés** des principales banques françaises et néobanques, un profil CSV
  par banque.
- **Notifications par email**, le canal choisi par chaque utilisateur, et **alertes de
  budget** quand les dépenses atteignent un seuil.
- **Thème** clair ou sombre, préférences d'affichage du système (contraste élevé, couleurs
  forcées) ; le portail s'installe comme une application.
- **Modules bancaires** mis à jour depuis la page **Comptes**, le dépôt de woob approuvé par
  un administrateur.
- Recherche des transactions au libellé proche, suppression d'une transaction, comptes
  suivis affichés sous leur banque.

**Sécurité** : aucune ressource externe (CDN) dans le portail ; le jeton de
synchronisation dans l'URL devient obsolète (en-tête `X-Sync-Token`).

**À la mise à jour** : cinq migrations (`2026-10-18_transaction_deleted`,
`2026-10-19_user_theme`, `2026-10-20_user_notification`,
`2026-10-21_user_budget_threshold`, `2026-10-22_budget_alert`). Une tâche planifiée qui
passe le jeton dans l'URL (`?token=…`) fonctionne encore : passez-le dans l'en-tête
`X-Sync-Token`.

## 1.3.0 — 13 octobre 2026

**Nouveautés** : `woob config update` à chaque démarrage du conteneur ; un nom lisible pour
chaque banque connectée ; suppression d'une banque depuis la page **Comptes** ; l'historique
gardé quand une synchronisation échoue, et une synchronisation planifiée relancée après une
validation sur le téléphone.

**À la mise à jour** : migration `2026-10-17_bank_backend_label`.

## 1.2.0 — 13 octobre 2026

**Nouveautés** : plusieurs identifiants d'une même banque ; des noms pour les comptes
suivis ; ajout des comptes en deux étapes, depuis ceux que woob liste ; synchronisation de
tous les comptes ; copie des budgets du mois précédent ; logs de débogage de woob dans le
journal de synchronisation.

**Sécurité** : la signature des modules woob est vérifiée (`gpgv`).

**À la mise à jour** : migration `2026-10-16_bank_account_label`.

## 1.1.0 — 11 octobre 2026

**Nouveautés** : validation de l'authentification forte d'une banque depuis le portail ;
import de relevé proposé sur un mois vide ; politique de sécurité.

**À la mise à jour** : `docker-compose.yml` peut passer MariaDB à une nouvelle version
majeure (voir [Mettre à jour](installation.md#mettre-a-jour)).

## 1.0.5 — 9 octobre 2026

**Nouveautés**

- **Transactions récurrentes** : un badge « Mensuelle » sur les transactions de chaque
  mois (salaire, loyer, abonnements, factures), et la liste de celles qui ne sont pas
  encore arrivées ce mois-ci.
- **Transactions identiques conservées** : deux vraies transactions identiques le même
  jour ne sont plus fusionnées.
- **Connexion simplifiée** : le portail trouve l'API tout seul.

**À la mise à jour** : `docker-compose.yml` passe à **MariaDB 13** (au lieu de 11).
Sauvegardez la base avant ; pour rester en MariaDB 11, gardez `image: mariadb:11`.

## 1.0.4 — 6 octobre 2026

**Nouveautés**

- **Démarrage rapide** : l'assistant d'installation propose environ 30 catégories,
  100 règles et 13 insights de départ.
- La **banque d'origine** dans le détail d'une transaction.
- Le mois et l'année dans l'en-tête, limités aux mois qui ont des transactions.
- **Logs** au format JSON Lines, avec l'adresse IP du client, et leur durée de
  conservation (`log_retention_days`).

**À la mise à jour** : migration `2026-10-15_transaction_bank`. Les logs existants sont
gardés indéfiniment tant que `log_retention_days` n'est pas renseigné ; un outil qui lit
`data/logs/carbure_*.log` doit lire le nouveau format.

## 1.0.3 — 5 octobre 2026

**Nouveautés** : **import de relevés** OFX/QFX, QIF, CSV et CAMT.053 avec aperçu des
doublons ; un flux du budget plus riche ; onglets **Paramètres** et **Synchronisation**
dans l'administration ; recherche dans le choix de la catégorie.

**À la mise à jour** : aucune migration. Hors Docker, autorisez des requêtes de 2 Mo
(`client_max_body_size 2m;`) pour l'import.

## 1.0.2 — 4 octobre 2026

**Nouveautés** : connexion de Claude au serveur MCP par OAuth 2.1, sans jeton ; budgets
par sous-catégorie ; menu **Administration** ; environnement de développement `./start.sh`.

**À la mise à jour** : migration `2026-10-14_oauth`. Derrière votre propre nginx, routez
`/.well-known/oauth-*` vers `src/api.php` comme `docker/nginx.conf`.

## 1.0.1 — 3 octobre 2026

Image Docker sur Alpine, beaucoup plus légère ; icônes des transactions aux couleurs de
leur catégorie ; correction de woob sur NAS (« config file … is readable by group or other
users »).

## 1.0.0 — 3 octobre 2026

Première version publique : transactions, budget et flux du mois, insights et tendances,
règles de catégorisation, synchronisation bancaire par woob depuis le portail, serveur MCP
pour les agents IA, profils administrateur et utilisateur, image Docker `amd64`/`arm64`
avec migrations automatiques.
