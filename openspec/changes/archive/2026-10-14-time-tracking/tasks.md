## 1. Migrations

- [x] 1.1 Migration : ajout de `estimated_hours` (`DECIMAL(6,2)`, nullable) sur `issues`
- [x] 1.2 Migration : table `time_entries` (ULID PK, `workspace_id` FK, `issue_id` FK, `author_id` FK, `worked_at` DATE, `hours` `DECIMAL(6,2)` avec CHECK `hours > 0`, `note` nullable, timestamps) + index (`issue_id`, (`workspace_id`, `author_id`))

## 2. Domaine

- [x] 2.1 Modèle `TimeEntry` avec trait `BelongsToWorkspace` (global scope), casts decimal/date, relations `issue()` et `author()` ; factory
- [x] 2.2 Relation `timeEntries()` sur `Issue` + accesseur de totaux : `logged_hours` (SUM des entrées), `remaining_hours` (estimate − logged, non calculable sans estimation) ; mise à jour du Responder de détail issue pour exposer estimation + entrées + totaux

## 3. Actions et Responders

- [x] 3.1 `SetIssueEstimate` : membre du workspace ; `estimated_hours` nullable, numeric ≥ 0 (null = réinitialisation)
- [x] 3.2 `CreateTimeEntry` : membre du workspace, auteur = utilisateur courant ; `hours` numeric > 0, `worked_at` date (défaut : aujourd'hui), `note` nullable
- [x] 3.3 `UpdateTimeEntry` : auteur only ; mêmes règles de validation
- [x] 3.4 `DeleteTimeEntry` : auteur only
- [x] 3.5 Routes nommées (`issues.{id}.estimate`, `issues.{id}.time-entries`, `time-entries.{id}`) + Responders `TimeTracking` (redirects Inertia après mutation)

## 4. UI

- [x] 4.1 Champ estimation dans la vue détail issue : affichage + edit dialog (comme les autres métadonnées), affichage "logged / estimated"
- [x] 4.2 Section "Time" dans la vue détail issue : liste des entrées (auteur, date, heures, note)
- [x] 4.3 Formulaire d'ajout d'entrée (heures + note + date préremplie à aujourd'hui) ; edit/delete uniquement sur ses propres entrées (composants React TS + shadcn/ui existants)

## 5. Tests

- [x] 5.1 Estimation : définir, réinitialiser, valeur négative rejetée, éditable par un simple member
- [x] 5.2 Time entries : ajout, 0/négatif rejeté, date par défaut = aujourd'hui, note optionnelle
- [x] 5.3 Permissions : édition/suppression par l'auteur OK, par un autre membre refusée (owner y compris)
- [x] 5.4 Isolation inter-tenants : issue d'un autre workspace introuvable, entrée cross-workspace inaccessible en edit/delete par ID
- [x] 5.5 Totaux : logged/estimated/remaining exacts, remaining négatif en surconsommation, issue sans estimation

## 6. Vérification finale

- [x] 6.1 Passe complète de tests au vert (`php artisan test --compact`), dont isolation inter-tenants — 67/67 tests passent
- [x] 6.2 `vendor/bin/pint --dirty --format agent`
- [x] 6.3 Revue du respect du pattern ADR : toute requête DB dans une Action, tout `Inertia::render` dans un Responder ; actions TimeTracking appelables en `AsObject` (prêtes pour la change `mcp-access`)
- [x] 6.4 Dogfood minimal : estimer une issue, logger du temps sur plusieurs jours, corriger une entrée, constater les totaux "logged / estimated" dans le détail de l'issue
