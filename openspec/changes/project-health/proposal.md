> **Ordre d'implémentation conseillé : 2/4** — après `time-tracking`, son prérequis fort (estimations + time entries). `templates` est indépendante et peut être intercalée avant.

## Why

Les managers Taskr n'ont aujourd'hui aucun moyen simple de suivre la tenue d'un projet : savoir s'il progresse bien, s'il est en retard ou s'il risque de prendre du retard exige de parcourir les issues une par une. Le besoin est du pilotage par convention — pas des sprints, pas de workflow custom : des indicateurs calculés à partir des données déjà saisies.

## What Changes

- Ajout d'une **date cible** (`target_date`, nullable, date) sur les projets : la "due date de projet", repoussée depuis foundation.
- Page **Health** par projet et vue d'ensemble sur la page projets, avec des indicateurs **toujours calculés, jamais stockés ni saisis** :
  - nombre d'issues par catégorie de statut (backlog / in_progress / done / cancelled) ;
  - pourcentage terminé = (issues done + cancelled) / total ;
  - nombre d'issues en retard (due date dépassée, hors done/cancelled) ;
  - ratio de consommation des heures : heures logguées / heures estimées (appui sur la change `time-tracking`) ;
  - projection simple de retard : statut `ok` / `at-risk` / `late` calculé en comparant le pourcentage terminé au pourcentage attendu à date (formule explicite, documentée dans la spec).
- Visibilité : **tout membre du workspace** peut consulter la santé d'un projet (aucune restriction de rôle).
- Les mêmes indicateurs sont exposés en vue d'ensemble sur la liste des projets (badge de statut + pourcentage terminé).

Hors périmètre (volontairement) : accès MCP (change `mcp-access`), templates d'issues (change `templates`), sprints, vélocité, burndown charts, alertes/notifications, indicateurs custom.

**Dépendance explicite** : cette change **requires la change `time-tracking`** (estimations d'effort par issue et time entries). Le ratio de consommation et la projection reposent sur ces données ; l'implémentation de `project-health` viendra après celle de `time-tracking`.

## Capabilities

### New Capabilities
- `project-health`: suivi de la santé des projets — date cible de projet, indicateurs calculés (avancement, retards, consommation des heures, projection de retard) et pages de consultation associées.

### Modified Capabilities
- Néant — la spec `project-management` n'est pas modifiée au niveau requirements ; l'ajout de `target_date` est couvert par la nouvelle capacité `project-health`.

## Impact

- Base de données : migration unique ajoutant `projects.target_date` (nullable, date).
- Domaine : aucune nouvelle table — la santé est un calcul pur au-dessus d'`issues`, des catégories de statut, des `due_dates`, et des estimations/time entries issus de `time-tracking`.
- Actions : nouvelle action `GetProjectHealth` (pattern ADR, `AsObject` + `AsController`), réutilisable telle quelle par les outils MCP de la change `mcp-access`.
- UI : page Health par projet (Inertia + React + shadcn/ui), enrichissement de la liste des projets ; formulaire d'édition de projet étendu avec `target_date`.
- Aucune donnée persistée supplémentaire pour les indicateurs : pas de table de santé, pas de cache à invalider.
