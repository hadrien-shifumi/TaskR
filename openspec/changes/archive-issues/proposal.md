## Why

Le board et les vues transverses accumulent au fil du temps des issues terminées (« done ») ou annulées (« cancelled ») qui ne sont plus actives mais restent affichées, chargées et comptabilisées partout. L'archivage réversible existe déjà pour les projets (`archived_at` + toggle `ArchiveProject`) ; les issues n'ont rien d'équivalent. Le besoin : sortir les issues closes du champ de vision sans les supprimer (l'historique reste requêtable et consultable), et rendre l'opération accessible aux humains (UI) et aux agents (MCP).

## What Changes

- Fondation : colonne `archived_at` nullable sur `issues` + scopes `unarchived`/`archived` et helper `isArchived()` sur le modèle `Issue`, miroir exact de `Project`.
- Archivage unitaire réversible : action `ArchiveIssue` en toggle, réservée au **Owner** du workspace, refusée si le statut de l'issue n'est pas de catégorie `done` ou `cancelled`, avec **cascade** : les enfants non archivés de l'issue sont archivés en même temps (et désarchivés ensemble au restore — un enfant n'est jamais plus archivé que son parent).
- Exclusion par défaut : les issues archivées disparaissent des vues actives (board, liste cross-projet, santé projet, recherche sémantique d'issues le cas échéant) et des chargements actifs, sans casser les liens existants (l'issue reste consultable à son URL, les parents/enfants restent joignables depuis les fiches non archivées).
- UI « Archives » : vue par projet listant les issues archivées du projet avec date d'archivage et bouton « Désarchiver » (owner-only) ; entrée dédiée, pas de déarchivage en masse en v1.
- Archivage en masse : depuis le board, un owner peut archiver en une action toutes les issues `done`/`cancelled` d'un projet (confirmation explicite, pas de limite de statut).
- MCP : `list_issues` gagne un paramètre `archived` (`exclude` par défaut / `include` / `only`) pour que les agents continuent de voir le présent par défaut et requêtent l'historique explicites ; pas de nouvel outil d'archivage en v1 (l'archivage est une opération de gestion humaine, comme la suppression des issues via MCP restera possible via `delete_issue`).

Hors périmètre (volontairement) : archivage des commentaires/temps, purge automatique (les issues archivées restent indefinitely), archivage en masse par statut au-delà du périmètre projet, archivage via MCP, comptabilisation des archivées dans la santé projet.

## Capabilities

### Modified Capabilities

- `issue-tracking` : cycle de vie élargi — une issue close MAY être archivée (owner-only, cascade sur les enfants), l'archivage est réversible, les listes et vues actives excluent les archivées par défaut.
- `web-interface` : nouvelle vue « Archives » par projet, action d'archivage unitaire depuis la fiche/board, action d'archivage en masse des `done`/`cancelled` d'un projet depuis le board, contrôles owner-only masqués pour les members.
- `mcp-access` : `list_issues` gagne le paramètre `archived` (`exclude`/`include`/`only`, défaut `exclude`) ; les autres outils MCP restent inchangés (les archivées restent lisibles/mouvables via leurs outils dédiés, jamais par des chemins d'existence).

## Impact

- Base de données : migration ajoutant `archived_at` (timestamp nullable, index) à `issues` ; aucune migration de données (toutes les issues existantes restent actives).
- Backend : scopes + helper sur `Issue` ; actions `ArchiveIssue` (toggle + cascade) et `ArchiveCompletedIssues` (masse, périmètre projet) dans `app/Actions/Issues/` ; `ListIssues` étendu pour composer le filtre `archived` ; intégration aux requêtes du board, des vues transverses et de la santé projet.
- MCP : `app/Mcp/Tools/ListIssues.php` uniquement (paramètre + description) ; aucun nouvel outil.
- Frontend : section Archives par projet, boutons d'archivage (fiche issue, board), dialogue d'archivage en masse avec confirmation.
- Permissions : archivage et désarchivage réservés au Owner du workspace ; l'accès en lecture des archivées suit les règles de périmètre existantes.
