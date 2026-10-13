<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_labels', function (Blueprint $table) {
            $table->ulid('issue_id');
            $table->ulid('label_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
            $table->primary(['issue_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_labels');
    }
};
