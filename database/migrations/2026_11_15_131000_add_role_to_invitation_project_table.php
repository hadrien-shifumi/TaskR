<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_project', function (Blueprint $table) {
            // D16: each announced project carries the project role the
            // invitee will land with at acceptance. Pending invitations
            // at deployment convert naturally — the default is the plain
            // member role their acceptance produced before the rework.
            $table->string('role')->default('member')->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_project', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
