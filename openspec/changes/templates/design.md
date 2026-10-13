## Context

Taskr gère des issues à hiérarchie molle sans notion de type : un bug, une tâche de développement et une analyse fonctionnelle sont la même entité, différenciée uniquement par l'usage. Conséquence : rien ne garantit qu'un bug rédigé par deux membres différents se ressemble. Le besoin exprimé : « définir des templates de tickets pour avoir une forme identique par type de ticket — un bug doit toujours être pareil, une tâche de développement également, une analyse fonctionnelle… ».

Décisions produit déjà prises pendant l'exploration :

- Les gabarits sont **non bloquants** : ils pré-remplissent (description gabarit Markdown, priorité par défaut optionnelle, labels par défaut optionnels), ils ne contraignent jamais rien ; l'utilisateur peut tout éditer après application.
- CRUD gabarit : owner only (convention du workspace, comme les statuts) ; application : tout membre, à la création d'issue.

La fondation (change `foundation`, archivée) est en place : pattern ADR avec `lorisleiva/laravel-actions`, scoping workspace par trait `BelongsToWorkspace` + global scope, ULID, labels libres, priorité enum `Priority`, UI Inertia + React TS + shadcn/ui avec une page `Settings/Statuses` et un dialogue de création d'issue.

## Goals / Non-Goals

**Goals:**

- Un owner peut définir des gabarits d'issues réutilisables dans tous les projets du workspace.
- Tout membre peut appliquer un gabarit à la création d'une issue pour obtenir une forme standard, tout en gardant la main sur chaque valeur.
- Les issues restent découplées des gabarits : aucune issue existante ne dépend d'un gabarit.
- Les actions créées (`CreateIssueTemplate`, `UpdateIssueTemplate`, `DeleteIssueTemplate`) sont les points d'entrée naturels des futurs outils MCP de la change `mcp-access` — un agent devra pouvoir créer un bug « bien formé » en appliquant le gabarit.

**Non-Goals:**

- Exposition MCP des gabarits (change `mcp-access`).
- Application d'un gabarit à l'édition d'une issue existante, ou rétroactivement.
- Catégorie/type obligatoire sur les gabarits (voir ADR-4).
- Gabarit par défaut appliqué automatiquement sans sélection explicite.
- Time tracking, santé projet (changes dédiées).

## Decisions

### ADR-1 : Portée workspace (pas projet)

**Décision** : les gabarits sont définis au niveau du workspace et disponibles dans tous ses projets.

**Alternatives** : définition par projet (le brief mentionne « par projet (peut être workspace ?) ») ; gabarits globaux à l'instance.

**Justification** : trois raisons convergent.

1. **Cohérence du domaine** : les statuts et les labels — référencés par les gabarits (priorité, labels par défaut) — vivent au workspace. Un gabarit défini par projet référencerait des labels workspace : conflit de périmètre, et un gabarit projet serait cassé par tout réordonnancement de la taxonomie workspace.
2. **Cohérence produit** : les projets sont de simples conteneurs d'issues sans configuration propre (pas de workflow, pas de config). Introduire la première config par projet ici ouvrirait une brèche que rien ne justifie aujourd'hui.
3. **Réutilisation** : un gabarit « Bug » a vocation à servir tous les projets du workspace — c'est la standardisation recherchée.

Si un besoin de raffinement par projet émerge (sous-ensembles, ordre), il s'ajoutera plus tard comme un simple filtre optionnel sur une entité qui reste workspace-scoped — non breaking.

### ADR-2 : Modèle — table `issue_templates` + pivot labels, sans lien vers les issues

**Décision** :

- Table `issue_templates` : ULID (via `BaseModel`), `workspace_id`, `name`, `description` (text Markdown, nullable), `priority` (enum `Priority`, nullable). Contrainte d'unicité (`workspace_id`, `name`).
- Pivot `issue_template_labels` : gabarit ↔ label (labels du workspace).
- Modèle `IssueTemplate` avec trait `BelongsToWorkspace` (global scope), relations `labels()` (belongsToMany) et cast `priority` sur l'enum.
- **Aucune colonne** `template_id` sur `issues` : le lien n'est pas persisté.

