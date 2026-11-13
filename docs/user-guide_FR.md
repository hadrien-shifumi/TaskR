# Guide utilisateur de TaskR

Ce guide explique **ce que fait TaskR et comment l'utiliser au quotidien**. Il
s'adresse à toute personne qui utilise l'application — aucune connaissance
technique n'est nécessaire.

> Vous cherchez plutôt à installer ou héberger TaskR ? Voir
> [`installation_FR.md`](installation_FR.md) et [`self-hosting_FR.md`](self-hosting_FR.md).

## Sommaire

1. [TaskR en une page](#taskr-en-une-page)
2. [Compte et connexion](#compte-et-connexion)
3. [Espaces de travail, membres et invitations](#espaces-de-travail-membres-et-invitations)
4. [Rôles et permissions](#rôles-et-permissions)
5. [Projets](#projets)
6. [Tickets](#tickets)
7. [Statuts et workflow](#statuts-et-workflow)
8. [Commentaires et modèles de tickets](#commentaires-et-modèles-de-tickets)
9. [Suivi du temps](#suivi-du-temps)
10. [Pilotage et santé des projets](#pilotage-et-santé-des-projets)
11. [Vues, recherche et navigation](#vues-recherche-et-navigation)
12. [Base de connaissance](#base-de-connaissance)
13. [Notifications et mentions](#notifications-et-mentions)
14. [Journal d'activité](#journal-dactivité)
15. [Exports et sauvegarde](#exports-et-sauvegarde)
16. [Sécurité du compte](#sécurité-du-compte)
17. [Agents IA](#agents-ia)
18. [Application installable (mobile et bureau)](#application-installable-mobile-et-bureau)
19. [Glossaire](#glossaire)

## TaskR en une page

TaskR est un gestionnaire de travail collaboratif : une équipe y organise son
activité en **projets**, suit ses tâches sur un **tableau kanban**, enregistre
le **temps passé**, suit l'**avancement** de chaque projet, et conserve sa
**documentation interne** dans une base cherchable en langage naturel. Le
tout peut aussi être piloté par des **agents IA**, avec exactement les mêmes
règles que pour un humain.

Quatre idées guident le produit :

- **Organiser** : découper le travail en projets, tickets et sous-tickets,
  avec priorités, labels et échéances.
- **Suivre** : déplacer les tickets dans un workflow de statuts libre, savoir
  ce qui est en retard et qui porte quoi.
- **Piloter** : mesurer l'avancement de chaque projet et la charge de chacun.
- **Documenter et déléguer** : capitaliser la connaissance de l'équipe, et
  laisser des agents IA agir sur l'instance comme le ferait un humain.

Quelques principes qui simplifient la vie au quotidien :

- **Être membre d'un espace suffit pour quasiment tout faire** : créer et
  modifier des projets, des tickets, des labels, des pages de documentation.
  Seuls vos propres commentaires et vos propres saisies de temps vous sont
  réservés.
- **Le workflow est libre** : aucun chemin imposé entre les statuts, un
  ticket peut passer directement de « à faire » à « terminé ».
- **Rien n'est figé** : les indicateurs d'avancement se recalculent à chaque
  consultation.
- **Annuler n'est pas supprimer** : un ticket annulé reste visible (barré) et
  compte dans le total du projet ; seule la suppression l'efface pour de bon.
- **L'interface existe en français et en anglais**, au choix de chaque
  utilisateur.

## Compte et connexion

| Action | Comment |
|---|---|
| **Créer un compte** | Inscription publique : nom, e-mail, mot de passe (8 caractères minimum). Un espace de travail est créé automatiquement pour vous, avec quatre statuts de départ (Backlog, In Progress, Done, Cancelled). Vous êtes connecté·e immédiatement. |
| **Se connecter** | E-mail + mot de passe, avec une case « se souvenir de moi ». |
| **Changer de langue** | Sélecteur dans la barre latérale : French / English. Le choix est mémorisé sur votre compte. |
| **Créer un token API** | Dans *Réglages → Tokens* : un token sert à connecter un agent IA ou un script à votre compte. Sa valeur n'est affichée **qu'une seule fois** — copiez-la tout de suite. Vous pouvez le révoquer à tout moment. |

Ce qui n'est pas encore possible : modifier son nom ou son e-mail, supprimer
son compte.

## Espaces de travail, membres et invitations

Un **espace de travail** (workspace) regroupe tout ce que vous utilisez :
projets, tickets, statuts, labels, modèles, documentation. Vous pouvez
appartenir à autant d'espaces que vous voulez, et basculer de l'un à l'autre
depuis le sélecteur de la barre latérale.

- **Créer un espace** : disponible depuis le même sélecteur. Vous en devenez
  le propriétaire (Owner).
- **Inviter quelqu'un** : réservé aux Owners. Un lien d'invitation est généré
  (valable 7 jours) ; il se partage manuellement, aucun e-mail n'est envoyé
  automatiquement. L'Owner choisit le rôle (Member ou Guest, voir ci-dessous)
  et, s'il le souhaite, un périmètre de projets précis.
- **Accepter une invitation** : il faut être connecté avec l'adresse e-mail
  exacte à laquelle l'invitation a été envoyée, sinon elle est refusée avec un
  message explicite.
- **Retirer un membre** : réservé aux Owners. La personne perd l'accès à cet
  espace (ses autres espaces ne sont pas affectés) ; un espace garde toujours
  au moins un Owner.

## Rôles et permissions

TaskR distingue trois niveaux : le rôle dans l'espace de travail, le rôle
dans un projet, et la propriété d'un objet (commentaire, saisie de temps).

### Rôles d'espace de travail

| | Owner | Member | Guest |
|---|---|---|---|
| Créer et modifier projets, tickets, labels, pages de documentation | ✅ | ✅ | Seulement dans son périmètre |
| Commenter, saisir son temps | ✅ | ✅ | ✅, dans son périmètre |
| Gérer ses tokens API, changer de langue | ✅ | ✅ | ❌ |
| Gérer les membres, les statuts, les modèles de tickets | ✅ | ❌ | ❌ |
| Télécharger la sauvegarde complète de l'espace | ✅ | ❌ | ❌ |

Un espace garde toujours au moins un Owner, qui ne peut pas se retirer
lui-même.

Deux profils d'accès restreint existent, pour travailler avec des personnes
qui ne doivent voir qu'une partie de l'espace :

- **Guest** (invité) : typiquement un client ou un prestataire externe. Son
  périmètre, ce sont les projets où il est inscrit dans l'équipe. Il ne voit
  jamais la liste des membres, les labels globaux, ni les indicateurs
  globaux d'un projet (il reste sur « Ma charge »). En dehors de son
  périmètre, tout lui est simplement invisible — comme si ça n'existait pas.
- **Membre à périmètre restreint** : un collègue interne à qui l'on restreint
  la visibilité à certains projets. Contrairement au Guest, il garde toutes
  les fonctions d'un membre normal (liste des membres, labels, tokens API,
  documentation transverse, indicateurs globaux) — seule la liste des
  projets qu'il voit est limitée. Le périmètre se règle depuis la page
  Membres, par l'Owner.

### Rôles de projet

Chaque projet a sa propre équipe, avec trois rôles indépendants des rôles
d'espace : **Owner** (un seul par projet), **Manager**, **Member**. Owner et
Manager gèrent l'équipe du projet et voient les indicateurs globaux de santé
; un simple Member du projet ne voit que sa propre charge de travail.

Important : l'équipe d'un projet ne protège **pas** les tickets — n'importe
quel membre de l'espace de travail peut créer ou modifier les tickets de
tous les projets. L'équipe de projet sert au pilotage, pas à un verrouillage.

### Propriété par objet

Seuls les commentaires et les saisies de temps sont réservés à leur auteur :
vous seul·e pouvez modifier ou supprimer les vôtres. Tout le reste (tickets,
labels, pages de documentation, vues sauvegardées) peut être modifié par
n'importe quel membre.

## Projets

Un projet regroupe des tickets. Il a un nom (obligatoire, unique dans
l'espace), une description facultative, et une **date cible** facultative
qui déclenche le calcul de l'avancement attendu.

- **Créer** : tout membre de l'espace peut créer un projet ; il en devient
  automatiquement l'Owner.
- **Archiver** : action réversible en un clic — le projet disparaît de la
  liste active mais rien n'est perdu. Il reste consultable depuis le
  dialogue « Archivés », avec un bouton pour le restaurer.
- **Supprimer** : refusé tant que le projet contient au moins un ticket
  (message explicite invitant à l'archiver à la place). C'est une
  protection volontaire contre les suppressions accidentelles.

## Tickets

Le ticket (issue) est l'unité de travail de TaskR.

### Créer et éditer

Seul le titre est obligatoire. Vous pouvez créer un ticket depuis trois
endroits : la page Tickets, le tableau de bord (création rapide, titre +
projet), ou le board d'un projet (formulaire complet). **Le projet est fixé
à la création** : un ticket ne se déplace pas ensuite vers un autre projet.

Un ticket peut porter : une description en Markdown, un statut, une
priorité (aucune / basse / haute / urgente), des labels, une ou plusieurs
personnes assignées, une échéance, une estimation en heures, et un ticket
parent.

### Hiérarchie (sous-tickets)

Un ticket peut avoir des sous-tickets, sans limite de profondeur, et même
dans un autre projet. Les cycles sont impossibles (un ticket ne peut pas
être son propre ancêtre). Par défaut, le statut d'un parent et de ses
enfants évoluent indépendamment ; vous pouvez activer, sur un ticket
parent, l'option **« complétion automatique »** : quand tous ses sous-tickets
actifs sont terminés ou annulés, le parent bascule automatiquement.

### Dépendances entre tickets

Un ticket peut être **bloqué par** un ou plusieurs autres tickets (même d'un
autre projet). Un badge « bloqué » apparaît tant qu'un bloqueur est ouvert,
et « déblocable » quand ils sont tous terminés. La dépendance alerte, elle
n'empêche rien : vous pouvez toujours faire avancer un ticket bloqué si
besoin. Un filtre « Bloqué » permet de les retrouver sur la page Tickets.

### Échéances (jalons de projet)

Un projet peut définir des **échéances** (milestones) : des étapes avec un
nom, une fenêtre de dates et éventuellement une hiérarchie entre elles. Un
ticket peut être rattaché à une échéance du même projet (une seule à la
fois). La page de l'échéance montre sa progression (nombre de tickets
terminés sur le total). Gérer les échéances est réservé à l'équipe du
projet (Owner, Manager, Member) — un Guest ne peut pas en créer ni en
modifier, même s'il fait partie de l'équipe.

### Suppression et archivage

- **Supprimer** un ticket détruit définitivement ses commentaires, son
  temps saisi et ses rattachements — une confirmation énumère ce qui sera
  perdu. Ses sous-tickets ne sont pas supprimés : ils deviennent des
  tickets racines.
- **Archiver** un ticket terminé ou annulé (réservé à l'Owner de l'espace)
  le sort des vues actives (board, liste, tableau de bord) sans rien
  changer à son décompte dans l'avancement du projet. Un bouton permet
  d'archiver en masse tous les tickets terminés/annulés d'un projet. Une
  vue « Archives » par projet permet de les consulter et de les restaurer.

## Statuts et workflow

Les statuts (colonnes du board) sont définis **au niveau de l'espace de
travail** et partagés par tous les projets : le même board, dans le même
ordre, partout. Leur gestion (Réglages → Statuts) est réservée à l'Owner.

Chaque statut a un nom libre et une **catégorie sémantique** parmi quatre :
*Backlog*, *In Progress*, *Done*, *Cancelled*. C'est cette catégorie — jamais
le nom — qui pilote tous les calculs (avancement, retard, heures). Vous
pouvez avoir plusieurs statuts dans la même catégorie (par exemple « À
faire » et « Bloqué », tous deux en Backlog).

Les transitions sont entièrement libres : un ticket peut passer directement
de Backlog à Done. Le déplacement se fait par glisser-déposer sur le board,
ou via le sélecteur de la fiche du ticket.

## Commentaires et modèles de tickets

**Commentaires** : fil de discussion en Markdown sur chaque ticket, trié du
plus ancien au plus récent. Tout le monde peut en ajouter ; seul l'auteur
peut modifier ou supprimer le sien.

**Modèles de tickets (templates)** : un gabarit pour pré-remplir la création
des tickets récurrents (description, priorité et labels par défaut — jamais
le titre, le statut, l'échéance ou les assignés). Créer/modifier un modèle
est réservé à l'Owner ; tout le monde peut l'utiliser à la création d'un
ticket. Modifier ou supprimer un modèle n'affecte jamais les tickets déjà
créés avec lui.

## Suivi du temps

Le temps se suit à deux niveaux : une **estimation** prévisionnelle par
ticket, et des **saisies** réelles.

- **Estimation** : un nombre d'heures facultatif sur chaque ticket. « Pas
  d'estimation » est un état normal, affiché comme tel.
- **Saisie de temps** : heures (minimum 0,01), date du travail (aujourd'hui
  par défaut, les dates passées sont acceptées), note facultative. Chacun
  saisit toujours en son propre nom ; seul l'auteur peut modifier ou
  supprimer sa saisie.
- **Restant** = estimation − temps consommé. S'il devient négatif
  (dépassement), il s'affiche en rouge — c'est une information de pilotage,
  pas une erreur.
- **Timer** : un bouton « Démarrer le timer » sur la carte ou la fiche d'un
  ticket chronométre votre temps en direct. Vous pouvez faire tourner
  plusieurs timers en parallèle sur des tickets différents (un seul par
  ticket à la fois). À l'arrêt, une saisie de temps est proposée, pré-remplie
  avec la durée écoulée.
- **Feuille hebdomadaire** (page « Mon temps ») : une grille semaine par
  semaine, un ticket par ligne, pour saisir vos heures jour par jour en une
  seule fois. La saisie rétroactive est possible jusqu'à 8 semaines en
  arrière.

## Pilotage et santé des projets

La santé d'un projet n'est jamais stockée : elle est recalculée à chaque
consultation, à partir des tickets et du temps saisi. Elle apparaît en
résumé sur la liste des projets et le tableau de bord, et en détail sur la
page **Santé** (Health) de chaque projet.

Indicateurs principaux :

- **% de complétion** : part des tickets terminés (les annulés comptent dans
  le total mais jamais comme terminés — annuler un ticket fait donc baisser
  la complétion).
- **Tickets en retard** : échéance passée et ticket toujours ouvert.
- **Heures estimées / consommées** et **taux de consommation**, calculés
  sur les tickets ouverts.
- **Projection** : où le projet devrait en être aujourd'hui, au rythme du
  calendrier jusqu'à sa date cible — comparée à la complétion réelle, elle
  donne un statut **à jour**, **à risque** ou **en retard**.
- **Graphique d'évolution** : trois courbes (tickets ouverts / fermés /
  déplacés), sur 30 jours, 90 jours ou depuis la création du projet.
- **Charge de l'équipe** : un tableau par personne (tickets ouverts, heures,
  retards), visible des responsables du projet ; chaque ligne ouvre une
  **vue d'activité du membre** (ses tickets en cours et son historique
  récent sur ce projet).

Un simple contributeur du projet (et un Guest, même s'il gère l'équipe) ne
voit que sa propre charge, jamais les indicateurs globaux ni ceux des
autres.

## Vues, recherche et navigation

- **Page Tickets** : tous les tickets des projets actifs, en **liste**
  filtrable (recherche, statut, priorité, assigné, projet, en retard,
  bloqué) ou en **kanban transverse** (un board par projet, côte à côte).
  Les filtres vivent dans l'URL : une vue filtrée se partage ou se met en
  favoris.
- **Vues sauvegardées** : depuis une page Tickets filtrée, le bouton
  « Sauvegarder cette vue » lui donne un nom réutilisable par tout le monde
  via un sélecteur dédié. L'Owner peut renommer ou supprimer n'importe
  quelle vue ; chacun peut supprimer les siennes.
- **Board d'un projet** : une colonne par statut, glisser-déposer pour
  changer de statut, filtre « par personne », et un mode « Parents only »
  qui masque les sous-tickets pour une vue d'ensemble.
- **Tableau de bord d'accueil** : vos compteurs (tickets ouverts, en retard,
  heures de la semaine, projets actifs), la santé de vos projets actifs, et
  vos tickets assignés les plus récents.

## Base de connaissance

La base de connaissance est la mémoire documentaire de l'espace de travail :
des pages en **Markdown**, organisées en arborescence libre, optionnellement
rattachées à un projet (ou transverses, c'est-à-dire communes à tout
l'espace).

- **Recherche sémantique** : la barre de recherche accepte une question en
  langage naturel — elle trouve des pages pertinentes même sans les mots
  exacts. (Elle nécessite qu'un fournisseur de recherche soit configuré sur
  l'instance ; sinon, tout le reste — créer, lire, modifier — fonctionne
  normalement.)
- **Corbeille** : supprimer une page la met en corbeille pendant 30 jours
  (restaurable), puis elle est purgée automatiquement. Suppression refusée
  tant que la page a des sous-pages.
- **Versionnage** : chaque sauvegarde crée une nouvelle version. L'historique
  complet est consultable, chaque version peut être prévisualisée ou
  restaurée (la restauration crée elle-même une nouvelle version — rien
  n'est jamais perdu).
- **Lien avec un ticket** : depuis la fiche d'un ticket, vous pouvez lier une
  ou plusieurs pages de connaissance pertinentes (recherche intégrée). Un
  badge indique combien de pages sont liées à un ticket.

## Notifications et mentions

- **Mentions** : dans un commentaire, écrire `@` puis le nom d'une personne
  ou d'un projet (ce qui notifie toute son équipe) déclenche une alerte.
  Une page « Mentions » centralise les notifications reçues, avec un
  compteur et un marquage « lu ».
- **Digest e-mail** : un résumé périodique (quotidien ou hebdomadaire, au
  choix) envoyé par e-mail, reprenant vos tickets en retard, vos échéances
  proches, vos nouvelles assignations et les nouveaux commentaires sur vos
  tickets. Il est désactivé par défaut — à activer depuis *Réglages →
  Notifications*, où vous choisissez aussi la fréquence et le seuil
  d'échéance « proche ».

## Journal d'activité

Chaque ticket et chaque projet conserve un historique en lecture seule des
changements importants (création, changement de statut, assignation,
priorité, échéance, estimation, commentaires, saisies de temps...). Il se
consulte sous forme de chronologie sur la fiche d'un ticket, ou en vue
d'ensemble filtrable (par personne, par type) sur la page d'un projet. Rien
n'y est jamais modifiable ni supprimable.

## Exports et sauvegarde

- **Exporter les tickets** (CSV ou JSON) depuis la page Tickets : l'export
  respecte exactement les filtres actifs à l'écran.
- **Exporter les saisies de temps** (CSV ou JSON) depuis la page Santé d'un
  projet, sur une période choisie (un mois par défaut) — utile pour la
  facturation.
- **Sauvegarde complète de l'espace de travail** (réservée à l'Owner) :
  télécharge une archive contenant tous les projets, tickets, temps saisi,
  commentaires et toute la base de connaissance (avec son arborescence). Une
  façon simple de garder une copie de ses données ou de les faire migrer
  ailleurs.

## Sécurité du compte

- **Changer son mot de passe** depuis *Réglages → Compte*, en confirmant
  l'actuel.
- **Mot de passe oublié** : un lien de réinitialisation est envoyé par
  e-mail, valable une heure et à usage unique. Après réinitialisation,
  toutes vos autres sessions connectées sont automatiquement déconnectées.
- **Invitations vérifiées** : accepter une invitation exige d'être connecté
  avec l'adresse e-mail exacte visée par l'invitation — un message explicite
  s'affiche sinon, sans jamais créer d'accès non désiré.

## Agents IA

TaskR est conçu pour être piloté par des agents IA au même titre qu'un
humain : un assistant connecté agit **au nom de l'utilisateur dont il porte
le token**, avec exactement ses droits et exactement les mêmes règles
(validations, permissions, refus). Un agent ne peut jamais faire plus que la
personne au nom de laquelle il agit, ni contourner une règle métier.

Tout ce qu'un agent fait est attribué à son propriétaire, horodaté, et
visible dans l'interface au même titre qu'une action humaine. Révoquer le
token coupe immédiatement son accès.

Pour configurer un agent, voir la section correspondante du
[`README`](../README.md#driving-taskr-from-ai-agents-mcp).

## Application installable (mobile et bureau)

TaskR peut s'installer comme une application depuis votre navigateur (PWA) :
sur mobile comme sur ordinateur, elle s'ajoute à l'écran d'accueil ou au
menu des applications et s'ouvre dans sa propre fenêtre, sans onglet de
navigateur autour.

## Glossaire

| Terme | Définition |
|---|---|
| **Workspace (espace de travail)** | Le conteneur de tout : projets, tickets, statuts, labels, modèles, documentation. |
| **Project (projet)** | Regroupement de tickets, avec une date cible optionnelle. |
| **Issue (ticket)** | L'unité de travail : titre, description, priorité, échéance, labels, assignés, statut. |
| **Status (statut)** | Une colonne du workflow, partagée par tous les projets de l'espace. |
| **Label** | Un marqueur libre, rattachable à n'importe quel ticket. |
| **Template (modèle)** | Un gabarit de pré-remplissage pour créer des tickets récurrents. |
| **Milestone (échéance)** | Une étape d'un projet, à laquelle des tickets peuvent être rattachés. |
| **Knowledge page (page de connaissance)** | Une page de documentation en Markdown. |
| **Time entry (saisie de temps)** | Une déclaration d'heures travaillées sur un ticket. |
| **Guest (invité)** | Un accès externe limité aux projets de son équipe. |
| **Membre à périmètre restreint** | Un membre interne dont la liste de projets visibles est limitée, mais qui garde toutes les fonctions d'un membre complet. |

