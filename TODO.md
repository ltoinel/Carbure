# TODO

Tâches restant à réaliser sur Carbure. Cocher une tâche quand elle est terminée et
mergée ; ajouter les nouvelles tâches dans la bonne section.

## Déploiement (action manuelle)

- [ ] **Urgent** : `jwtsecret` de production = valeur par défaut `secret` → n'importe qui peut
      forger un JWT. Le remplacer (`openssl rand -hex 32`) ; les sessions seront à rouvrir.
- [x] Appliquer `sql/migrations/2026-10-03_audit.sql` sur la base de production
      (colonnes `is_admin` et `language`, `devices.token` élargi, FK `RESTRICT`).
- [ ] Ajouter `sync_token` dans `conf/prod.ini` et l'URL du cron
      (`/api/bank/sync?token=…` ou en-tête `X-Sync-Token`).
- [ ] Vider le cache APCu (redémarrer PHP-FPM) après chaque déploiement modifiant les routes.
- [ ] Changer le mot de passe MySQL présent dans l'ancien historique Git (`conf/settings.ini`).
- [ ] Synchro BNP en échec : `'NoneType' object has no attribute 'iter_accounts'` (backend woob).

## Portail

- [x] Recherche de transactions par libellé (API `GET /transaction/search` + formulaire).
- [x] Pointage des transactions non vérifiées + filtre « non vérifiées » à côté du nombre.
- [x] Page profil : liste des appareils avec token masqué et bouton copier.
- [ ] Vue chargée en version de développement depuis unpkg sans version figée ni SRI.
- [x] Onglet « Tendances » : débit, crédit, hors budget, budget planifié et épargne sur 24 mois.
- [x] Vue de gestion des règles de catégorisation automatique (mots-clés : liste, ajout, suppression).
- [x] Seuil d'alerte par utilisateur : push quand une transaction non vérifiée dépasse un montant.
- [ ] Gestion d'un JWT expiré (retour automatique à l'écran de connexion sur 401/403).

## Qualité

- [ ] Couverture des tests PHP ≥ 90 % (unitaires + intégration MariaDB).
- [x] Hooks Git : lint en pre-commit, tests unitaires en pre-push.
- [x] CI GitHub Actions : PR (lint, tests, couverture, secrets) et Release.
- [ ] Activer GitHub Pages (source « GitHub Actions ») dans les réglages du dépôt.
- [x] Documentation GitHub Pages (MkDocs Material).
- [x] README en français avec badges, AGENTS.md (CLAUDE.md local), licence MIT.

## Publication GitHub

- [ ] Préparer un historique neuf (commit unique, auteur noreply) dans un clone séparé.
- [x] Retirer l'IP interne `192.168.2.2` des valeurs par défaut du portail.

## Base de données

- [x] Index sur `bank_transaction.date` (migration 2026-10-04).
- [ ] Filtres en intervalle de dates au lieu de `MONTH()`/`YEAR()` (fait pour `GET /transaction` ; reste `Budget::get`, insights, `countUnpointed`).
- [ ] Réécrire `Budget::get` (agrégation unique du mois, sans `OR` ni sous-requête corrélée).
- [ ] `budget.amount` en `DECIMAL(10,2)` ; tables en `utf8mb4`.
- [ ] Unicité `(account_number, bank_name, user_id)` sur `bank_account`.
- [ ] Charger les mots-clés une seule fois dans `updateMissingCategories` (N+1).
- [ ] Remplacer le SQL stocké dans `budget_insight` par des insights définis dans le code.

## Dette technique connue

- [ ] Regex `PRLV SEPA` gourmande : `ECH/…` n'est pas retiré du libellé. La corriger change
      les libellés donc les UUID → prévoir une migration des UUID existants.
- [ ] `cleanLabel()` applique `addslashes()` avant une requête préparée (double échappement) ;
      même contrainte d'UUID que ci-dessus.
- [ ] Signature APNs ES256 : `openssl_sign` produit du DER, JWT attend R‖S brut (à vérifier
      en conditions réelles) ; mettre en cache le JWT APNs (limite de renouvellement Apple).
- [ ] Un appareil qui se reconnecte avec un autre compte garde l'ancien `user_id`.
- [ ] `Category::find()` recharge tous les mots-clés pour chaque transaction.
