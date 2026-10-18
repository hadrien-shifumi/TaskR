<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The perimeter announced to a guest at invitation time: the roster
        // rows are created from these links when the invitation is accepted.
        Schema::create('invitation_project', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('invitation_id');
            $table->foreign('invitation_id')->references('id')->on('invitations')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['invitation_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_project');
    }
};
