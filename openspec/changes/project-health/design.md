## Context

Foundation est en place : workspaces multi-tenant, projets, issues à hiérarchie molle, statuts custom à catégories sémantiques fixes, commentaires, pattern ADR avec `lorisleiva/laravel-actions`. Les managers manquent d'une lecture synthétique de la tenue de leurs projets : la santé est aujourd'hui invisible sans parcourir les issues une par une.

Décisions produit déjà prises pendant l'exploration :

- Santé = pilotage par convention : pas de sprints, pas de workflow custom, pas de vélocité.
- La santé d'un projet est **toujours calculée** (jamais stockée ni saisie) à partir des données existantes : issues, catégories de statut, due dates, estimations et time entries.
- `target_date` sur les projets, repoussée depuis foundation, entre par cette change.
- Découpage initial : `foundation` → `mcp-access` → `templates` → `time-tracking` → `project-health`. Cette change **requires `time-tracking`** (estimations + time entries) : implémentée après elle, sans quoi le ratio de consommation ne peut exister.

## Goals / Non-Goals

**Goals:**

- Un manager voit en quelques secondes, par projet et sur la liste des projets, si le projet progresse, est en retard ou va l'être.
- Des indicateurs définis par des formules explicites, documentées dans la spec, reproductibles par un humain avec une calculatrice.
- Une action unique de calcul (`AsObject` + `AsController`), réutilisable telle quelle par les outils MCP de la change `mcp-access`.
- Zéro donnée dérivée persistée : pas de désynchronisation possible, pas de cache à invalider.

**Non-Goals:**

- Sprints, vélocité, burndown, earned value management.
- Alertes, notifications, rapports email.
- Santé agrégée au niveau workspace (des agrégats croisés pourront être ajoutés plus tard si besoin).
- Seuils configurables par workspace (les formules sont des conventions du produit).

## Decisions

### ADR-1 : `target_date` — colonne date nullable sur `projects`

**Décision** : migration additive `projects.target_date` (nullable, date). Éditable via l'action d'édition de projet existante, élargie avec une règle `date`. Aucune autre contrainte : la date cible peut être dans le passé (un projet "déjà en retard" est une situation légitime à visualiser).

