## Why

Taskr permet aujourd'hui de décrire et suivre le statut des issues, mais pas le temps : ni le temps passé, ni une estimation grossière. Les membres qui travaillent sur une issue n'ont aucun moyen de reporter leurs heures, et personne ne peut voir si une issue est en surconsommation. C'est la brique manquante pour que la vue détail d'une issue reflète l'effort réel, et le prérequis des rollups projet (reportés à la change `project-health`).

## What Changes

- Estimation sur les issues : nouvelle colonne `estimated_hours` (décimal, nullable), éditable par tout membre du workspace. Heures décimales, "en gros" — pas de dates de début/fin, pas de découpage en sessions.
- Time entries : nouvelle table `time_entries` rattachée à une issue — auteur (membre qui loggue), date du jour travaillé, heures décimales strictement positives, note optionnelle.
- Un membre loggue du temps sur une issue du workspace ; édition et suppression d'une entrée réservées à son auteur (même règle que les commentaires).
- Totaux calculés sur l'issue : heures logguées (somme des entrées), estimées, restant (estimate − logged, calculé à la lecture, jamais stocké).
- UI : dans la vue détail issue — champ estimation (affichage + edit dialog), section "Time" avec la liste des entrées (auteur, date, heures, note), formulaire d'ajout (heures + note + date), edit/delete sur ses propres entrées, et l'affichage "logged / estimated".

Hors périmètre (volontairement, changes ultérieures) : rollups et santé projet (change `project-health`), exposition MCP des actions (change `mcp-access` — les actions créées ici seront les points d'entrée naturels des outils MCP de time tracking, sans duplication de logique), templates.

## Capabilities

### New Capabilities
- `time-tracking`: estimation en heures décimales sur les issues, saisie/gestion des time entries par les membres, totaux loggué/estimé/restant au niveau de l'issue.

### Modified Capabilities

(néant — les comportements de `issue-tracking` existants sont inchangés ; l'estimation et le temps sont des ajouts, spécifiés dans `time-tracking`)

## Impact

- Base de données : nouvelle colonne `issues.estimated_hours` (décimal, nullable), nouvelle table `time_entries` (`workspace_id` pour le scoping tenant, `issue_id`, `author_id`, `worked_at`, `hours`, `note` nullable).
- Domaine : nouveau modèle `TimeEntry` portant `BelongsToWorkspace` (global scope) ; relation `timeEntries` sur `Issue` ; pas de relation inverse stockée ailleurs que sur l'entrée.
- Actions ADR : `SetIssueEstimate`, `CreateTimeEntry`, `UpdateTimeEntry`, `DeleteTimeEntry` (une classe par opération, `AsObject` + `AsController`, `handle()` + `rules()` + `authorize()`) + Responders `TimeTracking`.
- UI Inertia : vue détail issue enrichie (champ estimation, section "Time"), edit dialog d'estimation, composants de saisie des heures.
- Les actions sont conçues dès maintenant pour être appelées en `AsObject` par la couche MCP de la change `mcp-access`.
