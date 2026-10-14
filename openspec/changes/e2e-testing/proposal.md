> **Ordre d'implémentation conseillé : autonome** — ne dépend d'aucune change produit en cours, et indépendante de `templates` et `mcp-access` (non implémentées). Les futures pages livrées par ces changes rejoindront le smoke au fur et à mesure.

## Why

Taskr possède une suite feature PHPUnit 12 solide qui valide les frontières HTTP de chaque domaine : validation, autorisation, isolation inter-tenants, props Inertia. Mais aucun test n'exécute le frontend : React 19 ne monte jamais, la navigation client Inertia n'est jamais empruntée, les dialogs shadcn/Base UI ne s'ouvrent jamais, et le rendu réel des pages n'est jamais vérifié. Une régression UI (erreur JavaScript, dialog cassé, flash invisible, lien mort) passe aujourd'hui inaperçue jusqu'à l'utilisateur.

L'E2E navigateur sécurise les parcours utilisateurs de bout en bout et détecte en plus les erreurs JS, les logs console et les problèmes d'accessibilité — trois familles de défauts invisibles pour les feature tests.

## What Changes

- Adoption du runner **Pest 4** (construit sur PHPUnit 12, déjà en place) — la suite existante en style classe continue de tourner à l'identique, sans réécriture.
- Ajout du plugin browser officiel `pestphp/pest-plugin-browser` : tests navigateur **Playwright (Chromium)** écrits en PHP, avec accès complet aux helpers Laravel — `RefreshDatabase` (même sqlite `:memory:`), factories, actions en `AsObject`, fakes.
- Nouvelle testsuite `tests/Browser` en style Pest, organisée par parcours utilisateur plutôt que par endpoint, avec fixtures réutilisant le pattern `setupWorkspace()` des feature tests.
- Couverture par ordre de priorité :
  1. **Golden path** : inscription (workspace + statuts par défaut) → login → création projet → création issue minimale → changement de statut → commentaire.
  2. **Management & collaboration** : gestion des statuts par un owner (dont suppression protégée), invitation + acceptation + bascule de workspace, saisie de temps depuis l'UI.
  3. **Santé projet** : page Health avec seed ciblé (catégories, due dates, target date) + badges de projection sur la liste des projets.
  4. **Smoke global** : toutes les pages visitées sans erreur JS, sans log console, sans problème d'accessibilité serious — en viewport desktop et mobile.
- Orchestration : build des assets avant la suite (script `test:e2e`), `.gitignore` pour screenshots/traces, exécution parallèle et traces Playwright exploitables en cas d'échec.

Hors périmètre (volontairement) : visual regression (`assertScreenshotMatches`), multi-browser (Firefox/Safari — Chromium seul pour commencer), migration de la suite existante vers la syntaxe Pest, refonte des feature tests (leur périmètre HTTP reste le leur), et les changes non implémentées (`templates`, `mcp-access`).

## Capabilities

### New Capabilities
- `e2e-testing`: socle de tests navigateur Pest/Playwright et parcours E2E couvrant les capabilities déployées (`workspace-management`, `project-management`, `status-management`, `issue-tracking`) ainsi que `time-tracking` et `project-health`.

### Modified Capabilities

(néant — aucun comportement applicatif n'est modifié ; l'E2E lit le domaine existant)

## Impact

- **Dépendances dev** : retrait de la dépendance directe `phpunit/phpunit` (reste transitive ^12 via Pest), ajout de `pestphp/pest` ^4, `pestphp/pest-plugin-browser` ^4, `pestphp/pest-plugin-laravel` ^4 ; côté npm, ajout de `playwright` et téléchargement de Chromium.
- **Configuration** : création de `tests/Pest.php` (`uses()` par testsuite), testsuite `Browser` dans `phpunit.xml`, scripts composer (`test` via `pest`, nouveau `test:e2e`), `.gitignore` (`tests/Browser/Screenshots`, `tests/Browser/Traces`).
- **Application** : aucune modification applicative attendue, sauf deux exceptions documentées au fil de l'eau — ajout d'attributs `data-test` sur des composants quand un sélecteur manque, et correction des défauts préexistants révélés par le smoke (erreurs JS, violations d'accessibilité).
- **Suite existante** : doit rester au vert sans qu'aucun fichier de test existant ne soit modifié.
- **CI** : prête à brancher quand la base sera stable (job dédié : `playwright install`, `npm run build`, `pest --parallel --trace`, artifacts) — non incluse dans cette change.
