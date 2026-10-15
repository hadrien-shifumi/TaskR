## Context

La change `mcp-access` (archivée) a ouvert Taskr aux agents IA : serveur `laravel/mcp` sur `/mcp`, auth par personal access tokens Sanctum, outils fins dans `app/Mcp/Tools/` qui résolvent le workspace (`ResolveAgentWorkspace` + `WorkspaceContext`), délèguent aux actions `AsObject` et sérialisent — jamais de logique métier dans la couche MCP. Le socle multi-tenant (trait `BelongsToWorkspace` + global scope) isole chaque requête par workspace. Les issues ont déjà une hiérarchie molle parent/enfant avec prévention de cycles (`SetIssueParent`), et le frontend dispose de `MarkdownEditor`/`MarkdownView` (tiptap, rendu sans HTML brut).

Décisions produit actées pendant l'exploration (voir proposal) :

- Portée : pages au niveau workspace, projet optionnel (un seul modèle).
- Structure : pages Markdown arborescentes.
- Recherche **sémantique dès la v1** : embeddings via endpoint OpenAI-compatible configurable.
- Écart dev/prod : dev et tests tournent sur SQLite `:memory:` ; la production sera Postgres avec `pgvector`.
- Agents MCP : lecture ET écriture ; pas d'historique de révisions.

## Goals / Non-Goals

**Goals:**

- Une base de connaissance métier par workspace : pages Markdown hiérarchiques, transverses ou rattachées à un projet.
- Recherche par similarité sémantique exploitable par des agents (résultats rankés + extraits + scores) et par des humains (barre de recherche).
- Un pipeline de vectorisation asynchrone résilient : l'écriture ne dépend jamais de la disponibilité de l'endpoint d'embeddings.
- Exposition MCP complète (lecture + écriture) déléguant aux actions, avec l'isolation et le contrat d'erreurs existants.
- Zéro nouvelle dépendance composer : client embeddings HTTP natif, opérateur pgvector en SQL brut.

**Non-Goals:**

