<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_dependencies', function (Blueprint $table) {
            $table->ulid('issue_id');
            $table->ulid('blocked_by_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('blocked_by_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->primary(['issue_id', 'blocked_by_id']);
            $table->index('blocked_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_dependencies');
    }
};
