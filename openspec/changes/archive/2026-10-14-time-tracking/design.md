## Context

La change `foundation` est archivée : socle multi-tenant en place (workspaces, rôles owner/member, scoping par `BelongsToWorkspace` + `WorkspaceContext`), pattern ADR opérationnel avec `lorisleiva/laravel-actions` (une action = `AsObject` + `AsController`, `handle()` + `rules()` + `authorize()`), issues à hiérarchie molle avec commentaires éditables par leur auteur, UI Inertia + React + shadcn/ui.

Cette change ajoute la brique temps : estimer "en gros" une issue en heures, et permettre aux membres de logguer le temps passé. Décisions produit déjà prises pendant l'exploration :

- Heures décimales, pas de dates de début/fin ni de sessions.
- Édition/suppression d'une entrée par son auteur uniquement (parité avec les commentaires).
- Le rollup projet ("les projets progressent-ils ?") est reporté à la change `project-health`.

## Goals / Non-Goals

**Goals:**

- Tout membre peut définir/modifier/réinitialiser l'estimation d'une issue (heures, nullable).
- Tout membre peut logguer du temps sur une issue : heures décimales > 0, date travaillée, note optionnelle.
- L'auteur d'une entrée peut l'éditer et la supprimer ; personne d'autre.
- La vue détail issue expose : estimation (affichage + édition), section "Time" (liste, ajout, edit/delete de ses entrées), totaux logged / estimated / remaining.
- Les opérations vivent dans des Actions réutilisables en `AsObject` — prêtes à devenir des outils MCP dans la change `mcp-access`.

**Non-Goals:**

- Rollups projet, santé/avancement (change `project-health`).
- Exposition MCP (change `mcp-access`) — les actions créées ici en seront les points d'entrée, sans duplication.
- Feuilles de temps personnelles, exports, rapports.
- Timers/sessions horlogées, dates début/fin.
- Écriture d'estimations/entrées par des non-membres (le scoping tenant interdit déjà l'accès).

## Decisions

### ADR-1 : Estimation — colonne `issues.estimated_hours` nullable

**Décision** : `estimated_hours` en `DECIMAL(6,2)` nullable directement sur `issues`. Éditable par tout membre du workspace ; mettre à null = "pas d'estimation". Pas de validation de cohérence avec les heures logguées (le remaining peut être négatif — c'est de l'information, pas une erreur).

**Alternatives** : table séparée `estimates` historisées (complexité prématurée — on veut un chiffre "en gros") ; minutes entières (friction de saisie pour des estimations volontairement approximatives) ; estimation sur les projets ou par assigné (hors scope produit actuel).

**Justification** : une seule valeur par issue, lue à chaque détail d'issue — une colonne évite toute jointure. DECIMAL et pas FLOAT pour éviter les surprises d'arrondi à l'agrégation. Nullable plutôt que 0 pour distinguer "pas estimé" de "estimé à 0".

### ADR-2 : Table `time_entries` — une ligne par saisie

**Décision** : table `time_entries` : `id` (ULID), `workspace_id` (FK, scoping tenant), `issue_id` (FK), `author_id` (FK vers users, l'utilisateur qui loggue), `worked_at` (DATE — jour travaillé, défaut : jour courant, dates passées acceptées, pas de contrainte sur le futur), `hours` (`DECIMAL(6,2)`, contrainte CHECK `hours > 0`), `note` (nullable), timestamps standard. Index sur (`issue_id`), (`workspace_id`, `author_id`).

**Alternatives** : agrégat par (issue, auteur, date) mis à jour à chaque saisie (perd l'historique des saisies et complique l'édition par entrée) ; `minutes INT` (même friction que ADR-1) ; colonne `created_at` comme date travaillée (interdit de logguer après coup un jour précédent).

**Justification** : une saisie = une ligne = un objet éditable/supprimable par son auteur, exactement comme un commentaire. Le CHECK `hours > 0` redouble la validation applicative pour protéger l'invariant si une écriture hors action survenait.

### ADR-3 : Scoping et permissions — parité commentaires

**Décision** : `TimeEntry` porte le trait `BelongsToWorkspace` (global scope + FK). Autorisations : `CreateTimeEntry` — tout membre du workspace courant ; `UpdateTimeEntry` / `DeleteTimeEntry` — auteur de l'entrée uniquement (owner du workspace n'inclut pas de passe-droit : parité stricte avec les commentaires). L'issue cible est résolue dans le workspace courant ; une entrée d'un autre workspace est invisible et introuvable par ID.

**Justification** : réutiliser les conventions existantes plutôt que d'inventer un régime de permission spécifique au temps. L'invariant "l'auteur et personne d'autre" est le même que pour les commentaires — pas de raison produit de diverger.

