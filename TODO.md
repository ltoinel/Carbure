# TODO

Tâches restant à réaliser sur Carbure. Une tâche terminée est retirée de cette liste
(l'historique Git garde la trace de ce qui a été fait).

- [ ] **Production** : tester la connexion de Claude au serveur MCP depuis claude.ai
      (Paramètres → Connecteurs → Ajouter un connecteur personnalisé). L'image Docker applique
      la migration OAuth au démarrage et route `/.well-known/oauth-*` ; renseigner `public_url`
      (onglet **Administration → Paramètres**) si le proxy HTTPS du NAS n'envoie pas
      `X-Forwarded-Proto`.

- [ ] **Production** : après le déploiement, vérifier sur la page Journaux que l'IP des
      entrées est celle du client et non celle du proxy inversé de DSM (il doit envoyer
      `X-Forwarded-For`). Régler `log_retention_days` (absent des configurations existantes :
      logs conservés indéfiniment) dans **Administration → Paramètres**.

- [ ] Connexion MySQL en `utf8mb4` (`Db` n'appelle pas `set_charset` : la connexion prend le
      jeu de caractères par défaut du serveur). Avant de l'ajouter, vérifier en production ce
      que reçoit PHP et comment les accents sont stockés :

      ```bash
      docker compose exec carbure php -r '$c = parse_ini_file("/data/conf/prod.ini");
        $m = new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_name"], (int)($c["db_port"] ?? 3306));
        echo $m->character_set_name(), "\n";
        foreach ($m->query("SELECT name, HEX(name) AS h FROM bank_transaction_category WHERE name LIKE \"%pargne%\"") as $r) echo $r["name"], " ", $r["h"], "\n";'
      ```

      `utf8mb4` (et `É` = `C389`) : `set_charset('utf8mb4')` ne change rien, l'ajouter.
      `latin1` avec `É` = `C383E280B0` : les accents sont stockés doublement encodés ; il faut
      d'abord une migration de conversion, sinon le portail afficherait « Ã‰pargne ».
      Symptôme d'un serveur en latin1 : l'API répond 500 (« cannot be encoded in JSON » dans
      les logs) dès qu'un texte accentué a été écrit par une connexion utf8mb4.
- [ ] Regex `PRLV SEPA` gourmande (`ECH/…` n'est pas retiré du libellé) et `addslashes()`
      appliqué avant une requête préparée (double échappement). Les corriger change les libellés
      donc les UUID : prévoir une migration SQL qui recalcule libellés et UUID à l'identique
      de PHP (montant au format float PHP `-42.5`, 24 premiers **octets** du libellé pour les
      cartes, uniquement les lignes dont l'UUID actuel correspond à la formule), et gérer les
      collisions d'UUID. La regex est dans le `prod.ini` de chaque instance : la corriger dans
      `prod.sample.ini` ne suffit pas.
