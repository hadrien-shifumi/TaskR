## Why

Dans Taskr, la création d'issues est entièrement libre : chaque membre rédige à sa manière, et des tickets de même nature (bug, tâche de développement, analyse fonctionnelle) finissent avec des formes hétérogènes — informations manquantes, structure différente. Les gabarits (templates) permettent de standardiser la forme des issues par nature de ticket : un bug doit toujours se ressembler, une tâche de développement également.

## What Changes

- Nouvelle entité **gabarit d'issue** au niveau du workspace : nom (unique par workspace), corps de description Markdown (le contenu gabarit), priorité par défaut optionnelle, labels par défaut optionnels.
- **Gabarits non bloquants** : l'application d'un gabarit pré-remplit l'issue à la création (description, priorité, labels) sans jamais contraindre — toute valeur explicite prime sur le gabarit, et l'issue reste entièrement éditable après création.
- CRUD des gabarits réservé aux `owner` (même convention que les statuts) ; l'application à la création d'issue est ouverte à tout membre du workspace.
- UI : page de gestion des gabarits dans les réglages du workspace (Settings/Templates) et sélecteur « Template » optionnel dans le dialogue de création d'issue.
- Pas de persistance du lien issue → gabarit : les issues existantes ne dépendent jamais d'un gabarit (suppression ou édition d'un gabarit n'affecte aucune issue existante).

Hors périmètre (volontairement, changes ultérieures) : exposition des gabarits aux agents MCP (change `mcp-access`), application rétroactive ou à l'édition d'une issue existante, time tracking.

## Capabilities

### New Capabilities

- `templates`: gabarits d'issues définis au niveau du workspace, non bloquants — définition (owner only), application à la création (tout membre), pré-remplissage sans contrainte.

### Modified Capabilities

(néant — l'application d'un gabarit est un pré-remplissage à la création d'issue ; aucune exigence existante d'`issue-tracking` n'est modifiée)

## Impact

- Base de données : nouvelle migration `issue_templates` (workspace_id, name, description, priority) + table pivot `issue_template_labels` ; contrainte d'unicité (workspace_id, name).
- Backend : modèle `IssueTemplate` (ULID, scoping workspace via `BelongsToWorkspace`), nouvelles actions dans `app/Actions/Templates/` (CRUD, owner only) avec responders dédiés, extension de `CreateIssue` pour accepter un gabarit optionnel et fusionner ses valeurs par défaut.
- Frontend : nouvelle page `Settings/Templates` (liste, création, édition, suppression), sélecteur de gabarit dans le dialogue de création d'issue avec pré-remplissage client.
- Aucune nouvelle dépendance ; réutilise les labels, la priorité et les conventions ADR existantes. Les actions CRUD créées ici seront les points d'entrée naturels des outils MCP de la change `mcp-access`.
