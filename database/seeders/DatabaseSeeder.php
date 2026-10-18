<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(['email' => 'admin@istqb.local'], [
            'name' => 'Administrador', 'password' => Hash::make('Cambiar123!'), 'status' => 'active',
        ]);
        $permissionNames = ['users.manage','questions.manage','exam.configure','reports.view','audit.view'];
        foreach ($permissionNames as $name) {
            DB::table('permissions')->updateOrInsert(['name'=>$name], ['description'=>$name,'created_at'=>now(),'updated_at'=>now()]);
        }
        $roles = [
            'Administrador'=>$permissionNames,
            'Gestor de preguntas'=>['questions.manage','exam.configure'],
            'Auditor'=>['reports.view','audit.view'],
        ];
        foreach ($roles as $roleName=>$rolePermissions) {
            DB::table('roles')->updateOrInsert(['name'=>$roleName], ['description'=>$roleName,'created_at'=>now(),'updated_at'=>now()]);
            $roleId=DB::table('roles')->where('name',$roleName)->value('id');
            foreach ($rolePermissions as $permissionName) {
                $permissionId=DB::table('permissions')->where('name',$permissionName)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]);
            }
        }
        $adminRoleId=DB::table('roles')->where('name','Administrador')->value('id');
        DB::table('user_roles')->insertOrIgnore(['user_id'=>$admin->id,'role_id'=>$adminRoleId]);

        $chapters = [
            ['1','Fundamentos del Proceso de Prueba',8], ['2','Pruebas durante el Ciclo de Vida',6],
            ['3','Pruebas Estáticas',4], ['4','Técnicas de Diseño de Pruebas',11],
            ['5','Gestión de las Actividades de Prueba',9], ['6','Herramientas de Prueba',2],
        ];
        $configurationId = DB::table('exam_configurations')->insertGetId([
            'name'=>'Simulacro ISTQB', 'total_questions'=>40, 'duration_minutes'=>60, 'passing_correct_answers'=>26,
            'daily_attempt_limit'=>10, 'show_feedback_at_end'=>true, 'captcha_enabled'=>true, 'active'=>true,
            'created_by'=>$admin->id, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        foreach ($chapters as [$code,$name,$count]) {
            $chapterId = DB::table('chapters')->insertGetId(['code'=>$code,'name'=>$name,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('exam_chapter_distributions')->insert(['exam_configuration_id'=>$configurationId,'chapter_id'=>$chapterId,'question_count'=>$count]);
        }
    }
}
