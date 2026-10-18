<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->string('mode', 20)->default('exam')->after('exam_configuration_id')->index();
        });

        $permissions = ['users.manage', 'questions.manage', 'exam.configure', 'reports.view', 'audit.view'];
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission],
                ['description' => $permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $roles = [
            'Administrador' => $permissions,
            'Gestor de preguntas' => ['questions.manage', 'exam.configure'],
            'Auditor' => ['reports.view', 'audit.view'],
        ];
        foreach ($roles as $roleName => $rolePermissions) {
            DB::table('roles')->updateOrInsert(
                ['name' => $roleName],
                ['description' => $roleName, 'created_at' => now(), 'updated_at' => now()],
            );
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            foreach ($rolePermissions as $permission) {
                $permissionId = DB::table('permissions')->where('name', $permission)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};
