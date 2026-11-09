<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The project perimeter granted to a scoped member. It lives on the
        // membership — never on the project roster, which stays the real
        // project team and must not restrict a classic member's visibility.
        Schema::create('membership_project', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('membership_id');
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_project');
    }
};
