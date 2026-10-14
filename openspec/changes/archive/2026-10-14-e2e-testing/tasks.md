## 1. Socle navigateur

- [x] 1.1 Composer : retirer `phpunit/phpunit` des dépendances directes, ajouter `pestphp/pest` ^4, `pestphp/pest-plugin-browser` ^4, `pestphp/pest-plugin-laravel` ^4 (dev, `--with-all-dependencies`)
- [x] 1.2 npm : installer `playwright` + télécharger Chromium (`npx playwright install`)
- [x] 1.3 `tests/Pest.php` avec `uses()` par testsuite (Unit/Feature/Browser → `TestCase`, Feature/Browser + `RefreshDatabase`) ; testsuite `Browser` dans `phpunit.xml` ; vérifier que la suite existante passe sous `./vendor/bin/pest` sans aucune modification de test
- [x] 1.4 Smoke de validation : `visit('/login')` → formulaire visible + `assertNoSmoke()` — valide le boot serveur, le partage DB/session et les assets buildés
- [x] 1.5 `.gitignore` : `tests/Browser/Screenshots`, `tests/Browser/Traces` ; scripts composer : `test` via `pest` (`--testsuite=Unit,Feature`), `test:e2e` = `npm run build` + `pest --testsuite=Browser` ; vérifier `php artisan test` (détecte Pest, exécute les 90 tests)

## 2. Golden path

- [x] 2.1 Parcours inscription : remplir le formulaire → utilisateur authentifié, workspace créé avec les 4 statuts par défaut (vérifié via le domaine)
- [x] 2.2 Logout puis login par le formulaire
- [x] 2.3 Création d'un projet depuis l'UI, puis création d'une issue titre seul → statut Backlog visible dans la liste et le détail
- [x] 2.4 Détail issue : passage direct à Done, ajout puis édition et suppression d'un commentaire, sans erreur JS

## 3. Management & collaboration

- [x] 3.1 Statuts (owner) : créer "En revue" (in_progress), renommer, réordonner — ordre reflété dans l'UI
- [x] 3.2 Statuts : suppression protégée tant que des issues l'utilisent (message avec le nombre), tentative de création par un `member` refusée
- [x] 3.3 Invitation : owner invite Bob → récupération du token en base → Bob accepte via le lien → member du workspace, voit projets et issues
- [x] 3.4 Multi-workspace : bascule de workspace actif → seules les données du workspace actif sont affichées
- [x] 3.5 Time tracking : ajout d'une entrée (heures + note) depuis la section Time → totaux logged/estimated/restant mis à jour ; édition/suppression de ses entrées uniquement

## 4. Santé & smoke global

- [x] 4.1 Page Health : seed ciblé (issues dans chaque catégorie, due date dépassée, target date dépassée) → compteurs, pourcentage, issues en retard, ratio de consommation, statut de projection ; badge de statut sur la liste des projets
- [x] 4.2 Smoke toutes les pages, desktop + viewport mobile : `assertNoSmoke()` + `assertNoAccessibilityIssues()` (serious) ; triage et correction des défauts préexistants (ou documentation dans la change si arbitrage produit)

## 5. Vérification finale

- [x] 5.1 Suite complète au vert : `./vendor/bin/pest` (Unit + Feature inchangés : 89) puis `composer test:e2e` (Browser : 15)
- [x] 5.2 `vendor/bin/pint --dirty --format agent`
- [x] 5.3 Aucun fichier de test existant modifié ; modifications applicatives listées et justifiées (voir ci-dessous)
- [x] 5.4 Dogfood : `composer test:e2e` exécuté de bout en bout (build + 15 tests, ~8,5 s) ; en cas d'échec, la trace Playwright (`--trace`) permet de diagnostiquer

### Correctifs applicatifs révélés par la change (task 5.3)

Trois défauts préexistants ont été découverts et corrigés — aucun test existant modifié :

- `app/Http/Middleware/ResolveCurrentWorkspace.php` + `bootstrap/app.php` : une requête guest ne remettait jamais le `WorkspaceContext` singleton à zéro (il n'était résolu que sur les routes authentifiées). Le contexte résiduel faisait planter `HandleInertiaRequests` (`roleOf(null)` → TypeError 500) dès qu'un conteneur survit aux requêtes — cas des tests browser (serveur in-process), et futur Octane. Le middleware réinitialise désormais le contexte pour tout guest et rejoint le groupe `web` (la priority list existante garantit son ordre avant les bindings).
- `resources/js/layouts/app-layout.tsx` : les `DropdownMenuItem` utilisaient `onSelect`, qui n'existe pas dans Base UI (héritage Radix) — **le logout et la bascule de workspace ne se déclenchaient jamais au clic**. Corrigé en `onClick`. Le label du menu workspaces était par ailleurs rendu hors `DropdownMenuGroup`, ce qui fait planter l'ouverture du menu dans Base UI (erreur #31) — corrigé.
- `resources/js/Pages/Issues/Show.tsx` : les inputs d'heures utilisaient `step="0.25"` avec `min="0.01"` (saisie) puis `min="0"` (estimation) — la validation native du navigateur rejetait silencieusement quasiment toute valeur (1,5 h, 2 h, 8 h…) : **le formulaire de saisie de temps était inutilisable dans un vrai navigateur**. Corrigé en `step="any"` (la validation serveur reste l'autorité). Ajout des attributs `data-test` sur les cibles d'action ambiguës (ADR-4).

Constat documenté hors périmètre (à traiter par la change `status-management`) : la suppression protégée d'un statut (409) est **silencieuse** dans l'UI — le message serveur « This status is used by N issue(s) » n'atteint jamais l'utilisateur (Inertia avale l'erreur HTML des POST). Le test E2E couvre le refus (persistance + non-suppression) ; l'affichage du message relève d'une décision produit sur le contrat d'erreur (409 vs 422/flash), qui concerne aussi la future change `mcp-access`.

Contrainte d'outillage documentée (design.md, ADR-4 et Risques) : `pest-plugin-browser` 4.3.1 hang indéfiniment sur une action dont le sélecteur ne matche rien ou n'est jamais actionnable (issues #1781 / #1911) — les tests browser n'utilisent donc que des cibles garanties présentes (`data-test`, CSS explicites) et des parcours courts.
