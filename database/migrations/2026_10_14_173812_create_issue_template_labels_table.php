<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_template_labels', function (Blueprint $table) {
            $table->ulid('issue_template_id');
            $table->ulid('label_id');
            $table->foreign('issue_template_id')->references('id')->on('issue_templates')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
            $table->primary(['issue_template_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_template_labels');
    }
};
