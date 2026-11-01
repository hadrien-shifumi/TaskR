<?php

namespace Database\Factories;

use App\Enums\MentionType;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommentMention>
 */
class CommentMentionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'comment_id' => Comment::factory(),
            'user_id' => User::factory(),
            'type' => MentionType::User,
            'project_id' => null,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    public function projectMention(): static
    {
        return $this->state(fn () => [
            'type' => MentionType::Project,
            'project_id' => Project::factory(),
        ]);
    }
}
