# Agents IA (MCP)

Carbure embarque un **serveur MCP** ([Model Context Protocol](https://modelcontextprotocol.io/))
qui permet à un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) de consulter les
données du foyer et de répondre à des questions comme « Combien avons-nous dépensé en
restaurants cette année ? », « Quelles catégories dépassent leur budget ce mois-ci ? » ou
« Comment évolue notre épargne depuis 6 mois ? ».

- **Lecture seule** : aucun outil ne modifie les données.
- **Rien à installer** : le serveur est une route de l'API (`/api/mcp`), servie par le même
  serveur web que le portail.
- **Sous contrôle** : désactivé par défaut, un administrateur l'active ; chaque utilisateur
  crée ses jetons d'accès et les révoque quand il veut.

!!! warning "Confidentialité"
    Carbure reste hébergé chez vous, mais les données qu'un agent IA consulte (libellés,
    montants, catégories) sont envoyées à son fournisseur pour être analysées. N'activez cet
    accès que si cela vous convient.

## 1. Activer le serveur MCP

Onglet **Agents IA** du portail : un administrateur active **Serveur MCP activé**. Tant
qu'il est désactivé, `/api/mcp` répond `403`, même avec un jeton valide.

## 2. Créer un jeton d'accès

Toujours dans l'onglet **Agents IA**, bouton **+** : donnez un nom au jeton (par exemple
« Claude Code sur mon portable ») et choisissez sa **durée de validité** (30 jours, 90 jours,
1 an ou sans expiration ; un jeton expiré est refusé et signalé dans la liste). Le jeton (`cbt_…`) n'est affiché **qu'une seule fois**,
avec la configuration prête à copier pour chaque agent. La liste des jetons montre leur
date de dernière utilisation ; la corbeille révoque un jeton immédiatement.

Seule l'empreinte SHA-256 du jeton est conservée en base.

## 3. Connecter son agent IA

| Agent | Configuration (proposée par le portail) |
|---|---|
| Claude Code | `claude mcp add --transport http carbure <url> --header "Authorization: Bearer <jeton>"` |
| Claude Desktop | bloc `mcpServers` de `claude_desktop_config.json`, via `npx mcp-remote` (Node.js) |
| ChatGPT | connecteur en mode développeur, URL `<url>?token=<jeton>` (HTTPS public requis) |
| Cursor | bloc `mcpServers` de `~/.cursor/mcp.json` avec l'en-tête `Authorization` |
| VS Code (Copilot) | bloc `servers` de `mcp.json` (type `http`) avec l'en-tête `Authorization` |
| Gemini CLI | `gemini mcp add --transport http --header "Authorization: Bearer <jeton>" carbure <url>` |

`<url>` est `https://<votre-serveur>/api/mcp`. Tout client MCP compatible avec le transport
**Streamable HTTP** fonctionne de la même façon.

!!! note "Jeton dans l'URL"
    Pour les agents qui ne savent pas envoyer d'en-tête (ChatGPT), le jeton peut être passé
    en paramètre `?token=`. Il n'est pas écrit dans les logs de Carbure, mais peut l'être par
    un proxy : préférez l'en-tête quand l'agent le permet.

## Outils disponibles

| Outil | Rôle |
|---|---|
| `list_categories` | Catégories : nom, parent, type (dépense, revenu, hors budget) |
| `list_transactions` | Transactions d'un mois, éventuellement d'une catégorie |
| `search_transactions` | Transactions dont le libellé contient un texte |
| `spending_by_category` | Total et nombre de transactions par catégorie sur une période (`YYYY-MM`) |
| `get_budget` | Dépensé et budget prévu par catégorie pour un mois |
| `get_trends` | Débit, crédit, hors budget, budget prévu et épargne mois par mois |
| `list_rules` | Règles de catégorisation automatique |

## Détails techniques

- Transport **Streamable HTTP** sans session : `POST /api/mcp` reçoit un message JSON-RPC 2.0
  et répond en JSON ; une notification reçoit `202` sans corps ; `GET /api/mcp` répond `405`
  (pas de flux SSE).
- Versions du protocole : `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Authentification : jeton d'accès (`cbt_…`, en-tête `Authorization` ou `?token=`) ou JWT du
  portail ; `401` sinon. Une requête portant un en-tête `Origin` d'un autre site est refusée
  (`403`), comme toute requête quand le serveur est désactivé.
- État du serveur : `GET /api/mcp/settings`, `PUT /api/mcp/settings` (`enabled`,
  administrateurs), table `settings`.
- Les outils s'exécutent avec les droits de l'utilisateur du jeton.
- Code : `src/resources/Mcp.php` (protocole et outils), `src/resources/ApiToken.php` (jetons).