**Alternatives** : période `starts_at` + `ends_at` par projet (plus de saisie, le `created_at` suffit comme début conventionnel) ; table de milestones (hors scope, ce n'est pas de la planification).

**Justification** : le plus petit ajout qui débloque la projection de retard. Le début de la fenêtre de projection est le `created_at` du projet — toujours disponible, jamais à saisir.

### ADR-2 : Santé toujours calculée, jamais stockée — action `GetProjectHealth`

**Décision** : une action `GetProjectHealth` (`AsObject` + `AsController`, domaine `app/Actions/Projects/`) prend un `Project` et retourne un objet de données `ProjectHealth` pur (compteurs par catégorie, pourcentage terminé, nombre d'issues en retard, heures estimées/logguées, ratio de consommation, pourcentage attendu à date, statut `ok`/`at-risk`/`late` ou absence de projection). Le Responder transforme ce DTO en page Inertia. Aucune écriture, aucune table de santé, aucun job de recalcul.

**Alternatives** : table `project_health_snapshots` rafraîchie par observer/job (désynchronisation inévitable entre une issue bougée et le snapshot, plus une table et des événements à maintenir) ; attributs JSON sérialisés sur le projet (mêmes défauts).

**Justification** : la santé est une **projection** des données, pas une donnée. Les agrégats nécessaires sont trois requêtes groupées — le coût de calcul à la lecture est négligeable devant le coût de maintenance d'un cache. Et l'action en `AsObject` est le point d'entrée prêt pour MCP : un outil `get_project_health` future est un one-liner.

### ADR-3 : Formule de projection — pourcentage attendu à date, statut `ok` / `at-risk` / `late`

**Décision** : formule unique, documentée dans la spec et reprise à l'identique dans le code :

```
attendu   = clamp((aujourdhui - project.created_at) / (target_date - project.created_at), 0, 1)
termine   = (issues done + issues cancelled) / total des issues du projet
statut    = late     si aujourdhui > target_date et termine < 100 %
           at-risk   si termine < attendu
           ok        sinon
```

Conventions : projet sans `target_date` → pas de statut de projection (indicateur absent, les autres indicateurs restent affichés) ; projet sans issue → tous les compteurs à 0, statut `ok` ; `target_date` antérieure ou égale à `created_at` → l'attendu vaut 1 dès le départ (clamp), le projet est `at-risk` dès qu'une issue n'est pas terminée.

**Alternatives** : seuil fixe "moins de X jours avant la target_date" (arbitraire, ne dit rien de l'avancement réel) ; burndown/vélocité (suppose des sprints, hors scope) ; earned value (saisie de charges planifiées, contradictoire avec "simple mais suffisant").

**Justification** : la comparaison avancement réel vs avancement attendu au fil du temps est la projection la plus simple qui réponde aux trois questions du manager (progression, retard constaté, retard anticipé). Une seule formule, des inputs toujours disponibles, aucun paramètre.

### ADR-4 : Calcul par agrégats SQL, jamais par chargement des issues

**Décision** : l'action calcule via des requêtes groupées sur `issues` (par `status.category` pour les compteurs, `SUM` sur estimations et time entries jointes, `COUNT` conditionnel pour les retards), jamais en chargeant la collection des issues en mémoire. Pour la vue d'ensemble de la liste des projets, les mêmes agrégats sont produits en **une passe groupée par `project_id`** sur l'ensemble des projets du workspace — pas une exécution d'action par projet.

**Alternatives** : `Project::issues()->get()` puis reduce en PHP (simple mais O(issues) mémoire et requêtes par projet — mortel sur la page liste) ; colonnes dénormalisées incrémentées par observer (réintroduit l'état stocké rejeté en ADR-2).

**Justification** : trois agrégats suffisent ; la sémantique reste en un seul endroit (l'action) et reste testable unitairement.

### ADR-5 : Hiérarchie molle — toutes les issues comptent à plat

**Décision** : tous les indicateurs comptent chaque issue du projet uniformément, parent et enfants, sans distinction de profondeur ni rollup par epic.

**Alternatives** : ne compter que les feuilles (les parents ne sont "que des conteneurs" — mais rien ne l'impose : un parent peut être done sans ses enfants) ; ne compter que le premier niveau sous le projet (introduit une sémantique de profondeur que le modèle n'a pas).

**Justification** : le modèle à hiérarchie molle n'a pas de types ; toute règle de filtrage par profondeur serait une convention implicite impossible à expliquer. "1 ligne = 1 issue qui peut être terminée ou non" est la convention la plus honnête.

### ADR-6 : Ratio de consommation — brut, sans seuil

**Décision** : affichage du ratio heures logguées / heures estimées (et des deux totaux), uniquement si le total estimé est strictement positif. Pas de statut dérivé du ratio, pas de seuil ("burn rate" ok/dépassé) : l'interprétation appartient au manager.

**Alternatives** : statut de consommation avec seuils (ex. > 100 % = dépassement) — tentant, mais dépend fortement de la fiabilité des estimations ; un ratio brut présente l'information sans juger à la place de l'utilisateur.

**Justification** : la projection de retard (ADR-3) porte déjà le verdict d'alerte ; le ratio est une donnée d'appui. Deux "feux tricolores" concurrents sur une même page dilueraient le message.

### ADR-7 : Visibilité et exposition — tout membre, routes et UI

**Décision** : route `GET /projects/{project}/health` mappée sur `GetProjectHealth` en `AsController` ; l'autorisation vérifie l'appartenance au workspace du projet (le global scope `BelongsToWorkspace` couvre l'isolation, la policy couvre l'appartenance), sans aucune gate de rôle. Page Inertia `Health` par projet ; la liste des projets est enrichie du badge de statut et du pourcentage terminé via la passe groupée de l'ADR-4.

**Alternatives** : réserver la santé aux owners (contredit le besoin : les managers ne sont pas nécessairement owners du workspace) ; page santé au niveau workspace agrégeant tous les projets (pertinent, mais la lecture par projet est le besoin primaire — l'agrégat workspace pourra s'ajouter plus tard sur les mêmes fondations).

**Justification** : la santé est de l'information, pas une opération — tout membre du tenant y a accès, et le pattern d'autorisation reste celui de foundation.

## Risks / Trade-offs

- [N+1 sur la liste des projets si la santé est calculée projet par projet] → passe groupée unique par `project_id` (ADR-4), test de charge inutile à l'échelle visée mais structure correcte dès le départ.
- [Noms des champs time-tracking non figés (la change `time-tracking` n'est pas encore implémentée)] → cette change se branche sur l'action/modèle exposés par `time-tracking` ; les tâches précisent d'aligner les noms à l'implémentation réelle, la formule elle-même est stable.
- [`target_date` <= `created_at`] → comportement défini par clamp (attendu = 1, `at-risk` immédiat si inachevé) ; pas de rejet en validation, cas documenté dans la spec.
- [Projet sans issue] → compteurs à 0, statut `ok` — éviter un faux `at-risk` sur un projet fraîchement créé.
- [Ratio de consommation trompeur avec des estimations partielles] → affiché seulement si le total estimé > 0, totaux bruts affichés à côté du ratio.
- [Signaux interprétés comme des engagements] → la page affiche la formule attendu/réel et la `target_date` à côté du statut, pour que le manager voit le pourquoi du verdict, pas juste un feu rouge.

## Migration Plan

Migration purement additive : `projects.target_date` nullable, sans index (pas de requête de filtrage sur cette colonne). Déploiement : `php artisan migrate`. Rollback : drop de la colonne, aucune donnée métier perdue (la santé se recalcule). Pas de backfill — un projet existant est simplement "sans date cible" tant que le manager n'en saisit pas une.

## Open Questions

- Afficher le pourcentage attendu à date à côté du pourcentage terminé sur la page Health (recommandé pour la lisibilité du verdict) — tranché au moment de l'UI, pas bloquant.
- Unité exacte des estimations dans `time-tracking` (heures vs minutes) — le ratio est unité-agnostique, l'affichage s'alignera.
- Actualisation temps réel de la page (polling/WebSockets) — aucune demande ; le recalcul à chaque chargement de page suffit.
