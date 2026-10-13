<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('issue_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreignId('author_id');
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
