<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('gray');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
