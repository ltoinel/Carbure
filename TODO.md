# TODO

Tâches restant à réaliser sur Carbure. Une tâche terminée est retirée de cette liste
(l'historique Git garde la trace de ce qui a été fait).

## Production (actions manuelles)

- [ ] **Urgent** : remplacer le `jwtsecret` de production (valeur par défaut `secret`),
      par exemple avec `openssl rand -hex 32`. Les sessions seront à rouvrir.
- [ ] Changer le mot de passe MySQL présent dans l'ancien historique (dépôt `Carbure-Archive`).
- [ ] Ajouter `sync_token` dans `conf/prod.ini` et l'utiliser dans le cron
      (`/api/bank/sync` avec l'en-tête `X-Sync-Token`).
- [ ] Appliquer `sql/migrations/2026-10-05_schema.sql` (DECIMAL, utf8mb4, comptes uniques ;
      sans impact sur le code, à faire quand on veut).
- [ ] Installer `curl_cffi` (>= 0.7) dans l'image Docker woob (`ltoinel/woob:3.7`), requis par
      le correctif BNP appliqué dans `woob/.local/share/woob/modules/3.7/woob_modules/bnp`,
      puis vérifier la synchro BNP. Alternative : passer à l'image Docker de Carbure, qui
      embarque woob et `curl_cffi`.
- [ ] Supprimer le dépôt privé `Carbure-Archive` (après une sauvegarde `git bundle` si besoin).

## Évolutions

- [ ] Paquet Synology (`.spk`) généré par le pipeline de release, en plus du `.zip`, du
      `.tar.gz` et de l'image Docker : métadonnées du paquet, scripts d'installation
      réutilisant `tools/install.php`, intégration Web Station et MariaDB.
- [ ] Remplacer le SQL stocké dans `budget_insight` par des insights définis dans le code
      (nécessite de connaître les insights actuellement utilisés en production).

## Dette technique

- [ ] Connexion MySQL en `utf8mb4` (`Db` n'appelle pas `set_charset`) : vérifier d'abord
      comment les accents sont stockés en production pour ne pas les corrompre.
- [ ] Regex `PRLV SEPA` gourmande (`ECH/…` n'est pas retiré du libellé) et `addslashes()`
      appliqué avant une requête préparée (double échappement). Les corriger change les libellés
      donc les UUID : prévoir une migration qui recalcule les UUID existants.
