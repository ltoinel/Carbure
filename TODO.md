# TODO

Tâches restant à réaliser sur Carbure. Une tâche terminée est retirée de cette liste
(l'historique Git garde la trace de ce qui a été fait).

## Production (actions manuelles)

- [ ] **Urgent** : le nginx du NAS sert tout le projet : `data/` (configuration,
      identifiants woob) et `.git/` sont téléchargeables. Restreindre aux chemins publics
      (`/portal`, `/api`, `/swagger`), puis renouveler les secrets exposés (code BNP, mot de
      passe MySQL, `jwtsecret`, `sync_token`, `password_salt`).

- [ ] **Urgent** : remplacer le `jwtsecret` de production (valeur par défaut `secret`) :
      bouton **Renouveler** du bandeau affiché aux administrateurs dans le portail (le serveur
      web doit pouvoir écrire `conf/prod.ini`), ou à la main avec `openssl rand -hex 32`.
      Les sessions seront à rouvrir.
- [ ] Changer le mot de passe MySQL présent dans l'ancien historique (dépôt `Carbure-Archive`).
- [ ] Ajouter `sync_token` dans `conf/prod.ini` et l'utiliser dans le cron
      (`/api/bank/sync` avec l'en-tête `X-Sync-Token`).
- [ ] Mettre la base de prod à jour : après le déploiement, un administrateur voit le bandeau
      **Mise à jour de la base de données** dans le portail → sauvegarder la base (phpMyAdmin),
      puis **Mettre à jour**. Les migrations déjà appliquées à la main sont reconnues ; restent
      a priori `2026-10-05_schema` à `2026-10-13_rule_notify` (statut de synchro, insights,
      paramètres, connexions, jetons, règles). Le serveur web doit pouvoir lire `sql/`.
- [ ] Installer `curl_cffi` (>= 0.7) dans l'image Docker woob (`ltoinel/woob:3.7`), requis par
      le correctif BNP appliqué dans `woob/.local/share/woob/modules/3.7/woob_modules/bnp`,
      puis vérifier la synchro BNP. Alternative : passer à l'image Docker de Carbure, qui
      embarque woob et `curl_cffi`.
- [ ] Supprimer le dépôt privé `Carbure-Archive` (après une sauvegarde `git bundle` si besoin).

## Dette technique

- [x] Image Docker sur Alpine (`php:8.3-fpm-alpine`, woob construit à part) : plus de
      chaîne de compilation dans l'image, plus aucune alerte Trivy ouverte.
- [ ] Publier une release (1.0.1) pour que l'image Alpine arrive sur Docker Hub, puis
      tester l'ajout d'une banque (BNP, `curl_cffi` sous musl) avec cette image.

- [ ] Connexion MySQL en `utf8mb4` (`Db` n'appelle pas `set_charset`) : vérifier d'abord
      comment les accents sont stockés en production pour ne pas les corrompre.
- [ ] Regex `PRLV SEPA` gourmande (`ECH/…` n'est pas retiré du libellé) et `addslashes()`
      appliqué avant une requête préparée (double échappement). Les corriger change les libellés
      donc les UUID : prévoir une migration qui recalcule les UUID existants.
