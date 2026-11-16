<?php

namespace Database\Seeders;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\DeleteKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Enums\WorkspaceRole;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Embeddings;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Seeds a French demo knowledge base into the OLDEST workspace of the
 * current database — local demo environments only, never production.
 *
 * Safety rails:
 *  - refuses to run in production;
 *  - non-destructive: existing rows are never modified or deleted — pages
 *    are created through the application's own actions (Create / Update /
 *    DeleteKnowledgePage) so versions, hierarchy and the audit journal
 *    match exactly what the UI would produce;
 *  - idempotent: pages are detected by (workspace, title) — active or
 *    trashed — and skipped on re-run;
 *  - when the embeddings endpoint is not configured (local default), the
 *    vectorization queue is parked on the null connection instead of
 *    piling doomed jobs into the database queue. Never 'sync': an inline
 *    dispatch would call the endpoint without credentials and fail the
 *    whole seed.
 *
 * Deliberately NOT using WithoutModelEvents: the workspace_id auto-fill
 * relies on the BelongsToWorkspace `creating` hook.
 */
class KnowledgeDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(
            app()->environment('production'),
            403,
            'Le seeder de démonstration Knowledge ne s\'exécute jamais en production.',
        );

        if (! app(Embeddings::class)->enabled()) {
            config(['queue.default' => 'null']);
        }

        $workspace = Workspace::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($workspace === null) {
            $this->command?->warn('KnowledgeDemoSeeder : aucun espace de travail trouvé, rien à semer.');

            return;
        }

        $editor = $this->ownerOf($workspace);

        if ($editor === null) {
            $this->command?->warn("KnowledgeDemoSeeder : l'espace « {$workspace->name} » n'a pas de propriétaire, rien à semer.");

            return;
        }

        app(WorkspaceContext::class)->set($workspace);

        $this->command?->info("KnowledgeDemoSeeder — espace ciblé : « {$workspace->name} » (éditeur : {$editor->email}).");

        $projects = Project::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->get()
            ->keyBy('name');

        $this->seedTransversePages($workspace, $editor);
        $this->seedHierarchy($workspace, $editor);
        $this->seedProjectPages($workspace, $editor, $projects);
        $this->seedTrash($workspace, $editor, $projects);

        $this->command?->info('KnowledgeDemoSeeder : terminé.');
    }

    private function ownerOf(Workspace $workspace): ?User
    {
        return $workspace->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->first()
            ?->user;
    }

    /**
     * Create a demo page through the application action, or recognize an
     * existing one (active or trashed) to stay idempotent.
     *
     * @param  array{title: string, body: string, project_id?: string|null, parent_id?: string|null}  $data
     * @return array{0: KnowledgePage, 1: bool} The page and whether it was freshly created.
     */
    private function createPage(Workspace $workspace, User $editor, array $data): array
    {
        $existing = KnowledgePage::withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('title', $data['title'])
            ->first();

        if ($existing !== null) {
            $this->command?->line("  = « {$data['title']} » existe déjà, ignorée.");

            return [$existing, false];
        }

        $page = CreateKnowledgePage::run($workspace, $editor, $data);
        $this->command?->info("  + « {$data['title']} » créée.");

        return [$page, true];
    }

    private function seedTransversePages(Workspace $workspace, User $editor): void
    {
        [$guide, $created] = $this->createPage($workspace, $editor, [
            'title' => 'Guide de démarrage',
            'body' => $this->guideBodyV1(),
        ]);

        if ($created) {
            // Two edits after the creation: the history shows three versions.
            UpdateKnowledgePage::run($guide, $editor, [
                'title' => 'Guide de démarrage',
                'body' => $this->guideBodyV2(),
            ]);
            UpdateKnowledgePage::run($guide, $editor, [
                'title' => 'Guide de démarrage',
                'body' => $this->guideBodyV3(),
            ]);
            $this->command?->line('  + « Guide de démarrage » : 2 révisions ajoutées (3 versions).');
        }

        $this->createPage($workspace, $editor, [
            'title' => 'Glossaire',
            'body' => $this->glossaryBody(),
        ]);
    }

    /**
     * Root manual with children and grandchildren: the collapsible tree,
     * the "Sous-pages" section and the breadcrumb all get a workout.
     */
    private function seedHierarchy(Workspace $workspace, User $editor): void
    {
        [$root] = $this->createPage($workspace, $editor, [
            'title' => "Manuel de l'équipe",
            'body' => $this->teamManualBody(),
        ]);

        [$process] = $this->createPage($workspace, $editor, [
            'title' => 'Processus de développement',
            'body' => $this->developmentProcessBody(),
            'parent_id' => $root->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Revue de code',
            'body' => $this->codeReviewBody(),
            'parent_id' => $process->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Gestion des branches',
            'body' => $this->branchingBody(),
            'parent_id' => $process->id,
        ]);

        [$rituals] = $this->createPage($workspace, $editor, [
            'title' => "Rituels d'équipe",
            'body' => $this->ritualsBody(),
            'parent_id' => $root->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Rétrospectives',
            'body' => $this->retrospectivesBody(),
            'parent_id' => $rituals->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Checklists de release',
            'body' => $this->releaseChecklistBody(),
            'parent_id' => $root->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Outils et accès',
            'body' => $this->toolsBody(),
            'parent_id' => $root->id,
        ]);
    }

    /**
     * @param  Collection<string, Project>  $projects
     */
    private function seedProjectPages(Workspace $workspace, User $editor, Collection $projects): void
    {
        $this->createPage($workspace, $editor, [
            'title' => 'Brief de la refonte du site',
            'body' => $this->websiteBriefBody(),
            'project_id' => $projects->get('Website Redesign')?->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Décisions techniques — application mobile',
            'body' => $this->mobileDecisionsBody(),
            'project_id' => $projects->get('Mobile App')?->id,
        ]);

        $this->createPage($workspace, $editor, [
            'title' => 'Journal de bord TaskR App',
            'body' => $this->taskrLogbookBody(),
            'project_id' => $projects->get('TaskR App')?->id,
        ]);
    }

    /**
     * Two trash entries with different reasons: a manual delete of a
     * childless page, and a "cascade project" delete replicating the
     * DeleteProject pattern (trash, then detach from the project so the
     * FK cascade never hard-deletes the trashed row).
     *
     * @param  Collection<string, Project>  $projects
     */
    private function seedTrash(Workspace $workspace, User $editor, Collection $projects): void
    {
        [$faq, $faqCreated] = $this->createPage($workspace, $editor, [
            'title' => 'Ancienne FAQ',
            'body' => $this->oldFaqBody(),
        ]);

        if ($faqCreated) {
            DeleteKnowledgePage::run($faq, $editor, 'manual');
            $this->command?->line('  + « Ancienne FAQ » mise à la corbeille (raison : manual).');
        }

        $prototypeProject = $projects->get('Website Redesign') ?? $projects->first();

        [$prototype, $prototypeCreated] = $this->createPage($workspace, $editor, [
            'title' => 'Documentation du prototype',
            'body' => $this->prototypeBody(),
            'project_id' => $prototypeProject?->id,
        ]);

        if ($prototypeCreated) {
            $this->trashForProjectCascade($prototype, $editor);
            $this->command?->line('  + « Documentation du prototype » mise à la corbeille (raison : cascade_project).');
        }
    }

    /**
     * Replicates DeleteProject::moveKnowledgePagesToTrash for a single
     * page: metadata, soft delete, then detach from the project.
     */
    private function trashForProjectCascade(KnowledgePage $page, User $editor): void
    {
        $page->forceFill([
            'deleted_by_id' => $editor->id,
            'deleted_reason' => 'cascade_project',
        ])->save();
        $page->delete();

        KnowledgePage::withoutWorkspaceScope()
            ->withTrashed()
            ->whereKey($page->getKey())
            ->update(['project_id' => null]);
    }

    // -------------------------------------------------------------------------
    // Demo content (French)
    // -------------------------------------------------------------------------

    private function guideBodyV1(): string
    {
        return <<<'MARKDOWN'
        # Guide de démarrage

        Bienvenue dans l'espace ! Ce guide rassemble tout ce qu'il faut savoir pour travailler efficacement au quotidien. Il est découpé en sections courtes : utilisez le sommaire « Sur cette page » pour naviguer.

        ## Bienvenue dans l'espace

        L'espace réunit vos projets, vos issues et votre documentation au même endroit. Chaque membre y retrouve :

        - ses **projets** actifs et archivés ;
        - ses **issues** assignées, triées par priorité ;
        - la **base de connaissances**, partagée ou rattachée à un projet.

        ## Organiser son travail

        ### Les projets

        Un projet représente une initiative : un site à refondre, une application à lancer, une campagne à orchestrer. Ouvrez un projet pour voir son tableau, sa santé et ses membres.

        ### Les issues et sous-tâches

        Une issue est une unité de travail. Découpez les sujets larges en sous-tâches : la partie « parent » sert d'épique et suit l'avancement de ses enfants.

        1. Créez l'issue parente avec une description claire.
        2. Ajoutez les sous-tâches, une par livrable.
        3. Assignez les personnes concernées.
        4. Estimez chaque sous-tâche en heures.

        ## Suivre l'avancement

        ### Les statuts

        | Statut | Signification | Usage |
        | --- | --- | --- |
        | Backlog | Pas encore planifié | Idées et besoins |
        | In Progress | Travail en cours | Maximum une par personne |
        | In Review | En attente de relecture | Revue de code ou de contenu |
        | Done | Terminé | Critères d'acceptation validés |

        ### Les priorités

        Quatre niveaux : *Low*, *Medium*, *High* et *Urgent*. Une issue urgente avec une échéance dépassée apparaît en rouge sur le tableau.

        ### Une bonne description d'issue

        Le contexte, la reproduction, l'attendu — dans cet ordre :

        ```
        ## Contexte
        Le menu déborde de l'écran sous 640px.

        ## Reproduction
        1. Ouvrir le site sur un mobile
        2. Ouvrir le menu

        ## Attendu
        Le menu tient dans l'écran.
        ```

        ## Collaborer

        ### Commentaires et mentions

        > Astuce : mentionnez un collègue avec `@` pour le notifier, même s'il n'est pas assigné à l'issue.

        Les commentaires conservent l'historique des échanges : pas besoin de réunions pour tout trancher.

        ### Le suivi de temps

        Chaque session de travail peut être journalée avec une note courte. Le temps journalé alimente la consommation estimée du projet.

        ## La base de connaissances

        ### L'arborescence

        Les pages peuvent s'imbriquer : une racine, des enfants, des petits-enfants. L'arbre se replie et se déplie, et la page ouverte se déplie automatiquement dans le fil d'Ariane.

        ### La recherche

        La recherche couvre les titres et les contenus. Quand la recherche sémantique est configurée, elle trouve aussi les pages par sens — pas seulement par mots-clés.

        ## Vos premiers pas

        - [ ] Lire ce guide jusqu'au bout
        - [ ] Rejoindre un projet et ouvrir son tableau
        - [ ] Créer votre première issue
        - [ ] Écrire une page de documentation
        MARKDOWN;
    }

    private function guideBodyV2(): string
    {
        return str($this->guideBodyV1())
            ->replace(
                '## Vos premiers pas',
                "## Astuces et raccourcis clavier\n\n".
                "| Raccourci | Action |\n".
                "| --- | --- |\n".
                "| `n` | Nouvelle issue |\n".
                "| `/` | Recherche |\n".
                "| `?` | Aide-mémoire |\n\n".
                "Les raccourcis fonctionnent sur toutes les surfaces : tableau, liste et base de connaissances.\n\n".
                '## Vos premiers pas',
            )
            ->toString();
    }

    private function guideBodyV3(): string
    {
        return str($this->guideBodyV2())
            ->replace(
                '## Vos premiers pas',
                "## FAQ rapide\n\n".
                "**Qui peut voir les pages transverses ?** Tous les membres complets de l'espace ; les invités ne voient que les pages de leur projet.\n\n".
                "**Une page supprimée est-elle perdue ?** Non : elle reste 30 jours dans la corbeille, restaurable en un clic.\n\n".
                "**Peut-on lier une page à une issue ?** Oui, depuis l'issue comme depuis la page — le lien apparaît des deux côtés.\n\n".
                '## Vos premiers pas',
            )
            ->toString();
    }

    private function glossaryBody(): string
    {
        return <<<'MARKDOWN'
        # Glossaire

        Les termes utilisés dans l'espace, en un coup d'œil. Un terme manque ? Ajoutez-le directement à la table.

        | Terme | Définition |
        | --- | --- |
        | Issue | Unité de travail suivie dans un projet : bug, fonctionnalité, tâche. |
        | Épique | Issue parente qui regroupe des sous-tâches et suit leur avancement. |
        | Milestone | Jalon : un ensemble d'issues regroupées derrière une échéance. |
        | Statut | Étape du flux de travail (Backlog, In Progress, In Review, Done…). |
        | Corbeille | Zone temporaire où les pages supprimées restent 30 jours. |
        | Version | Instantané immuable d'une page, conservé dans son historique. |
        | Page transverse | Page de connaissances rattachée à l'espace, pas à un projet. |
        | Cascade | Suppression en chaîne : supprimer un projet met ses pages à la corbeille. |

        > Les définitions restent courtes : le détail appartient aux pages dédiées du manuel.
        MARKDOWN;
    }

    private function teamManualBody(): string
    {
        return <<<'MARKDOWN'
        # Manuel de l'équipe

        Tout ce qui fait notre façon de travailler, réuni au même endroit. Ce manuel est la racine de la documentation interne : chaque section est une sous-page que chacun peut faire évoluer.

        ## Comment utiliser ce manuel

        - L'arbre à gauche se replie et se déplie : gardez ouvertes les sections du moment.
        - La page ouverte est mise en évidence et dépliée automatiquement.
        - Le fil d'Ariane en haut de page montre toujours où vous en êtes.

        ## Les sections

        - **Processus de développement** — du besoin exprimé à la mise en production.
        - **Rituels d'équipe** — les rendez-vous qui structurent la semaine.
        - **Checklists de release** — rien ne part sans elles.
        - **Outils et accès** — qui a quoi, et comment l'obtenir.

        > Une section manque ? Créez une sous-page depuis cette page : le bouton « Nouvelle sous-page » est fait pour ça.
        MARKDOWN;
    }

    private function developmentProcessBody(): string
    {
        return <<<'MARKDOWN'
        # Processus de développement

        Du besoin exprimé à la mise en production, le chemin est toujours le même. Les deux sous-pages détaillent les points de passage obligés : la revue de code et la gestion des branches.

        ## Les étapes

        1. **Cadrage** — l'issue est rédigée, découpée, priorisée.
        2. **Réalisation** — une branche par sujet, des commits petits.
        3. **Revue** — une autre paire d'yeux relit chaque changement.
        4. **Validation** — les critères d'acceptation sont cochés.
        5. **Livraison** — la checklist de release est parcourue en entier.

        ## Les règles d'or

        - Une issue en cours par personne et par projet : le reste attend en Backlog.
        - Une issue bloquée remonte immédiatement en commentaire, avec le blocage nommé.
        - Pas de changement direct en production, jamais — même « juste une ligne ».
        MARKDOWN;
    }

    private function codeReviewBody(): string
    {
        return <<<'MARKDOWN'
        # Revue de code

        La revue n'est pas un filtre : c'est un partage de contexte. Elle protège l'auteur autant que le projet.

        ## Ce qu'on attend d'une revue

        - [ ] Le but du changement est compréhensible sans explication orale.
        - [ ] Les tests couvrent le comportement ajouté ou corrigé.
        - [ ] Pas de secret, pas de débogage, pas de code mort.
        - [ ] Les noms disent ce que le code fait.

        ## Les délais

        Une demande de revue est traitée sous 24 heures ouvrées. Un blocage urgent se signale en direct, pas en commentaire.

        > Relisez-vous comme vous aimeriez être relu : précis sur le code, bienveillant sur la forme.
        MARKDOWN;
    }

    private function branchingBody(): string
    {
        return <<<'MARKDOWN'
        # Gestion des branches

        Une branche par sujet, nommée d'après l'issue qu'elle réalise.

        ## Nommer les branches

        ```
        feat/123-redesign-hero
        fix/456-nav-overlap-mobile
        docs/789-onboarding-guide
        ```

        Le préfixe donne l'intention, le numéro relie l'issue, le reste décrit le sujet en quelques mots.

        ## Les règles

        - `main` est toujours déployable : aucune poussée directe.
        - Les branches vivent peu : quelques jours, pas quelques semaines.
        - Rebaser avant d'ouvrir la revue plutôt que d'empiler les fusions.
        MARKDOWN;
    }

    private function ritualsBody(): string
    {
        return <<<'MARKDOWN'
        # Rituels d'équipe

        Les rendez-vous qui structurent la semaine. Chacun est court, préparé, et se termine par des décisions écrites.

        ## La semaine type

        | Rituel | Quand | Durée | Objectif |
        | --- | --- | --- | --- |
        | Point quotidien | Chaque matin | 10 min | Blocages et priorités du jour |
        | Revue hebdo | Lundi | 45 min | L'avancement des projets |
        | Rétrospective | Vendredi (quinze) | 60 min | Ce qu'on améliore la semaine suivante |

        ## Ce qui n'est pas négociable

        - Les décisions prises en rituel sont écrites le jour même, dans la page du projet.
        - Un rituel sans ordre du jour préparé est reporté.
        MARKDOWN;
    }

    private function retrospectivesBody(): string
    {
        return <<<'MARKDOWN'
        # Rétrospectives

        Une rétrospective ne sert pas à se plaindre : elle sert à choisir **une** amélioration et à la suivre.

        ## Le format

        1. **Ce qui a bien marché** — à garder tel quel.
        2. **Ce qui a coincé** — sans chercher de coupable.
        3. **Une action** — nommée, assignée, datée.

        ## Le suivi

        L'action choisie rejoint le Backlog du projet comme une issue ordinaire. La rétrospective suivante commence par son point : faite ou pas, et pourquoi.
        MARKDOWN;
    }

    private function releaseChecklistBody(): string
    {
        return <<<'MARKDOWN'
        # Checklists de release

        Rien ne part sans que cette liste soit parcourue en entier. Chaque case cochée est un incident évité.

        ## Avant la mise en production

        - [ ] Toute la suite de tests passe, y compris les parcours de bout en bout.
        - [ ] Les migrations sont réversibles et testées sur une copie des données.
        - [ ] Les variables d'environnement nouvelles sont documentées.
        - [ ] Les changements visibles sont annoncés aux utilisateurs.

        ## Après la mise en production

        - [ ] Les journaux d'erreur restent calmes pendant 30 minutes.
        - [ ] Les métriques clés ne bougent pas anormalement.
        - [ ] L'équipe sait qui surveille quoi, et jusqu'à quand.

        > Un retour arrière n'est pas un échec : c'est une décision. Le critère, c'est le temps de rétablissement.
        MARKDOWN;
    }

    private function toolsBody(): string
    {
        return <<<'MARKDOWN'
        # Outils et accès

        Qui a accès à quoi, et comment demander ce qui manque.

        ## L'inventaire

        | Outil | Usage | Qui demander |
        | --- | --- | --- |
        | Dépôt de code | Sources et revues | Le responsable du projet |
        | Hébergement | Environnements de recette et production | L'administrateur de l'espace |
        | Messagerie | Échanges quotidiens | Tout le monde, sur invitation |
        | Maquettes | Design et prototypes | L'équipe design |

        ## Demander un accès

        1. Ouvrez une issue dans le projet concerné, avec l'outil et la raison.
        2. Le responsable valide et fournit l'accès.
        3. L'accès est retiré à la fin du besoin — pas « au cas où ».
        MARKDOWN;
    }

    private function websiteBriefBody(): string
    {
        return <<<'MARKDOWN'
        # Brief de la refonte du site

        Tout ce que l'équipe doit savoir avant de toucher au site public. Ce document fait foi : en cas de doute, c'est ici que ça se tranche.

        ## Contexte

        Le site actuel date d'avant la nouvelle identité visuelle. Les textes sont obsolètes, le parcours mobile casse, et le temps de chargement de la page d'accueil dépasse nos objectifs.

        ## Objectifs

        - [ ] Une page d'accueil qui présente la valeur en moins de 5 secondes.
        - [ ] Un parcours mobile sans chevauchement de navigation.
        - [ ] Un temps de chargement perçu sous 2 secondes.
        - [ ] Des textes à jour, relus par le marketing.

        ## Périmètre

        | Inclus | Exclu |
        | --- | --- |
        | Page d'accueil et navigation | Le blog (migration séparée) |
        | Pages produit | L'espace client |
        | Pied de page et mentions | Le multilingue |

        > Toute demande hors périmètre passe par une nouvelle issue, pas par une extension silencieuse du brief.
        MARKDOWN;
    }

    private function mobileDecisionsBody(): string
    {
        return <<<'MARKDOWN'
        # Décisions techniques — application mobile

        Les choix d'architecture de l'application, avec leur raison. Chaque décision est datée : une décision ancienne peut être revisitée, mais pas discrètement.

        ## Décisions actives

        ### Lecteur hors ligne

        Les articles enregistrés sont mis en cache localement et la progression de lecture se synchronise au retour du réseau. Décision du 12 mars : le mode hors ligne passe avant les notifications dans la file des priorités.

        ### Jetons de notification

        Le renouvellement des jetons de notification est géré côté application, avec une régénération silencieuse en cas d'expiration.

        ```
        if (token.isExpired()) {
            await renewToken(silently: true);
        }
        ```

        ## Décisions abandonnées

        > Le stockage des articles en base distante a été abandonné : trop de latence au premier lancement, et un mode hors ligne plus complexe que le problème qu'il prétendait résoudre.
        MARKDOWN;
    }

    private function taskrLogbookBody(): string
    {
        return <<<'MARKDOWN'
        # Journal de bord TaskR App

        Ce qui s'est passé, semaine après semaine. Les décisions détaillées vivent dans les issues : ce journal garde le fil narratif.

        ## Semaine 45

        - L'arbre des pages de connaissances se replie et se déplie correctement.
        - Le sommaire « Sur cette page » est en place sur les pages longues.
        - La corbeille conserve les pages supprimées 30 jours.

        ## Semaine 44

        - Première version de la recherche enrichie.
        - Les raccourcis clavier arrivent sur la base de connaissances.

        > Ce journal se lit du bas vers le haut : la semaine la plus récente est en premier.
        MARKDOWN;
    }

    private function oldFaqBody(): string
    {
        return <<<'MARKDOWN'
        # Ancienne FAQ

        Cette FAQ datait de la première version de l'espace. Les réponses ont migré dans le guide de démarrage et le glossaire.

        ## Questions traitées ailleurs

        - Comment créer une issue ? → Guide de démarrage, section « Organiser son travail ».
        - Que veut dire « épique » ? → Glossaire.
        - Où vont les pages supprimées ? → Glossaire, entrée « Corbeille ».
        MARKDOWN;
    }

    private function prototypeBody(): string
    {
        return <<<'MARKDOWN'
        # Documentation du prototype

        Notes prises pendant le prototypage de la page d'accueil. Le prototype a été abandonné au profit d'une refonte complète : cette documentation reste en corbeille au cas où.

        ## Ce que montrait le prototype

        - Un carrousel d'accueil, jugé trop lent à charger.
        - Une navigation latérale, jugée confuse sur mobile.

        ## Pourquoi c'est arrêté

        Le carrousel doublait le temps de chargement pour un gain de clarté nul. La navigation latérale est revenue, plus simple, dans la refonte.
        MARKDOWN;
    }
}
