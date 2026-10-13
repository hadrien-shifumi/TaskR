<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        DB::statement("ALTER TABLE statuses ADD CONSTRAINT statuses_category_check CHECK (category IN ('backlog', 'in_progress', 'done', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