- Historique de révisions, chunking des longues pages, recherche hybride lexicale+sémantique (upgrade v2 possible sans casser le contrat).
- Suppression via MCP (opération destructive, humaine en v1 — l'agent peut vider une page via `update_knowledge` s'il doit la retirer du contexte).
- Permissions granulaires, partage public, export/import, pièces jointes.
- UI de configuration des embeddings (variables d'environnement uniquement).

## Decisions

### ADR-1 : Modèle de données — `knowledge_pages`, hiérarchie miroir des issues

**Décision** : table `knowledge_pages` : `id` ULID, `workspace_id` FK, `project_id` FK nullable (projet du même workspace, validé par `Rule::exists(...)->where('workspace_id', ...)`), `parent_id` self-FK nullable, `title`, `body` (Markdown), `last_editor_id` FK users, timestamps. Modèle `KnowledgePage` (ULID, trait `BelongsToWorkspace`).

Invariants repris de `SetIssueParent` à l'identique :

- parent du même workspace (validé par règle d'existence scopée) ;
- pas de cycle : un parent ne peut pas être un descendant de la page (même contrôle qu'`ancestorIds()`, promu en méthode réutilisable si duplication) ;
- suppression refusée tant que la page a des enfants (même convention que la suppression conditionnée des projets) ;
- déplacement = changement de parent avec les mêmes contrôles.

**Alternatives rejetées** : deux tables workspace/projet (doublonnage pour le même comportement) ; arbre par matérialized path / nested set (prématuré : le nombre de pages par workspace ne justifie pas la complexité, le parcours parent/enfant suffit).

### ADR-2 : Client d'embeddings — HTTP natif, OpenAI-compatible configurable, sans dépendance

**Décision** : classe support `App\Support\Embeddings` : un appel POST `{base_url}/embeddings` via le HTTP client Laravel, format de requête/réponse OpenAI (`input: string[]`, `data[].embedding`), configurable par `config('knowledge.embeddings.*)` : `base_url`, `api_key`, `model`, et une méthode `enabled(): bool` (config remplie). Entrée vectorisée : `title + "\n\n" + body` (le titre porte l'essentiel du signal de retrieval à l'échelle page). Aucune interface ni driver abstrait : un seul fournisseur, la compatibilité OpenAI couvre OpenAI, Ollama, et la plupart des fournisseurs.

**Alternatives rejetées** : SDK `openai-php/laravel` (dépendance et lock-in pour un seul endpoint) ; Voyage AI dédié (compte séparé, même logique via une 2ᵉ implémentation si besoin un jour).

### ADR-3 : Stockage des vecteurs — colonne conditionnelle au driver, opérateur pgvector en SQL brut

**Décision** : la migration crée la colonne d'embedding selon le driver :

- **pgsql** : `ALTER TABLE knowledge_pages ADD COLUMN embedding vector(1536)` (via `DB::statement`, Blueprint n'ayant pas ce type) + index `ivfflat (embedding vector_cosine_ops)`. Dimension fixée à 1536 (`text-embedding-3-small` par défaut) — constante documentée, un changement de modèle/dimensions est une migration future.
- **sqlite** (dev/tests) : colonne `embedding` de type `text` contenant le vecteur en JSON.

Pas de package `pgvector/pgvector-laravel` : la requête de recherche pgsql utilise directement l'opérateur cosinus (`embedding <=> $query::vector`) dans une expression `orderByRaw` — trois lignes de SQL containées, contre une dépendance à maintenir.

**Justification** : un seul point de vérité (la migration) adapte le stockage au moteur ; le fallback SQLite rend dev et tests (`:memory:`) autonomes, sans Postgres obligatoire.

### ADR-4 : Recherche — action `SearchKnowledge` avec une couture par driver, contrat unique

**Décision** : `Actions\Knowledge\SearchKnowledge` (`AsObject` + `AsController`) — `handle(Workspace $workspace, string $query, ?string $projectId, int $limit)` retourne `[id, title, excerpt, score, project_id]`. Déroulé :

1. `Embeddings::embed($query)` (un appel HTTP) ; si non configuré → erreur explicite de configuration (`HttpException` avec message actionnable, traduite côté MCP en résultat d'erreur distinct, côté UI en message d'état).
2. Classement par driver :
   - **pgsql** : `whereNotNull('embedding')` [ + filtre projet ] `orderByRaw('embedding <=> ?::vector', [...])` → score `1 - distance` ;
   - **sqlite** : fetch des lignes vectorisées du workspace, cosinus calculé en PHP (O(n) exact — dimension acceptable pour les jeux de dev/test).
3. Extrait : premiers ~280 caractères du corps, coupés proprement.

Le contrat (entrée → résultats rankés avec score) est stable : une future recherche hybride ou un changement de modèle d'embeddings se fait sous cette signature sans toucher UI ni MCP.

**Alternatives rejetées** : Laravel Scout (drivers externes type Meilisearch = nouvelle infra ; driver `database` = LIKE, pas sémantique) ; forcer Postgres partout en dev/tests (change le workflow de toute l'équipe pour une seule feature).

### ADR-5 : Vectorisation — job en file à l'écriture, page consultable immédiatement

**Décision** : job `VectorizeKnowledgePage(KnowledgePage $page)` mis en file par `CreateKnowledgePage`/`UpdateKnowledgePage` après persist : embedde (titre + corps) puis stocke le vecteur et `embedded_at`. `tries`/`backoff` sur le job pour absorber les pannes de l'endpoint ; en cas d'échec définitif la page reste consultable et absente de la recherche jusqu'à la prochaine édition (ou un `php artisan knowledge:reembed` fourni pour rejouer une vectorisation complète, utile aussi après changement de modèle). Une page non vectorisée est exclue des résultats de recherche mais jamais de la navigation.

**Justification** : l'écriture (humaine ou agent) ne doit jamais échouer ni se bloquer à cause d'un service externe ; la cohérence à terme (vectorisation idempotente, retentée) prime sur la disponibilité immédiate en recherche.

### ADR-6 : Outils MCP — mapping outil → action, pas de delete

**Décision** : cinq outils dans `app/Mcp/Tools/`, suivant l'envelope `AgentTool` (résolution workspace, erreurs structurées, flush du contexte) :

| Outil MCP | Point d'entrée |
|---|---|
| `search_knowledge` | `Actions\Knowledge\SearchKnowledge` (requête, projet optionnel, limit) |
| `list_knowledge` | nouvelle action de lecture `ListKnowledgePages` (flat : `id`, `title`, `parent_id`, `project_id`, `updated_at` — l'agent reconstruit l'arbre) |
| `get_knowledge` | nouvelle action de lecture `GetKnowledgePage` (contenu intégral Markdown) |
| `create_knowledge` | `Actions\Knowledge\CreateKnowledgePage` |
| `update_knowledge` | `Actions\Knowledge\UpdateKnowledgePage` |

`delete_knowledge` n'est PAS exposé en v1 : la suppression est conditionnée aux enfants et irréversible — décision humaine. Les descriptions d'outils guident l'agent vers le parcours d'imprégnation : `list_knowledge` → `get_knowledge` / `search_knowledge`.

### ADR-7 : UI — section Knowledge par workspace, réutilisation des composants existants

**Décision** : `resources/js/Pages/Knowledge/Index.tsx` : panneau arborescent (pages transverses + regroupement par projet), lecteur avec `MarkdownView`, éditeur dialog avec `MarkdownEditor` (sélecteur de parent et de projet), barre de recherche sémantique (appels à `SearchKnowledge` en `AsController`). Navigation intégrée au switch de workspace existant (tout est scopé par le `WorkspaceContext`). Aucun composant d'édition/rendu à créer.

**Justification** : tiptap rend le Markdown via son schéma de document — pas d'HTML brut injecté, la sécurité du rendu est héritée du composant déjà en production sur les issues.

## Risks / Trade-offs

- [Prod requiert l'extension Postgres `pgvector`] → documenté dans le plan de déploiement ; en son absence, la recherche échoue explicitement mais écriture/navigation fonctionnent (vecteurs stockés, non requêtables) — même dégradation que l'absence de config embeddings.
- [Chemin pgsql de la recherche peu testé en CI (suite sur SQLite)] → le cosinus PHP et le SQL pgvector partagent le même contrat ; tests unitaires dédiés au SQL pgvector via grammar assertion, et `knowledge:reembed` + recherche manuels à valider en staging.
- [Endpoint d'embeddings down] → job retenté avec backoff, écritures jamais bloquées ; fenêtre d'invisibilité en recherche limitée aux pages modifiées pendant la panne.
- [Changement de modèle/dimensions d'embeddings] → vecteurs incompatibles silencieusement : mitigé par le job idempotent + `knowledge:reembed` ; dimension 1536 documentée comme constante.
- [Coût/latence d'un appel d'embedding par recherche] → un seul appel par requête (page-level), cacheable plus tard si nécessaire ; coût marginal (text-embedding-3-small).
- [Agent qui réécrit mal la connaissance] → dernier éditeur enregistré (traçabilité) ; l'historique de révisions est l'answer v2 si le besoin apparaît.
- [Pages très longues diluant le signal sémantique] → assumé en v1 (embedding page-level) ; le chunking est un upgrade interne au pipeline (ADR-5) sans changement de contrat.

## Migration Plan

1. Migration `knowledge_pages` (colonne embedding conditionnelle au driver) — aucune donnée existante à migrer.
2. Config `knowledge.embeddings.*` (base_url, api_key, model) + documentation `.env.example`.
3. Actions `app/Actions/Knowledge/`, job `VectorizeKnowledgePage`, support `Embeddings`, commande `knowledge:reembed`.
4. Outils MCP (5) enregistrés dans le serveur existant.
5. UI Knowledge + navigation workspace.
6. Feature tests (CRUD, hiérarchie/cycles, isolation, recherche avec embeddings fakes déterministes, MCP) puis suite complète au vert.

Rollback : la feature est additive — retirer les routes/pages/outil MCP et ignorer la table ; aucune donnée existante impactée.

## Open Questions

- Regroupement de l'arbre UI : plat avec indentation, ou sections « Transverse » / par projet — à trancher en implémentation selon la volumétrie réelle.
- Extrait de recherche : extrait de tête fixe (~280 caractères) vs paragraphe le plus pertinent — v2 lexicale si le besoin apparaît.
- Nombre de résultats par défaut côté MCP : suivre la convention `list_*` existante (limit 25, offset) — à confirmer en implémentation.
