<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_knowledge_pages', function (Blueprint $table) {
            $table->ulid('issue_id');
            $table->ulid('knowledge_page_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('knowledge_page_id')->references('id')->on('knowledge_pages')->cascadeOnDelete();
            $table->primary(['issue_id', 'knowledge_page_id']);
            $table->index('knowledge_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_knowledge_pages');
    }
};
