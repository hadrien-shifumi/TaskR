<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_embedding_settings', function (Blueprint $table) {
            $table->string('chat_base_url')->nullable();
            $table->text('chat_api_key')->nullable();
            $table->string('chat_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_embedding_settings', function (Blueprint $table) {
            $table->dropColumn(['chat_base_url', 'chat_api_key', 'chat_model']);
        });
    }
};
