<?php

use App\Models\User;
use App\Support\HandleGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 32)->nullable();
        });

        // Backfill in PHP so existing users get exactly the handles a
        // fresh create would derive from their names.
        User::query()
            ->whereNull('handle')
            ->orderBy('id')
            ->get()
            ->each(function (User $user): void {
                $user->handle = HandleGenerator::forUser($user->name);
                $user->save();
            });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('handle');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['handle']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('handle');
        });
    }
};
