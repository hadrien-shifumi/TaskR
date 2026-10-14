## Context

La suite existante (PHPUnit 12, style classe) couvre les frontières HTTP de chaque domaine : validation, autorisation, isolation inter-tenants, props Inertia. Le frontend — React 19, navigation client Inertia, dialogs shadcn/Base UI — n'est jamais exécuté. Les specs déployées (`workspace-management`, `project-management`, `status-management`, `issue-tracking`) et les changes implémentées (`time-tracking`, `project-health`) décrivent des parcours utilisateurs qui méritent une vérification de bout en bout dans un vrai navigateur.

Cette change ajoute la brique test E2E, sans toucher au comportement applicatif. Décisions déjà prises pendant l'exploration :

- Outil : plugin browser officiel de Pest (Playwright sous le capot), pas Dusk, pas de stack TS séparée.
- Le périmètre couvre les capabilities livrées ; `templates` et `mcp-access` rejoindront le smoke à leur livraison.

## Goals / Non-Goals

**Goals:**

- Runner Pest 4 opérationnel, la suite existante passant à l'identique (aucun fichier de test modifié).
- Testsuite navigateur dédiée (`tests/Browser`), exécutable seule via un script `test:e2e` qui build les assets.
- Parcours E2E priorisés : golden path, management & collaboration, santé projet, smoke global (JS/console/accessibilité).
- Défauts préexistants révélés par le smoke identifiés et corrigés (ou documentés s'ils relèvent d'une décision produit).

**Non-Goals:**

- Migration de la suite existante vers la syntaxe Pest (les tests classe tournent tels quels sous Pest).
- Visual regression, multi-browser (Firefox/Safari), sharding — des extensions possibles une fois la base stable.
- Refonte des feature tests ni déplacement de leur périmètre (validation, autorisation, isolation restent testés en HTTP).
- Couvrir `templates` et `mcp-access` (non implémentées).

### ADR-1 : Pest 4 + plugin browser officiel, pas Pest 5 ni Playwright standalone

**Décision** : `pestphp/pest` ^4 + `pestphp/pest-plugin-browser` ^4 + `pestphp/pest-plugin-laravel` ^4, Playwright (Chromium) côté npm. Pest 4 est construit sur PHPUnit 12 — déjà la version du projet — et exécute nativement les tests PHPUnit en style classe, donc la suite existante continue de tourner sans réécriture. Le plugin browser expose `visit()` et une API d'assertions navigateur tout en donnant accès aux helpers Laravel : `RefreshDatabase` (même sqlite `:memory:`), factories, fakes, actions en `AsObject`.

