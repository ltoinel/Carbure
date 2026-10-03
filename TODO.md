# TODO

Tâches restant à réaliser sur Carbure. Une tâche terminée est retirée de cette liste
(l'historique Git garde la trace de ce qui a été fait).

## Production (actions manuelles)

- [ ] **Urgent** : remplacer le `jwtsecret` de production (valeur par défaut `secret`) :
      bouton **Renouveler** du bandeau affiché aux administrateurs dans le portail (le serveur
      web doit pouvoir écrire `conf/prod.ini`), ou à la main avec `openssl rand -hex 32`.
      Les sessions seront à rouvrir.
- [ ] Changer le mot de passe MySQL présent dans l'ancien historique (dépôt `Carbure-Archive`).
- [ ] Ajouter `sync_token` dans `conf/prod.ini` et l'utiliser dans le cron
      (`/api/bank/sync` avec l'en-tête `X-Sync-Token`).
- [ ] Lancer `php tools/migrate.php --status` puis `php tools/migrate.php` sur le serveur de
      prod : enregistre la version du schéma (migrations déjà appliquées à la main reconnues)
      et applique celles qui manquent, a priori `2026-10-06_sync_status` (statut de synchro,
      lu par l'onglet Comptes) et `2026-10-05_schema` (DECIMAL, utf8mb4, comptes uniques).
      Sans PHP CLI sur le NAS : appliquer ces deux fichiers dans phpMyAdmin.
- [ ] Installer `curl_cffi` (>= 0.7) dans l'image Docker woob (`ltoinel/woob:3.7`), requis par
      le correctif BNP appliqué dans `woob/.local/share/woob/modules/3.7/woob_modules/bnp`,
      puis vérifier la synchro BNP. Alternative : passer à l'image Docker de Carbure, qui
      embarque woob et `curl_cffi`.
- [ ] Supprimer le dépôt privé `Carbure-Archive` (après une sauvegarde `git bundle` si besoin).

## Dette technique

- [ ] Image Docker : passer de `php:8.3-apache-bookworm` à `-trixie` (Debian 13) pour
      réduire les CVE Debian sans correctif remontées par Trivy (zlib, util-linux,
      python3.11) ; vérifier woob et `curl_cffi` avec Python 3.13.

- [ ] Connexion MySQL en `utf8mb4` (`Db` n'appelle pas `set_charset`) : vérifier d'abord
      comment les accents sont stockés en production pour ne pas les corrompre.
- [ ] Regex `PRLV SEPA` gourmande (`ECH/…` n'est pas retiré du libellé) et `addslashes()`
      appliqué avant une requête préparée (double échappement). Les corriger change les libellés
      donc les UUID : prévoir une migration qui recalcule les UUID existants.
