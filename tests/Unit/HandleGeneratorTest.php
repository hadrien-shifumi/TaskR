<?php

namespace Tests\Unit;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Support\HandleGenerator;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_a_slug_from_the_name(): void
    {
        $this->assertSame('hadrien-delphin', HandleGenerator::forUser('Hadrien Delphin'));
        $this->assertSame('site-vitrine', HandleGenerator::forUser('Site Vitrine'));
    }

    public function test_appends_a_numeric_suffix_on_collision(): void
    {
        User::factory()->create(['name' => 'Hadrien Delphin', 'handle' => 'hadrien-delphin']);

        $this->assertSame('hadrien-delphin-2', HandleGenerator::forUser('Hadrien Delphin'));

        User::factory()->create(['name' => 'Hadrien Delphin II', 'handle' => 'hadrien-delphin-2']);

        $this->assertSame('hadrien-delphin-3', HandleGenerator::forUser('Hadrien Delphin'));
    }

    public function test_falls_back_to_a_generic_handle_when_the_name_is_unusable(): void
    {
        $this->assertSame('user', HandleGenerator::forUser('***'));
        $this->assertSame('user', HandleGenerator::forUser('张伟'));
    }

    public function test_the_fallback_handle_also_deduplicates(): void
    {
        User::factory()->create(['name' => 'Ghost', 'handle' => 'user']);

        $this->assertSame('user-2', HandleGenerator::forUser('***'));
    }

    public function test_generated_handles_never_exceed_32_characters(): void
    {
        $handle = HandleGenerator::forUser(str_repeat('a b c d e ', 10));

        $this->assertLessThanOrEqual(32, strlen($handle));
    }

    public function test_project_handles_are_unique_per_workspace_only(): void
    {
        $owner = User::factory()->create(['name' => 'Owner', 'handle' => 'owner']);
        $workspaceA = CreateWorkspace::run($owner, ['name' => 'WS A']);
        $workspaceB = CreateWorkspace::run($owner, ['name' => 'WS B']);

        app(WorkspaceContext::class)->set($workspaceA);
        $projectA = CreateProject::run($workspaceA, $owner, ['name' => 'Site Vitrine']);

        $this->assertSame('site-vitrine', $projectA->handle);

        app(WorkspaceContext::class)->set($workspaceB);
        $projectB = CreateProject::run($workspaceB, $owner, ['name' => 'Site Vitrine']);

        // Same handle in a different workspace is fine: uniqueness is scoped.
        $this->assertSame('site-vitrine', $projectB->handle);

        app(WorkspaceContext::class)->set($workspaceA);
        $this->assertSame('site-vitrine-2', HandleGenerator::forProject('Site Vitrine', $workspaceA->id));
    }

    public function test_project_falls_back_to_a_generic_handle(): void
    {
        $owner = User::factory()->create(['name' => 'Owner', 'handle' => 'owner']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->assertSame('project', HandleGenerator::forProject('***', $workspace->id));
    }
}