**Alternatives** : Pest 5 (impose PHPUnit ^13.4.2 + collision ^8.9.5 — upgrade de toute la suite pour un bénéfice E2E nul) ; Playwright standalone en TS (zéro impact PHP mais double stack, seeding/DB orchestrés à la main, perte des factories et assertions Laravel) ; Laravel Dusk (ChromeDriver à maintenir, remplacé par l'approche Playwright, pas de parallélisation native).

**Justification** : friction minimale avec l'existant, une seule langue (PHP), réutilisation directe du pattern `setupWorkspace()` des feature tests, et le retour à Pest 5 plus tard sera purement incrémental.

### ADR-2 : Testsuite séparée + assets buildés avant la suite

**Décision** : les tests navigateur vivent dans `tests/Browser` (testsuite dédiée dans `phpunit.xml`, bind `uses()` dans `tests/Pest.php`). Un script `test:e2e` enchaîne `npm run build` puis `./vendor/bin/pest --testsuite=Browser` : les pages sont servies avec les assets compilés (le `public/build` est gitignoré, jamais supposé présent), ce qui rend la suite déterministe et CI-ready sans dépendre d'un serveur Vite en cours d'exécution. La suite quotidienne (`composer test`) reste rapide et n'exécute pas les tests navigateur.

**Alternatives** : tout dans la même testsuite (ralentit le retour quotidien de plusieurs dizaines de secondes et casse en l'absence d'assets buildés) ; servir depuis le dev server Vite (dépendance à un process externe, non déterministe).

**Justification** : l'E2E est complémentaire du quotidien, pas un remplacement ; séparer les testsuites permet de choisir quand payer son coût.

### ADR-3 : Fixtures via actions du domaine, navigateur pour le parcours

**Décision** : chaque test arrange ses données depuis le processus PHP avec les mêmes outils que les feature tests — factories, `CreateWorkspace::run(...)`, `app(WorkspaceContext::class)->set(...)` — puis délègue au navigateur la navigation, les interactions et les assertions UI. Pas de seeder global : l'arrangement reste explicite et par-test. Là où un parcours n'exige pas de retester l'authentification, le test crée l'utilisateur/workspace et passe par le formulaire de login une fois par test.

**Alternatives** : seeders dédiés E2E (état implicite partagé entre tests, débogage plus difficile) ; tout faire par l'UI depuis l'inscription (lent, redondant avec le parcours golden path qui le couvre déjà).

**Justification** : cohérence avec les conventions du projet (`testing.md` du skill backend) ; le navigateur teste ce que le navigateur seul peut révéler.

### ADR-4 : Sélecteurs — texte d'abord, `data-test` sur les cibles d'action

**Décision** : les assertions ciblent le texte visible (ce que voit l'utilisateur), les CSS explicites, ou les cartes shadcn par `data-slot`. Les **actions** (click/fill/press) utilisent des cibles garanties présentes et non ambiguës : attributs `data-test` ajoutés au fil de l'eau sur les boutons et champs d'édition, CSS explicites (`[role="combobox"]`, `[role="option"]:has-text(...)`, `li:has-text(...) button:has-text(...)`, `input[placeholder=...]`), ou ids de formulaire. Les interactions natives non pilotables (`window.confirm`) sont neutralisées via `$page->script('window.confirm = () => true')`.

**Justification** (durant l'implémentation) : `pest-plugin-browser` 4.3.1 **hang indéfiniment** sur une action dont le sélecteur ne matche aucun élément ou reste non actionnable — bug connu (#1781, #1911) : le process PHP attend pour toujours une réponse du serveur Node. Une cible d'action floue (first-match texte, élément qui apparaît tard) transforme donc une erreur de sélecteur en suite bloquée sans diagnostic. Les assertions, elles, échouent proprement (timeout 5 s + screenshot) : le texte reste le médium des assertions, les cibles d'action deviennent déterministes. Parcours courts : moins de surface d'exposition par test, isolement plus net des défaillances.

### ADR-5 : Smoke global incluant l'accessibilité, avec triage des défauts préexistants

**Décision** : chaque page existante est visitée en viewport desktop puis mobile avec `assertNoSmoke()` (aucune erreur JS, aucun log console) et `assertNoAccessibilityIssues()` (niveau serious par défaut). Les violations préexistantes sont triées : corrigées si elles relèvent d'un défaut, ou documentées dans la change si elles relèvent d'un arbitrage produit.

**Alternatives** : smoke sans accessibilité (perd une détection d'incident qu'un plugin déjà installé fournit gratuitement) ; accessibilité au niveau moderate (bruit de false positives sur une première passe).

**Justification** : le coût marginal est nul (le plugin embarque les vérifications), le niveau serious est le standard du plugin, et le triage explicite évite l'écueil du "test qu'on désactive".

## Risks / Trade-offs

- [Plugin browser encore jeune (v4.x)] → vérifié : l'API couvre les besoins, mais deux bugs connus subsistent en 4.3.1 — hang indéfini sur action à zéro correspondance ou jamais actionnable (#1781, #1911). Mitigé par l'ADR-4 (cibles d'action déterministes) et des parcours courts. À revisiter à la montée de version du plugin.
- [Défauts préexistants révélés par les parcours] → trois corrigés dans cette change (contexte résiduel guest, `onSelect` Base UI, `step="0.25"` bloquant la validation native) ; un documenté hors périmètre (409 silencieux à l'affichage, cf. tasks.md 5.3).
- [Flakiness (animations, transitions Inertia)] → les assertions du plugin sont auto-attendantes (5 s) ; les actions ciblent des éléments déjà présents ; le confirm natif est neutralisé explicitement.
- [Coût d'exécution (build + navigateur)] → testsuite séparée (ADR-2) ; 15 tests browser ≈ 8,5 s ; `--parallel` et `--shard` disponibles si la suite grossit.
- [Dérive vers la duplication des feature tests] → règle : l'E2E teste des parcours, jamais une matrice de validation ; si un cas E2E ressemble à un cas HTTP, il n'a pas sa place ici.

## Migration Plan

Additif et réversible, sans impact applicatif :

1. Dépendances : retrait de `phpunit/phpunit` en dépendance directe (transitive via Pest, même version 12), ajouts dev `pestphp/pest` ^4, `pest-plugin-browser` ^4, `pest-plugin-laravel` ^4 ; npm `playwright` + Chromium. Rollback : `composer require phpunit/phpunit --dev` et suppression de la testsuite.
2. Fichiers nouveaux : `tests/Pest.php`, `tests/Browser/*` — aucun fichier applicatif modifié, sauf `data-test` ciblés et corrections de défauts révélés.
3. La suite existante doit rester au vert à chaque étape ; le smoke de validation (`/login`) est le critère de sortie de l'installation.

## Open Questions

- Niveau d'accessibilité à long terme (serious aujourd'hui ; moderate quand les violations faciles seront épuisées) — à revisiter après le premier passage.
- Branchement CI : immédiat en job dédié, ou après stabilisation des premières semaines ? — décision d'exploitation, hors périmètre de la change.
