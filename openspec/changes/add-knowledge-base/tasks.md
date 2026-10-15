## 1. Configuration et client d'embeddings

- [x] 1.1 Config `config/knowledge.php` : `embeddings.base_url`, `embeddings.api_key`, `embeddings.model` (via `KNOWLEDGE_EMBEDDINGS_*`), avec commentaires documentant la compatibilité OpenAI et la nécessité de `pgvector` en production ; mettre à jour `.env.example`
- [x] 1.2 Support `App\Support\Embeddings` : POST `{base_url}/embeddings` via le HTTP client Laravel (format OpenAI : `input: string[]` → `data[].embedding`), méthode `enabled(): bool`, aucune dépendance composer, aucune interface abstraite
- [x] 1.3 Tests unitaires du client avec `Http::fake()` : réponse parsée en tableau de floats, erreur HTTP propagée proprement, `enabled()` faux sans config

## 2. Modèle et migration

- [x] 2.1 Migration `knowledge_pages` : `id` ULID, `workspace_id` FK, `project_id` FK nullable, `parent_id` self-FK nullable, `title`, `body` (Markdown), `last_editor_id` FK users, `embedded_at` nullable, timestamps ; colonne `embedding` conditionnelle au driver — `vector(1024)` + index `ivfflat (embedding vector_cosine_ops)` via `DB::statement` sur pgsql, `text` (JSON) sur sqlite ; index `(workspace_id)` et `(project_id)`
- [x] 2.2 Modèle `KnowledgePage` : ULID, trait `BelongsToWorkspace`, relations `project`/`parent`/`children`/`lastEditor`, `ancestorIds()` (méthode partagée avec `Issue` si le dédoublonnage est trivial) + factory avec états « vectorisée » / « non vectorisée »
- [x] 2.3 Tests : auto-fill `workspace_id` depuis le `WorkspaceContext`, isolation par global scope (page d'un autre workspace introuvable par identifiant direct)

## 3. Actions CRUD et invariants

- [x] 3.1 `CreateKnowledgePage` (`AsObject` + `AsController`) : titre requis, corps Markdown, `project_id` et `parent_id` optionnels validés dans le workspace courant (`Rule::exists` scopé), enregistre `last_editor_id`, met en file `VectorizeKnowledgePage` ; responder dédié + route nommée
- [x] 3.2 `UpdateKnowledgePage` : titre/corps/projet/parent, contrôle de cycle (miroir de `SetIssueParent` : parent ≠ self, parent non descendant), re-vectorisation via le job, `last_editor_id` = utilisateur courant
- [x] 3.3 `DeleteKnowledgePage` : refusée tant que la page a des enfants (message invitant à les déplacer/supprimer)
- [x] 3.4 Tests feature : création transverse et rattachée projet ; projet d'un autre workspace refusé ; édition par un membre ≠ auteur initial (dernier éditeur reflété) ; cycle refusé, arborescence inchangée ; déplacement sans cycle OK ; suppression refusée avec enfants

## 4. Vectorisation

- [x] 4.1 Job `VectorizeKnowledgePage` : `tries`/`backoff`, embedde `title + "\n\n" + body`, stocke le vecteur (cast selon driver) et `embedded_at`, idempotent
- [x] 4.2 Commande `knowledge:reembed` : rejoue la vectorisation de toutes les pages (utile après changement de modèle/dimensions)
- [x] 4.3 Tests : job avec `Http::fake` ; endpoint indisponible → édition conservée, job retenté, page consultable et absente de la recherche jusqu'à vectorisation

## 5. Recherche sémantique et actions de lecture

- [x] 5.1 Action `SearchKnowledge` (`AsObject` + `AsController`) : `Embeddings::embed(query)` puis classement par couture driver — pgsql `orderByRaw('embedding <=> ?::vector')` avec score `1 - distance`, sqlite cosinus en PHP sur les lignes vectorisées du workspace ; filtre projet optionnel, `limit`, extrait ~280 caractères coupé proprement ; erreur de configuration explicite (`HttpException` actionnable) quand les embeddings ne sont pas configurés
- [x] 5.2 Actions de lecture `AsObject` : `ListKnowledgePages` (plat : `id`, `title`, `parent_id`, `project_id`, `updated_at`) et `GetKnowledgePage` (contenu intégral Markdown)
- [x] 5.3 Tests feature avec embeddings fakes déterministes (vecteurs craftés, aucun réseau) : une page « Processus de règlement et invoices » ressort pour la requête « comment se passe la facturation d'un client » sans mots-clés exacts ; scoping workspace strict ; filtre projet ; pages non vectorisées exclues mais navigables ; erreur de config explicite (jamais de résultat silencieusement vide)
- [x] 5.4 Test unitaire du SQL pgsql de la recherche (assertion de grammar sur l'opérateur cosinus et le tri) — le chemin prod ne pouvant pas s'exécuter sur sqlite

## 6. UI Knowledge

- [x] 6.1 Page Inertia `Knowledge/Index` + action `ShowKnowledgePage` : arborescence (pages transverses + regroupement par projet), lecteur `MarkdownView` (rendu sécurisé hérité de tiptap), scopée au workspace actif
- [x] 6.2 Éditeur en dialog avec `MarkdownEditor` (titre, corps, sélecteurs parent et projet) pour création et édition ; suppression avec garde enfants
- [x] 6.3 Barre de recherche sémantique branchée sur `SearchKnowledge` (`AsController`), résultats avec titre + extrait + navigation vers la page
- [x] 6.4 Entrée « Knowledge » dans la navigation du workspace
- [x] 6.5 Tests browser (Pest) : parcours d'imprégnation d'un nouvel arrivant (arbre → lecture d'une page), création puis édition d'une page, recherche UI retournant la page pertinente

## 7. Outils MCP

- [x] 7.1 `list_knowledge` (arborescence plate reconstruisable) et `get_knowledge` (contenu intégral) — délégation aux actions de lecture, sérialisation plate, descriptions guidant le parcours d'imprégnation
- [x] 7.2 `search_knowledge` : requête naturelle, filtre projet optionnel, limit ; erreur de configuration embeddings retournée en résultat d'erreur distinct (ni validation, ni autorisation)
- [x] 7.3 `create_knowledge` et `update_knowledge` : mêmes validations/invariants que l'UI (cycle, projet du même workspace), dernier éditeur = utilisateur authentifié ; pas de `delete_knowledge`
- [x] 7.4 Tests feature MCP : `get_knowledge` sur une page d'un autre workspace → not found sans fuite d'existence ; `update_knowledge` créant un cycle → erreur métier identique à l'UI ; parcours `list_knowledge` → `get_knowledge`/`search_knowledge` ; re-vectorisation déclenchée après `update_knowledge`

## 8. Vérification finale

- [x] 8.1 Suite complète au vert : unit + feature + browser (`php artisan test`)
- [x] 8.2 `vendor/bin/pint --dirty --format agent` au propre
- [ ] 8.3 Dogfood agent réel (client MCP + token) : `list_knowledge` → `get_knowledge` → `search_knowledge` avec une question naturelle → `update_knowledge` → re-`search_knowledge` reflétant la mise à jour
- [x] 8.4 Revue du pattern : toute requête DB dans une Action, aucun `Inertia::render` hors Responder, aucune logique métier ni appel réseau dans `app/Mcp/` (résolution + appel d'action + sérialisation uniquement)
- [ ] 8.5 Vérifier le chemin pgsql en conditions réelles (staging ou Postgres local) : migration `vector(1024)` + index, recherche `<=>` fonctionnelle, `knowledge:reembed` idempotent
