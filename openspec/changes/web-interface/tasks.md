## 1. Tokens et socle visuel

- [ ] 1.1 Reporter la palette oklch des maquettes dans `resources/css/app.css` mappée sur les slots shadcn (ADR-1 : `--bg`→background, `--surface`→card/popover, `--fg`→foreground, `--muted`→muted-foreground, `--border`→border/input, `--accent`→primary/ring, dérivés `color-mix`→secondary/accent/muted) + tokens `--warning`/`--success`/`--info` exposés au `@theme inline` ; rayons 12 px (maître) / 8 px (petits éléments) ; mono tabulaire pour ULID et numériques — commit isolé, `npm run build` au vert, aucun changement de JSX dans le même commit
- [ ] 1.2 Créer le shell réglages partagé (tabs Statuts / Membres / Gabarits / Tokens API « à venir ») utilisé par les pages `Settings/*`

## 2. Écrans existants repris (un commit par écran, comparé à sa maquette)

- [ ] 2.1 Auth (`screens/login.html`) : Login, Register, GuestLayout — tokens, densité, états ; inviter à trancher l'Open Question page d'acceptation d'invitation
- [ ] 2.2 Home (`screens/home.html`) : dashboard workspace — compteurs cohérents avec les autres écrans
- [ ] 2.3 Projects/Index (`screens/projects.html`) : liste, santé, archivage — badge de santé, actions d'archivage
- [ ] 2.4 Projects/Health (`screens/project-health.html`) : répartition, retards, heures
- [ ] 2.5 Issues/Index (`screens/issues.html`) : liste cross-projet, filtres (sentinelle `'all'`, `preserveState`)
- [ ] 2.6 Issues/Show (`screens/issue-detail.html`) : statut, temps, commentaires (contrôles auteur-only), parent (erreur de cycle inline)
- [ ] 2.7 Settings/Statuses + Settings/Templates (`screens/settings.html`) : drag de réordonnancement des statuts, dialog de suppression avec migration des issues, refus de suppression de projet proposant l'archivage côté Projects

## 3. Board kanban

- [ ] 3.1 Route `GET projects/{project}/board` (`projects.board`) + action/responder `ShowProjectBoard` : issues du projet groupées par statut du workspace (ordre du workspace), compteurs par colonne, aucune requête hors Action
- [ ] 3.2 Page `Pages/Projects/Board.tsx` (`screens/project-board.html` comme référence) : colonnes, cartes (badges priorité/catégorie/retard via tokens), compteurs, état vide par colonne, lien depuis Projects/Index et navigation board↔liste
- [ ] 3.3 Drag & drop natif HTML5, drop non optimiste : appel `issues.move` (statut + ordre), synchronisation sur la réponse, retour à la colonne d'origine + message en cas de refus ; alternative clavier « déplacer vers » (select) sur chaque carte

## 4. Réglages — Membres

- [ ] 4.1 Responder + page `Pages/Settings/Members.tsx` : liste des membres (nom, email, rôle), invitation par email via l'action existante, retrait owner-only, emplacement tokens API en « à venir »
- [ ] 4.2 Lecture seule member (ADR-4) : contrôles owner-only masqués/désactivés selon le rôle dans les props partagées, indicateur « lecture seule » — aucun changement backend

## 5. Tests

- [ ] 5.1 `assertInertia` pour `ShowProjectBoard` (groupement par statut, ordre du workspace, compteurs) et la page Membres (rôles, invitation owner-only, refus member)
- [ ] 5.2 Suite Browser (E2E) : parcours board (drag & drop d'une carte, retour et message sur refus), migration de statut avant suppression, vue member en lecture seule, validation inline du titre d'issue
- [ ] 5.3 Vérifier les compteurs croisés maquette (projet « Stripe » : 18 issues = 12 done, 3 in progress, 2 backlog, 1 cancelled) comme jeu de contrôle des requêtes du board et de la santé projet

## 6. Vérification finale

- [ ] 6.1 `php artisan test` complet au vert (aucun `assertInertia` existant cassé) + suite Browser
- [ ] 6.2 `npm run build` au vert ; revue visuelle écran par écran contre `screens/*.html` ouverts dans le navigateur
- [ ] 6.3 `vendor/bin/pint --dirty --format agent` au propre
- [ ] 6.4 Revue ADR : aucune couleur brute dans le JSX, aucune requête DB hors Action, aucune règle métier dupliquée côté client, aucun nouveau primitif hors CLI shadcn
