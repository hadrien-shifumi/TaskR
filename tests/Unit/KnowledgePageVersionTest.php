<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KnowledgePageVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_is_immutable(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        // A version is a point-in-time snapshot: no updated_at column.
        $this->assertFalse(Schema::hasColumn('knowledge_page_versions', 'updated_at'));

        $version = KnowledgePageVersion::factory()->create([
            'editor_id' => $owner->id,
        ]);

        $this->assertFalse($version->timestamps);
        $this->assertNull($version->updated_at);

        // Saving again must not create or alter an updated_at value.
        $version->title = 'Changed title';
        $version->save();

        $this->assertNull($version->fresh()->updated_at);
    }
}
