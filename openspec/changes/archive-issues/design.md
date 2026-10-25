## Context

Taskr isole chaque workspace (trait `BelongsToWorkspace` + global scope) et le périmètre d'accès des guests (`WorkspaceContext::abortUnlessProjectAccessible`). Les projets disposent déjà d'un archivage réversible : colonne `archived_at`, scopes `scopeActive`/`scopeArchived` (via `whereNull`/`whereNotNull`), helper `isArchived()`, toggle `ArchiveProject` autorisé pour tout membre à qui le projet est accessible, et la suppression définitive d'un projet est refusée tant qu'il contient des issues (« archive it instead of deleting »). Les issues ont une hiérarchie molle parent/enfant (`SetIssueParent`, prévention de cycles, `ancestorIds()`), des statuts à catégorie sémantique fixe (backlog / in_progress / done / cancelled) et une liste cross-projet filtrable (`ListIssues`). Côté MCP, `list_issues` (`app/Mcp/Tools/ListIssues.php`) délègue à l'action `ListIssues` et sérialise ; jamais de logique métier dans `app/Mcp/`.

Décisions produit actées :

- L'archivage est **réversible** — pas de suppression, pas de purge automatique.
- L'archivage est réservé au **Owner** du workspace (contrairement aux projets) : c'est une opération de gestion du workspace, pas une action de rang.
- Condition d'éligibilité : le statut de l'issue doit être de catégorie `done` ou `cancelled`.
- **Cascade** sur les enfants : archiver un parent archive les enfants non archivés ; désarchiver restaure l'ensemble.

## Goals / Non-Goals

**Goals:**

- Sortir les issues closes des vues et chargements actifs, sans perte d'historique ni rupture de liens.
- Une seule définition de « archivé » (`archived_at`) réutilisée par le modèle, les actions, l'UI et MCP.
- Un parcours UI complet : archivage unitaire, vue Archives par projet, désarchivation, archivage en masse des done/cancelled d'un projet.
- Un contrat MCP inchangé par défaut : `list_issues` continue de renvoyer les issues actives ; l'historique archivé est requêtable explicitement.

**Non-Goals:**

