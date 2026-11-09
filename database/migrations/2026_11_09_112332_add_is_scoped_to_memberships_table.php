<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The explicit "scoped" marker distinguishes a member restricted to
        // a project perimeter from the default, unrestricted member: rows
        // alone cannot tell "no perimeter because unrestricted" from
        // "restricted to an empty perimeter".
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('is_scoped')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('is_scoped');
        });
    }
};
