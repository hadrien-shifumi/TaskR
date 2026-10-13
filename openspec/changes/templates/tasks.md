## 1. Migrations et modèle

- [ ] 1.1 Migration `issue_templates` : ULID (via convention `BaseModel`), `workspace_id` (FK), `name`, `description` (text, nullable), `priority` (enum `Priority`, nullable), `timestamps` ; contrainte d'unicité (`workspace_id`, `name`)
- [ ] 1.2 Migration pivot `issue_template_labels` (`issue_template_id`, `label_id`, unicité du couple)
- [ ] 1.3 Modèle `IssueTemplate` : trait `BelongsToWorkspace`, relation `labels()` (belongsToMany), cast `priority` sur l'enum `Priority` ; factory associée

## 2. Actions CRUD gabarits

- [ ] 2.1 `CreateIssueTemplate` (owner only) : `authorize()` + `rules()` — nom requis + unique par workspace, description nullable, priorité ∈ `Priority` nullable, labels ∈ labels du workspace ; `handle()` + synchronisation des labels pivot
- [ ] 2.2 `UpdateIssueTemplate` (owner only) : mêmes règles (unicité du nom hors gabarit courant), édition du corps, de la priorité, des labels
- [ ] 2.3 `DeleteIssueTemplate` (owner only) : suppression simple, sans condition bloquante (aucune issue ne référence un gabarit)
- [ ] 2.4 `ShowTemplatesPage` + responders `app/Responders/Templates/` (page + redirects), sur le modèle de `Statuses` ; routes `settings/templates` (nommées)

## 3. Application à la création d'issue

- [ ] 3.1 Étendre `CreateIssue` : paramètre optionnel `template` (ULID), résolution du gabarit du workspace courant (`findOrFail` — le global scope rejette l'intrusion inter-workspace en 404)
- [ ] 3.2 Fusion dans `CreateIssue` avant création : pour `description`, `priority`, `labels`, clé absente → valeur par défaut du gabarit, clé présente → valeur explicite ; le gabarit ne remplit jamais `title`, `status`, `due_date`, `parent_id` ni les assignés ; ignorer silencieusement les labels du gabarit qui n'existent plus
- [ ] 3.3 Types TS + shared props : exposer les gabarits du workspace (nom, description, priorité, labels) aux pages de création d'issue et de réglages

## 4. UI

- [ ] 4.1 Page `Settings/Templates` (même structure que `Settings/Statuses`) : liste des gabarits (nom, priorité, labels), dialogue création/édition (nom, description, priorité, labels), suppression avec confirmation ; actions de gestion masquées pour les `member`
- [ ] 4.2 Sélecteur « Template » optionnel dans le dialogue de création d'issue : pré-remplissage client de la description, priorité et labels à la sélection, tout restant éditable ; dé-sélection réinitialise le formulaire ; `template` envoyé avec la requête de création
- [ ] 4.3 Vérifier le rendu avec `npm run build` (manifest Vite) si les changements front n'apparaissent pas

## 5. Tests

- [ ] 5.1 Tests CRUD gabarits : création complète et minimale, nom dupliqué refusé (même workspace, accepté dans un autre), édition, suppression
- [ ] 5.2 Tests permissions : `member` refusé sur création/édition/suppression, lecture de la liste accessible, application par un `member` acceptée
- [ ] 5.3 Tests application : pré-remplissage des 3 champs à la création, valeur explicite prioritaire (clé absente vs présente, y compris vide), issue sans gabarit inchangée, gabarit d'un autre workspace rejeté (404), suppression du gabarit sans effet sur les issues existantes, double application (client + serveur) impossible
- [ ] 5.4 Test d'isolation inter-tenants : aucun gabarit d'un workspace B lisible/créable depuis le contexte A

## 6. Vérification finale

- [ ] 6.1 Passe complète de tests au vert (`php artisan test`), dont les tests d'isolation inter-tenants
- [ ] 6.2 `vendor/bin/pint --dirty --format agent`
- [ ] 6.3 Revue du respect du pattern ADR : requêtes DB et fusion uniquement dans les Actions, `Inertia::render` uniquement dans les Responders
- [ ] 6.4 Dogfood minimal : créer les gabarits « Bug » et « Analyse fonctionnelle », créer une issue via chaque gabarit, éditer les valeurs pré-remplies, vérifier qu'une issue sans gabarit reste inchangée
