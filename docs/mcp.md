# Analyser ses données avec Claude (MCP)

Carbure embarque un **serveur MCP** ([Model Context Protocol](https://modelcontextprotocol.io/))
qui permet à Claude de consulter les données du foyer et de répondre à des questions
comme « Combien avons-nous dépensé en restaurants cette année ? », « Quelles catégories
dépassent leur budget ce mois-ci ? » ou « Comment évolue notre épargne depuis 6 mois ? ».

- **Lecture seule** : aucun outil ne modifie les données.
- **Rien à installer** : le serveur est une route de l'API (`/api/mcp`), servie par le même
  serveur web que le portail.
- **Accès révocable** : Claude s'authentifie avec un jeton d'accès créé dans le portail.

!!! warning "Confidentialité"
    Carbure reste hébergé chez vous, mais les données que Claude consulte (libellés,
    montants, catégories) sont envoyées à Anthropic pour être analysées. N'activez cet
    accès que si cela vous convient.

## 1. Créer un jeton d'accès

Dans le portail : **menu utilisateur → Mon profil → Accès pour Claude (MCP) → +**.
Donnez-lui un nom (par exemple « Claude Code sur mon portable »). Le jeton (`cbt_…`) n'est
affiché **qu'une seule fois** : copiez-le, ou copiez directement la commande proposée.

Seule son empreinte SHA-256 est conservée en base ; la date de dernière utilisation est
affichée, et le jeton se révoque d'un clic.

## 2. Ajouter Carbure à Claude

### Claude Code

```bash
claude mcp add --transport http carbure https://carbure.example/api/mcp \
  --header "Authorization: Bearer cbt_xxxxxxxx"
```

Puis, dans Claude Code : `/mcp` pour vérifier la connexion, et posez vos questions.

### Autres clients (Claude Desktop…)

Tout client MCP compatible avec le transport **Streamable HTTP** et les en-têtes
personnalisés fonctionne : URL `https://<votre-serveur>/api/mcp`, en-tête
`Authorization: Bearer <jeton>`.

!!! note "Connecteurs de claude.ai"
    Les connecteurs personnalisés de claude.ai (web et mobile) exigent un serveur joignable
    depuis Internet et une authentification OAuth, que Carbure ne propose pas : utilisez
    Claude Code ou un client qui accepte un en-tête d'authentification.

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
- Authentification : jeton d'accès (`cbt_…`) ou JWT du portail ; `401` sinon. Une requête
  portant un en-tête `Origin` d'un autre site est refusée (`403`).
- Les outils s'exécutent avec les droits de l'utilisateur du jeton.
- Code : `src/resources/Mcp.php` (protocole et outils), `src/resources/ApiToken.php` (jetons).