- Archivage via MCP (pas de nouvel outil ; `delete_issue` reste le seul canal destructif d'agent).
- Déarchivage en masse, archivage en masse au-delà du périmètre projet, purge planifiée.
- Comptabilisation des issues archivées dans la santé projet (elles sortent des indicateurs comme des vues).
- Archivage des commentaires ou time entries attachés aux issues archivées (ils restent consultables sur la fiche).

## Decisions

### ADR-1 : Colonne `archived_at` + scopes, miroir exact de `Project`

**Décision** : migration ajoutant `archived_at` (timestamp nullable) + index à `issues`. Le modèle `Issue` gagne le cast `datetime`, les scopes `scopeUnarchived` (`whereNull('archived_at')`) et `scopeArchived` (`whereNotNull('archived_at')`) et le helper `isArchived()`, à l'identique de `Project`. Toutes les requêtes d'issues actives passent par `unarchived()` — un seul point de vérité. Aucune donnée migrée : les issues existantes restent actives par construction.

**Alternatives rejetées** : statut dédié « Archived » (pollue la catégorie sémantique et le board, mélange cycle de vie et rangement) ; table `archived_issues` séparée (casse liens, requêtes et ULID).

### ADR-2 : Action `ArchiveIssue` — toggle owner-only, condition de catégorie, cascade sur les enfants

**Décision** : action `AsObject` + `AsController` dans `app/Actions/Issues/`, pattern `AuthorizesWorkspaceAccess`. Quatre règles :

1. **Autorisation** : Owner du workspace uniquement (contrairement à `ArchiveProject` qui l'ouvre au périmètre) — l'archivage est une opération de gestion. Un member reçoit le même refus que toute action owner-only.
2. **Éligibilité** : archiver exige un statut de catégorie `done` ou `cancelled` (un 409 explicite sinon, message invitant à clore l'issue d'abord). Désarchiver est toujours permis (restore d'un état, pas d'un changement de fond).
3. **Cascade** : archiver une issue archive récursivement ses enfants non archivés (un enfant actif sous un parent archivé est une incohérence). Désarchiver désarchive récursivement les enfants **qui ont été archivés par la même vague** : chaque issue enregistre la cascade via le parent — en v1, la désarchivation d'un parent désarchive tous ses enfants archivés (le parent redevient la racine d'un sous-arbre actif). L'invariant garanti : **un enfant n'est jamais plus archivé que son parent**.
4. **Périmètre** : résolution route-model binding + global scope existants ; une issue d'un autre workspace est introuvable par identifiant direct, sans fuite d'existence.

**Alternatives rejetées** : cascade au niveau SQL (masque la récursion de désarchivage et le refus conditionnel) ; refus d'archiver un parent ayant des enfants actifs (friction inutile, la cascade est le comportement attendu).

### ADR-3 : Exclusion par défaut de toutes les vues et chargements actifs

**Décision** : chaque requête qui alimente le présent — board kanban (`ShowBoardPage` équivalent), liste cross-projet (`ListIssues`), santé projet (`GetProjectHealth`), compteurs, menus de sélection d'issues (parent picker, assignation) — applique `unarchived()`. La fiche issue d'une archivée reste **consultable** à son URL (statut « Archivée » visible), les commentaires et temps restent lisibles, les liens parent/enfant depuis une fiche active continuent de fonctionner si la cible est archivée (la cascade a archivé les sous-arbres entiers, donc un enfant actif ne référence jamais un parent archivé). Les issues archivées **sortent** de la santé projet (indicateurs, pourcentages, workloads) au même titre que les vues.

**Alternatives rejetées** : flag global dans `ListIssues` uniquement (laisserait fuir les archivées dans board/santé/pickers) ; suppression des relations vers les archivées (casse la navigation et l'historique).

### ADR-4 : `ListIssues` unifié — paramètre `archived` (exclude/include/only)

**Décision** : l'action `ListIssues` gagne un paramètre d'énumération à trois états : `exclude` (défaut — comportement inchangé), `include` (toutes), `only` (uniquement les archivées). Ce paramètre se compose avec les filtres existants (projet, statut, catégorie, priorité, labels, assigné, overdue). L'UI « Archives » par projet l'utilise avec `archived=only&project=…`. MCP `list_issues` expose le même paramètre sous le nom `archived` avec `exclude` en défaut : **le contrat MCP existant est inchangé pour tout appelant qui ne passe pas le paramètre**.

**Alternatives rejetées** : booléen `include_archived` (ne permet pas de requêter l'historique seul) ; outil MCP séparé `list_archived_issues` (duplication de tous les filtres pour un seul différenciateur).

### ADR-5 : Archivage en masse — action dédiée par projet, confirmation UI

**Décision** : action `ArchiveCompletedIssues` (`AsObject` + `AsController`) : owner-only, périmètre projet, archive toutes les `unarchived()` issues du projet dont le statut est `done`/`cancelled`, en une transaction. Retourne le nombre d'issues archivées pour le message de confirmation. Le board expose un bouton « Archiver les terminées » (owner-only) avec dialogue de confirmation indiquant le nombre d'issues concernées ; pas d'archivage en masse cross-projet ni de masse de désarchivage en v1.

**Alternatives rejetées** : commande artisan dédiée (pas de besoin d'ops exprimé, l'UI porte le besoin) ; exécution asynchrone par job (volume par projet trop faible pour justifier un job et son suivi).

## Risks / Trade-offs / Open Questions

- **Risque de cohérence sous-arbre** : un guest dont le périmètre couvre le parent mais pas un enfant — la cascade owner-only ne rencontre pas ce cas (l'owner voit tout le workspace). L'invariant enfant ≤ parent est garanti par construction (ADR-2), pas de voie UI/MCP ne permet de l'enfreindre.
- **MCP sans archivage** : un agent ne peut pas archiver ; c'est volontaire (opération de gestion humaine), mais un `archived: only` via `list_issues` lui donne l'accès historique complet.
- **Open question (v1 tranchée)** : faut-il afficher un badge « enfants archivés » sur un parent actif ? Non : par l'invariant, un parent actif n'a jamais d'enfants archivés (toute sa descendance est active). La question ne se pose qu'à l'inverse — badge « parent archivé » impossible aussi. Aucun badge d'état hiérarchique nécessaire.