**Alternatives** : table nommée `templates` (nom générique gaspillé alors que d'autres gabarits pourraient apparaître) ; persistance du lien issue→gabarit pour statistiques/drift detection (couple les issues au cycle de vie des gabarits, contredit le « non bloquant » et ne sert aucun besoin actuel).

**Justification** : `nullable` sur `description` et `priority` car un gabarit peut ne porter que des labels (ou seulement un nom de convention). L'absence de lien persisté rend la suppression d'un gabarit triviale et sans migration de données — l'invariant « éditer/supprimer un gabarit n'affecte aucune issue » est garanti par construction.

### ADR-3 : Sémantique d'application — fusion « valeurs explicites priment », uniquement à la création

**Décision** : `CreateIssue` accepte un paramètre optionnel `template` (ULID du gabarit). Au `handle()`, avant validation finale, le gabarit fournit des **valeurs par défaut** : pour chacun des champs `description`, `priority`, `labels`, si la requête ne fournit pas le champ, la valeur du gabarit est substituée ; si la requête fournit le champ (même vide), la valeur explicite est conservée telle quelle. Le gabarit ne remplit jamais `title`, `status`, `due_date`, `parent_id`, ni les assignés : ce sont des valeurs contextuelles, pas des éléments de forme. Application uniquement à la création — jamais à l'édition.

**Alternatives** : pré-remplissage 100 % côté client (le serveur ne connaît jamais le gabarit à la création) ; union systématique des labels (gabarit + saisie).

**Justification** : côté serveur, la même sémantique sert l'UI et les futurs agents MCP — un agent pourra créer un bug « bien formé » en passant juste `template` + `title`, sans copier le Markdown. Le pré-remplissage client reste utile (l'utilisateur voit et édite les valeurs avant soumission) ; dans ce cas il fournit des valeurs explicites et la fusion serveur devient un no-op — pas de double application. La règle « clé absente = défaut du gabarit, clé présente = explicite » est simple à tester et sans ambiguïté. L'union des labels est rejetée : surprenante pour l'utilisateur (des labels apparaissent sans qu'il les ait vus) et contraire au « tout est éditable, l'explicite prime ».

### ADR-4 : Pas de catégorie/type sur les gabarits — nom libre

**Décision** : pas de champ `category`/`type`. Le gabarit est identifié par son nom libre, unique par workspace.

**Alternatives** : enum de catégories (bug/dev/analyse/…) ; champ texte libre `type` en plus du nom.

**Justification** : la liste des natures de tickets émerge de l'usage de chaque workspace et n'a pas de sémantique système (contrairement aux catégories de statuts qui portent les invariants done/cancelled). Un enum figé serait faux pour certains workspaces ; un type libre en plus du nom duplique l'information pour zéro logique. Les conventions d'équipe se logent dans le nom (« Bug », « Analyse fonctionnelle »), et le nom unique par workspace suffit à la sélection. Ajouter une catégorie plus tard (si un besoin de filtrage/groupement émerge) serait une simple colonne nullable — non breaking.

### ADR-5 : Application serveur dans `CreateIssue`, CRUD dans des actions dédiées

**Décision** :

- `app/Actions/Templates/` : `CreateIssueTemplate`, `UpdateIssueTemplate`, `DeleteIssueTemplate` (owner only, `authorize()` + `rules()`), `ShowTemplatesPage` pour la page de gestion. Responders dans `app/Responders/Templates/` (page + redirects), sur le modèle de `Statuses`.
- L'application du gabarit vit **dans `CreateIssue`** (résolution du gabarit + fusion), pas dans une action séparée `ApplyTemplate` : l'application n'est pas une opération métier autonome, c'est un pré-traitement de la création.
- Validation `template` : ULID existant du workspace courant (le global scope rend l'intrusion inter-workspace silencieusement impossible — l'issue `findOrFail` renvoie un 404) ; `priority` ∈ enum ; labels ∈ labels du workspace.
- Suppression de gabarit : sans condition bloquante (aucune issue ne référence un gabarit), contrairement aux statuts.

**Justification** : une action = une opération métier ; « appliquer un gabarit » seul n'a pas d'effet durable. Garder la fusion dans `CreateIssue` prépare le futur outil MCP `create_issue` sans ajouter une action artificielle. Le CRUD suit à l'identique le pattern `Statuses` déjà éprouvé.

### ADR-6 : UI — sélecteur dans le dialogue de création + page Settings/Templates

**Décision** :

- **Dialogue de création d'issue** : un sélecteur « Template » optionnel (shadcn/ui Select) placé au-dessus du formulaire, alimenté par les gabarits du workspace (partagés via les shared props Inertia ou une prop dédiée de la page). La sélection pré-remplit côté client `description` (editor Markdown), `priority` et `labels` du formulaire ; tout reste éditable ; le `template` sélectionné est envoyé avec la requête (fusion serveur en filet de sécurité pour les canaux non-UI). Dé-sélectionner remet le formulaire à zéro.
- **Réglages du workspace** : page `Settings/Templates` (même gabarit de page que `Settings/Statuses`) : liste des gabarits (nom, priorité, labels), création/édition (formulaire nom, description, priorité, labels), suppression avec confirmation. Actions de gestion masquées pour les `member`.

**Alternatives** : application uniquement serveur sans pré-remplissage client (l'utilisateur ne verrait le résultat qu'après création — mauvais, il doit pouvoir ajuster avant) ; gestion des gabarits dans le dialogue de création (imbriquer du CRUD dans un dialogue nuit à la lisibilité et à la gouvernance owner).

**Justification** : le pré-remplissage client rend le « non bloquant » tangible (l'utilisateur voit et édite chaque valeur avant de soumettre) ; la page de réglages centralise la gouvernance avec les autres éléments workspace, et l'envoi du `template` côté serveur garantit une sémantique unique pour tous les canaux.

## Risks / Trade-offs

- [Labels référencés par un gabarit supprimés ou renommés] → à l'application, ignorer silencieusement les labels du gabarit qui n'existent plus (philosophie non bloquante) ; validation à l'écriture du gabarit pour exiger des labels existants. La suppression de labels n'existe pas encore dans l'app — le risque est théorique, le comportement est défini dès maintenant.
- [Ambiguïté « champ absent vs champ vide » dans la fusion] → règle explicite clé absente/explicite + tests unitaires de la fusion pour chaque champ.
- [Double application (client + serveur)] → le pré-remplissage client produit des valeurs explicites ; la fusion serveur devient un no-op dans le parcours UI. Documenter dans `CreateIssue` et couvrir par un test.
- [Prolifération de gabarits quasi identiques] → nom unique par workspace + page de gestion ; pas de duplication contrôlée (overkill pour l'usage visé).
- [Gabarits applicables via un canal non-UI par un member] → l'application n'est qu'un pré-remplissage, le CRUD reste owner only ; aucun risque de privilege escalation.

## Migration Plan

Migration additive uniquement (`issue_templates` + pivot) : déployable sans downtime, sans données à migrer, rollback par simple `migrate:rollback`. Aucun gabarit par défaut n'est créé à l'installation — la liste démarre vide (contrairement aux statuts) : les gabarits traduisent des conventions d'équipe, il n'y a pas de gabarit universellement pertinent.

## Open Questions

- **Gabarit par défaut automatique** (appliqué sans sélection, ex. toujours « Bug » par projet) : recommandé non pour l'instant — explicite seulement. À revisiter si l'usage montre une friction.
- **Formulaire d'édition : « réinitialiser depuis le gabarit »** : hors scope (application à la création uniquement) ; réévaluer si des utilisateurs demandent à re-standardiser une issue existante.
- **Ordre d'affichage des gabarits** dans le sélecteur : alphabétique suffisant à priori ; une colonne `position` serait un ajout trivial si le besoin apparaît.
