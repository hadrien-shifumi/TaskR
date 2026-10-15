## Why

Taskr connaît les workspaces, projets, issues — mais rien ne capture la **connaissance métier** : conventions, glossaire, décisions d'architecture, processus. Cette connaissance vit dans la tête des membres ou dans des outils externes, si bien que chaque nouvel arrivant (humain ou agent) démarre sans contexte, et les agents IA opèrent sur les issues sans comprendre le produit. Une base de connaissance logée dans le workspace, requêtable par similarité sémantique et exposée via MCP, rend ce contexte durable, injectable en contexte d'agent et navigable par les utilisateurs.

## What Changes

- Nouvelle entité **page de connaissance** au niveau du workspace : titre, corps Markdown, hiérarchie parent/enfant optionnelle (arborescence type wiki), rattachement optionnel à un projet (connaissance transverse au workspace OU spécifique à un projet).
- **Recherche sémantique** dès la v1 : chaque page est vectorisée (embeddings) à l'écriture via un endpoint d'embeddings OpenAI-compatible configurable (base URL + modèle + clé, zéro dépendance composer) ; la recherche embedde la requête puis classe les pages par similarité cosinus, dans le périmètre du workspace (filtrable par projet).
- Stockage des vecteurs : colonne `vector` pgvector + index en production Postgres ; JSON + cosinus calculé en PHP sur SQLite (dev/tests) — une seule couture dans l'action de recherche, contrat identique.
- CRUD complet côté UI (membres du workspace, même convention que les projets) avec une section Knowledge : arborescence, lecture rendue Markdown, recherche, éditeur.
- **Exposition MCP** : outils `search_knowledge`, `list_knowledge` (arborescence des titres), `get_knowledge` (contenu intégral), `create_knowledge`, `update_knowledge` — l'agent peut s'imprégner ET maintenir la connaissance à jour, avec l'isolation multi-tenant et les permissions identiques à l'UI.
- Pas d'historique des révisions en v1 (dernier éditeur + `updated_at` uniquement) ; pas de chunking (embedding au niveau page, titre inclus).

Hors périmètre (volontairement) : historique/révisions, recherche hybride lexicale+sémantique, chunking des longues pages, pièces jointes, export/import, permissions granulaires.

## Capabilities

### New Capabilities

- `knowledge-base`: pages de connaissance Markdown arborescentes scopées workspace (projet optionnel), CRUD par les membres, recherche sémantique par similarité d'embeddings, pipeline de vectorisation à l'écriture.

### Modified Capabilities

- `mcp-access`: ajout d'outils MCP de connaissance (`search_knowledge`, `list_knowledge`, `get_knowledge`, `create_knowledge`, `update_knowledge`) déléguant aux actions métier, soumis aux mêmes règles d'authentification, d'isolation et d'erreurs que les outils existants.

## Impact

- Base de données : migration `knowledge_pages` (workspace_id, project_id nullable, parent_id nullable, title, body, timestamps, auteur de la dernière édition) + colonne embedding (vector pgsql / texte JSON sqlite) ; pas de migration des données existantes.
- Backend : modèle `KnowledgePage` (ULID, `BelongsToWorkspace`), actions dans `app/Actions/Knowledge/` (CRUD, recherche, arbre), support `Embeddings` (client HTTP OpenAI-compatible), job de vectorisation en file d'attente.
- MCP : nouveaux outils dans `app/Mcp/Tools/` suivant le pattern existant (résolution workspace → action → sérialisation).
- Frontend : section Knowledge (arbre + page + éditeur + recherche) en Inertia, rendu Markdown sécurisé.
- Configuration : clés `KNOWLEDGE_EMBEDDINGS_*` (base URL, modèle, clé API) ; aucune nouvelle dépendance composer.
- Production : extension Postgres `pgvector` requise (déploiement à adapter).