### ADR-4 : Totaux calculés à la lecture, jamais stockés

**Décision** : aucun total en base. `logged_hours` = `SUM(time_entries.hours)` par issue (relation `timeEntries()` + agrégat Eloquent ou colonne calculée via `withSum`), `estimated_hours` lu sur l'issue, `remaining_hours` = estimate − logged, calculé côté Domain (accessor) ou Responder. Le remaining peut être négatif (surconsommation) et l'estimation absente (remaining indisponible).

**Alternatives** : colonnes `logged_hours` / `remaining_hours` maintenues par événements (dérive inévitable entre écritures concurrentes, code de synchronisation pour rien) ; vue SQL (couplage SGBD prématuré).

**Justification** : à l'échelle d'une issue (dizaines d'entrées au plus), le calcul à la lecture est trivial ; ne rien stocker supprime toute classe de bug de cohérence. Les rollups projet (change `project-health`) décideront séparatement de leur stratégie d'agrégation.

### ADR-5 : Actions ADR — une classe par opération

**Décision** : quatre actions dans `app/Actions/TimeTracking/`, chacune `AsObject` + `AsController` avec `rules()` + `authorize()` + `handle()` :

- `SetIssueEstimate` — reçoit `issue` + `estimated_hours` (nullable pour réinitialiser) ; remplace `UpdateIssue` pour ce champ afin de garder une seule responsabilité par action.
- `CreateTimeEntry` — reçoit `issue` + `hours` + `worked_at` (défaut : aujourd'hui) + `note` ; l'auteur est l'utilisateur courant.
- `UpdateTimeEntry` — auteur only ; heures/date/note.
- `DeleteTimeEntry` — auteur only.

Routes nommées préfixées par issue (`issues.{id}.estimate`, `issues.{id}.time-entries`, `time-entries.{id}`) ; Responders dans `app/Responders/TimeTracking/` pour les réponses Inertia/redirect. Validation : `hours` numeric > 0 (`min:0.01`), `worked_at` date, `note` nullable string bornée, `estimated_hours` numeric ≥ 0 nullable.

**Alternatives** : étendre `UpdateIssue` avec `estimated_hours` (action qui grossit, règles de validation conditionnelles de plus en plus larges) ; contrôleur CRUD resource (logique non réutilisable par MCP).

**Justification** : mêmes conventions que les actions commentaires ; chaque action est directement mappable à un outil MCP (`log_time`, `set_estimate`…) dans la change `mcp-access`.

### ADR-6 : UI — intégrée à la vue détail issue

**Décision** : pas de page dédiée. La vue détail issue gagne : l'estimation en champ affiché avec édition via dialog (comme les autres métadonnées), une section "Time" listant les entrées (auteur, date, heures, note) avec actions edit/delete uniquement sur les siennes, un formulaire d'ajout (heures + note + date, date préremplie à aujourd'hui), et la ligne "logged / estimated". React TypeScript + shadcn/ui existants ; aucune nouvelle dépendance front.

**Justification** : le temps se lit et se saisit dans le contexte de l'issue — une page séparée ajouterait de la navigation pour aucun gain. Réutiliser les composants `dialog`, `input`, `table`, `badge` déjà en place.

## Risks / Trade-offs

- [Fuite inter-tenant via une entrée accédée par ID] → global scope `BelongsToWorkspace` sur `TimeEntry` + test "cross-workspace access denied" sur edit/delete.
- [Agrégat logged par issue qui coûte en perf à grande échelle] → négligeable par issue ; les rollups projet (change `project-health`) prévoiront leur propre agrégation si nécessaire.
- [DECIMAL et somme en float côté PHP] → caster proprement (Eloquent decimal cast) et calculer les totaux en SQL (`SUM`) pour éviter les arrondis flottants.
- [Estimation éditable par n'importe quel membre] → choix produit assumé (outil d'équipe légère, pas de gating) ; si besoin, un régime de permission viendra avec la change permissions éventuelle.
- [Divergence "restant" stocké vs réel] → éliminée par construction (ADR-4 : rien n'est stocké).

## Migration Plan

Deux migrations additives, sans downtime, réversibles :

1. `issues` : ajout de `estimated_hours` (nullable) — rollback : drop column.
2. Création de `time_entries` avec FK vers `workspaces`, `issues`, `users` — rollback : drop table.

Aucune donnée existante impactée ; les issues existantes sont simplement "non estimées" et sans temps loggué.

## Open Questions

- Faut-il interdire `worked_at` dans le futur (saisie anticipée) ? — décision actuelle : pas de contrainte (simplicité) ; à revisiter si des données aberrantes apparaissent.
- Bornes de saisie : plafond d'heures par entrée (ex. 24 h/jour) non imposé pour l'instant ; une validation `max` léger pourra être ajoutée sans migration.
