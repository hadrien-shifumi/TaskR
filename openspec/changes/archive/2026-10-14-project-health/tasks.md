## 1. Migration et modèle

- [x] 1.1 Vérifier le prérequis : change `time-tracking` implémentée (estimations d'issues + time entries) et aligner les noms de champs/actions réels sur les tâches ci-dessous
- [x] 1.2 Migration : `projects.target_date` (nullable, date), migration additive sans index
- [x] 1.3 Modèle `Project` : cast `date` sur `target_date`
- [x] 1.4 Étendre les actions de création/édition de projet avec la règle de validation `date` (nullable) pour `target_date`
- [x] 1.5 Formulaire UI de création/édition de projet : champ date cible

## 2. Action de calcul de santé

- [x] 2.1 Créer le DTO `ProjectHealth` (compteurs par catégorie, pourcentage terminé, nombre d'issues en retard, heures estimées/logguées, ratio de consommation, pourcentage attendu à date, statut `ok`/`at-risk`/`late` ou absence de projection)
- [x] 2.2 Créer l'action `GetProjectHealth` (`AsObject` + `AsController`, `app/Actions/Projects/`) : agrégats SQL groupés (count par `status.category`, sum des estimations et des time entries, count des issues en retard), aucune collection d'issues chargée en mémoire
- [x] 2.3 Implémenter la formule de projection de l'ADR-3 : `attendu = clamp((aujourd'hui - created_at) / (target_date - created_at), 0, 1)` ; statuts `late` / `at-risk` / `ok` ; cas limites : sans `target_date` (pas de projection), sans issue (compteurs 0, `ok`), `target_date` <= `created_at` (attendu = 1)
- [x] 2.4 Créer la passe groupée par `project_id` pour l'ensemble des projets du workspace (mêmes agrégats en une requête), réutilisable par la liste des projets

## 3. Routes et Responders

- [x] 3.1 Route `GET /projects/{project}/health` mappée sur `GetProjectHealth` (`AsController`), nommée, autorisation par appartenance au workspace (aucune gate de rôle)
- [x] 3.2 Responder Health : transformation du DTO `ProjectHealth` en page Inertia
- [x] 3.3 Enrichir le responder de la liste des projets avec la passe groupée (statut de projection + pourcentage terminé par projet)

## 4. UI

- [x] 4.1 Page Health par projet (Inertia + React + shadcn/ui) : compteurs par catégorie, pourcentage terminé vs pourcentage attendu à date, issues en retard, totaux et ratio de consommation des heures, badge de statut avec `target_date`
- [x] 4.2 Liste des projets : badge de statut (`ok`/`at-risk`/`late`) + pourcentage terminé par projet ; sans badge si pas de `target_date`
- [x] 4.3 Masquer le ratio de consommation quand le total estimé du projet est nul

## 5. Tests

- [x] 5.1 Tests de la formule de projection : `ok`, `at-risk`, `late`, `late` levé si `target_date` passée sans achèvement, `ok` si `target_date` passée et 100 % terminé, clamp aux bornes, sans `target_date`, projet sans issue, `target_date` <= `created_at`
- [x] 5.2 Tests des indicateurs avec fixtures : répartition par catégorie (done + cancelled comptés au numérateur), issues en retard (due date dépassée hors done/cancelled), ratio de consommation (8 logguées / 10 estimées = 80 %, absent si aucune estimation)
- [x] 5.3 Tests feature : recalcul à la lecture (issue passée à done change le pourcentage sans action différée), consultation par un membre simple autorisée, accès inter-workspace refusé
- [x] 5.4 Test feature : vue d'ensemble de la liste des projets (badges et pourcentages corrects pour plusieurs projets en une requête groupée)

## 6. Vérification finale

- [x] 6.1 Passe complète de tests au vert (`php artisan test --compact`) — 89/89 tests passent
- [x] 6.2 `vendor/bin/pint --dirty --format agent` sur les fichiers PHP modifiés
- [x] 6.3 Dogfood minimal : créer un projet avec `target_date`, des issues réparties (dont une en retard et des estimations/time entries), consulter la page Health et la liste des projets, vérifier la cohérence des indicateurs avec la formule
- [x] 6.4 Revue du respect du pattern ADR : calcul dans l'action (`GetProjectHealth`), aucun `Inertia::render` hors des Responders (`ProjectHealthResponder`), aucun agrégat dans les composants React
