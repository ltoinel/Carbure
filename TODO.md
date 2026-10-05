# TODO

Tâches restant à réaliser sur Carbure. Une tâche terminée est retirée de cette liste
(l'historique Git garde la trace de ce qui a été fait).

- [ ] **Production, avant de déployer le code OAuth** : appliquer la migration
      `sql/migrations/2026-10-14_oauth.sql` (bouton **Mettre à jour** du bandeau
      administrateur, phpMyAdmin, ou automatique avec l'image Docker). Sans elle, seuls
      l'enregistrement et l'autorisation OAuth échouent, le reste fonctionne.
- [ ] **Production** : dans le nginx du NAS, router `/.well-known/oauth-*` vers
      `src/api.php` comme `docker/nginx.conf`, renseigner `public_url` dans `prod.ini` si le
      proxy HTTPS n'envoie pas `X-Forwarded-Proto`, puis tester la connexion depuis claude.ai
      (Paramètres → Connecteurs → Ajouter un connecteur personnalisé).

- [ ] **Production** : vérifier que `data/conf/prod.ini` est modifiable par l'utilisateur du
      serveur web du NAS, sinon l'onglet **Administration → Config** reste en lecture seule
      (il écrit aussi `prod.ini.bak` dans le même dossier).
- [ ] **Production** : l'import de relevés envoie jusqu'à ~1,4 Mo (fichier de 1 Mo en
      base64) ; vérifier la taille maximale des requêtes du serveur web du NAS
      (`client_max_body_size` 2m dans l'image Docker).

- [x] Image Docker sur Alpine (`php:8.3-fpm-alpine`, woob construit à part) : plus de
      chaîne de compilation dans l'image, plus aucune alerte Trivy ouverte.
- [x] Publier une release (1.0.1) pour que l'image Alpine arrive sur Docker Hub, puis
      tester l'ajout d'une banque (BNP, `curl_cffi` sous musl) avec cette image.

- [ ] Connexion MySQL en `utf8mb4` (`Db` n'appelle pas `set_charset`) : vérifier d'abord
      comment les accents sont stockés en production pour ne pas les corrompre. Constaté en
      local : avec un serveur MariaDB en latin1 par défaut, les noms accentués reviennent mal
      encodés et `json_encode()` échoue — l'API répond alors **200 avec un corps vide**
      (`/budget`, `/budget/flow`…), ce qui fait échouer les e2e Budget et Transactions.
- [ ] `Webservice::callService` : quand `json_encode()` renvoie `false`, répondre 500 avec
      `json_last_error_msg()` dans les logs plutôt qu'un 200 vide.
- [ ] Regex `PRLV SEPA` gourmande (`ECH/…` n'est pas retiré du libellé) et `addslashes()`
      appliqué avant une requête préparée (double échappement). Les corriger change les libellés
      donc les UUID : prévoir une migration qui recalcule les UUID existants.
