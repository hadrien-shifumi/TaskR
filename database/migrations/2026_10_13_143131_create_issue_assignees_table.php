<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_assignees', function (Blueprint $table) {
            $table->ulid('issue_id');
            $table->foreignId('user_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->primary(['issue_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_assignees');
    }
};
