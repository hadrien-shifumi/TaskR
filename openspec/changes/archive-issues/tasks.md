## 1. Fondation : colonne `archived_at` + scopes du modèle Issue — issue `01m5cc49mpd4gbkgf72znge0zd` (1/6)

- [x] 1.1 Migration : `archived_at` (timestamp nullable) + index sur `issues` ; aucune donnée migrée (toutes les issues existantes restent actives)
- [x] 1.2 Modèle `Issue` : cast `datetime:archived_at`, scopes `unarchived()` / `archived()`, helper `isArchived()` — miroir exact de `Project` (`app/Models/Project.php`)
- [x] 1.3 Tests feature : une issue fraîchement créée est `unarchived()` ; `archived()` ne retourne que les explicitement archivées ; `isArchived()` cohérent avec la colonne

## 2. Archivage unitaire : toggle owner-only avec cascade — issue `01m5cc4a60sz0yb2d7j1q4jhjx` (2/6)

- [x] 2.1 Action `ArchiveIssue` (`AsObject` + `AsController`, `AuthorizesWorkspaceAccess`) : toggle `archived_at` ; **owner-only** (member → refus, contrôle masqué côté UI) ; archiver exige un statut de catégorie `done`/`cancelled` (sinon 409 explicite invitant à clore l'issue) ; désarchiver toujours permis
- [x] 2.2 Cascade : archiver un parent archive récursivement ses enfants non archivés ; désarchiver désarchive récursivement les enfants archivés ; invariant « un enfant n'est jamais plus archivé que son parent » garanti par construction
- [x] 2.3 Résolution périmètre : issue d'un autre workspace introuvable par identifiant direct (global scope existant), pas de fuite d'existence
- [x] 2.4 Route nommée + responder dédié (pattern des actions Issues existantes)
- [x] 2.5 Tests feature : owner archive/désarchive une done ; member refusé ; issue `in_progress` refusée avec erreur explicite ; cascade parent→enfants à l'archivage et au restore ; statut `cancelled` éligible

## 3. Archivage en masse des done/cancelled depuis le board — issue `01m5cc4aqjvcz0xqfd4hwpx8f6` (3/6)

- [x] 3.1 Action `ArchiveCompletedIssues` (`AsObject` + `AsController`) : owner-only, périmètre projet, archive en une transaction toutes les `unarchived()` du projet en statut `done`/`cancelled`, retourne le nombre archivé
- [x] 3.2 UI board : bouton « Archiver les terminées » (owner-only, masqué pour member) avec dialogue de confirmation affichant le nombre d'issues concernées ; message de succès avec le compte
- [x] 3.3 Tests feature : masse sur un projet mixte n'archive que les done/cancelled ; member refusé ; projet vide en done/cancelled → aucune action / message « rien à archiver » ; composition avec la cascade (parents done archivés emportent leurs enfants)

## 4. Exclure les archivées des vues et chargements actifs — issue `01m5cc4b6ts8kpy8h32ap4mzc8` (4/6)

- [x] 4.1 `ListIssues` et toutes les requêtes d'issues actives (board, liste cross-projet, santé projet, pickers parent/assignation) passent par `unarchived()` — un seul point de vérité
- [x] 4.2 Santé projet : les archivées sortent des compteurs, pourcentages et workloads, concordance board/santé préservée
- [x] 4.3 Fiche issue archivée : toujours consultable à son URL (indicateur « Archivée » visible), commentaires et temps lisibles, liens hiérarchiques intacts ; un parent actif ne référence jamais d'enfants archivés (vérifié en test)
- [x] 4.4 Tests feature : board sans archivées ; `ListIssues` défaut sans archivées ; santé projet recalculée sans les archivées ; fiche archivée accessible ; hiérarchie active↔archivée impossible par construction

## 5. Vue « Archives » par projet — issue `01m5cc4bn4wncrq2nkzvs0m9tn` (5/6)

- [x] 5.1 Page Inertia « Archives » par projet : liste des issues archivées du projet (titre, statut, catégorie, date d'archivage), triée par date d'archivage décroissante, alimentée via `ListIssues` avec `archived=only&project=…` ; état vide explicite
- [x] 5.2 Bouton « Désarchiver » par ligne (owner-only, masqué pour member) ; pas de déarchivage en masse
- [x] 5.3 Entrée de navigation vers les archives du projet depuis le board/projet
- [x] 5.4 Tests feature : archives d'un projet ne montrent que ses issues archivées ; désarchivation depuis la vue ramène l'issue dans les vues actives ; isolation par workspace/périmètre guest ; tests browser (Pest) du parcours archive → vue Archives → désarchivage

## 6. MCP `list_issues` : paramètre `archived` (exclude/include/only) — issue `01m5cc4c42gk011fpytc7tz2y1` (6/6)

- [x] 6.1 Action `ListIssues` : paramètre `archived` à trois états (`exclude` défaut / `include` / `only`), composé avec tous les filtres existants
- [x] 6.2 Outil MCP `list_issues` : expose `archived` avec `exclude` en défaut — contrat inchangé pour les appelants qui ne passent pas le paramètre ; description mise à jour guidant l'accès historique
- [x] 6.3 Tests feature MCP : sans paramètre → issues actives seulement ; `archived=include` → tout ; `archived=only` → historique seul ; refus inter-workspace inchangé ; isolation guest respectée dans les trois modes

## 7. Vérification finale

- [ ] 7.1 Suite complète au vert : unit + feature + browser (`php artisan test`)
- [ ] 7.2 `vendor/bin/pint --dirty --format agent` au propre
- [ ] 7.3 Revue du pattern : requêtes DB dans les Actions uniquement, aucun `Inertia::render` hors Responder, aucune logique métier dans `app/Mcp/`
