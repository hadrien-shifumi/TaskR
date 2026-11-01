<?php

namespace App\Support;

use App\Enums\MentionType;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * Parses and resolves @handle mentions from a comment body.
 *
 * Handles are extracted from the stored markdown with a lookbehind that
 * ignores emails (foo@bar.com). Resolution happens against one
 * workspace: user handles among its members, project handles among the
 * projects the author can access. A project mention is exploded into
 * one target per project member; the author never targets themselves,
 * and every recipient must be able to reach the issue the comment
 * sits on (guests only receive mentions within their roster).
 */
class MentionParser
{
    public const HANDLE_PATTERN = '/(?<![\w@])@([a-z0-9][a-z0-9_-]{1,31})/i';

    /**
     * Distinct handles, lowercased, in order of first appearance.
     *
     * @return list<string>
     */
    public static function extractHandles(string $markdown): array
    {
        preg_match_all(self::HANDLE_PATTERN, $markdown, $matches);

        $handles = array_map('strtolower', $matches[1] ?? []);

        return array_values(array_unique($handles));
    }

    /**
     * Resolve the handles of a body into mention targets.
     *
     * @param  string  $projectId  Project of the issue the comment sits on.
     * @return list<array{user: User, type: MentionType, projectId: string|null}>
     */
    public static function resolve(Workspace $workspace, User $author, string $body, string $projectId): array
    {
        $context = app(WorkspaceContext::class);

        $members = $workspace->members()
            ->get()
            ->mapWithKeys(fn (User $member) => [mb_strtolower((string) $member->handle) => $member]);

        $authorScope = $context->accessibleProjectIds($author);

        $projects = $workspace->projects()
            ->when($authorScope !== null, fn ($query) => $query->whereIn('id', $authorScope))
            ->get()
            ->mapWithKeys(fn (Project $project) => [mb_strtolower((string) $project->handle) => $project]);

        /** @var array<int, array{user: User, type: MentionType, projectId: string|null}> $targets */
        $targets = [];

        foreach (self::extractHandles($body) as $handle) {
            if ($members->has($handle)) {
                $member = $members[$handle];
                $targets[$member->getKey()] ??= ['user' => $member, 'type' => MentionType::User, 'projectId' => null];

                continue;
            }

            $project = $projects[$handle] ?? null;

            if ($project === null) {
                continue; // Unknown handle: ignored silently.
            }

            foreach ($project->members()->get() as $member) {
                $targets[$member->getKey()] ??= ['user' => $member, 'type' => MentionType::Project, 'projectId' => $project->getKey()];
            }
        }

        return array_values(array_filter(
            $targets,
            function (array $target) use ($author, $context, $projectId): bool {
                if ($target['user']->getKey() === $author->getKey()) {
                    return false;
                }

                $scope = $context->accessibleProjectIds($target['user']);

                return $scope === null || in_array($projectId, $scope, true);
            },
        ));
    }
}
