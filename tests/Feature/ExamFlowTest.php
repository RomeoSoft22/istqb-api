<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExamFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_exam_supports_exact_multi_select_scoring_and_feedback(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedQuestions();
        $started=$this->postJson('/api/public/exam/start',['client'=>['browser'=>'PHPUnit']])->assertCreated()->json();
        $headers=['X-Resume-Token'=>$started['resume_token']];
        $exam=$this->getJson('/api/public/exam/'.$started['attempt_number'],$headers)->assertOk()->json();
        $this->assertCount(40,$exam['questions']);
        $question=$exam['questions'][0];
        $this->assertSame(2,$question['required_selection_count']);
        $correctIds=DB::table('attempt_options')->where('attempt_question_id',$question['id'])->where('is_correct_snapshot',true)->pluck('id')->all();
        $this->putJson('/api/public/exam/'.$started['attempt_number'].'/questions/'.$question['id'],['option_ids'=>$correctIds],$headers)->assertOk();
        $result=$this->postJson('/api/public/exam/'.$started['attempt_number'].'/finish',[],$headers)->assertOk()->json();
        $this->assertSame(1,$result['attempt']['score']);
        $this->assertFalse((bool)$result['attempt']['passed']);
        $this->assertArrayHasKey('is_correct',$result['questions'][0]['options'][0]);
    }

    public function test_training_returns_immediate_feedback_and_locks_the_answer(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedQuestions();
        $started=$this->postJson('/api/public/exam/start',['mode'=>'training','client'=>['browser'=>'PHPUnit']])->assertCreated()->json();
        $this->assertStringStartsWith('TR-',$started['attempt_number']);
        $headers=['X-Resume-Token'=>$started['resume_token']];
        $exam=$this->getJson('/api/public/exam/'.$started['attempt_number'],$headers)->assertOk()->json();
        $this->assertSame('training',$exam['attempt']['mode']);
        $question=$exam['questions'][0];
        $correctIds=DB::table('attempt_options')->where('attempt_question_id',$question['id'])->where('is_correct_snapshot',true)->pluck('id')->all();
        $this->putJson('/api/public/exam/'.$started['attempt_number'].'/questions/'.$question['id'],['option_ids'=>$correctIds],$headers)
            ->assertOk()->assertJson(['is_correct'=>true,'comment'=>'Explicación'])->assertJsonStructure(['correct_option_ids']);
        $this->putJson('/api/public/exam/'.$started['attempt_number'].'/questions/'.$question['id'],['option_ids'=>[]],$headers)->assertStatus(409);
        $resumed=$this->getJson('/api/public/exam/'.$started['attempt_number'],$headers)->assertOk()->json();
        $this->assertArrayHasKey('is_correct',$resumed['questions'][0]['options'][0]);
        $this->assertSame('Explicación',$resumed['questions'][0]['comment_snapshot']);
    }

    public function test_admin_can_authenticate_with_jwt(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->postJson('/api/auth/login',['email'=>'admin@istqb.local','password'=>'Cambiar123!'])
            ->assertOk()->assertJsonStructure(['access_token','token_type','expires_in']);
    }

    public function test_admin_question_accepts_safe_rich_html_and_returns_real_booleans(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token=$this->postJson('/api/auth/login',['email'=>'admin@istqb.local','password'=>'Cambiar123!'])->assertOk()->json('access_token');
        $chapterId=DB::table('chapters')->value('id');
        $response=$this->withToken($token)->postJson('/api/admin/questions',[
            'chapter_id'=>$chapterId,'learning_objective_id'=>null,'k_level'=>'K2','status'=>'active','comment'=>'Comentario',
            'content'=>'<p onclick="alert(1)">Texto <strong>importante</strong></p><ul><li>Punto</li></ul><table><tr><th>Dato</th></tr></table><script>alert(1)</script><img src="/istqb-api/api/public/question-images/example.png" onerror="alert(1)">',
            'options'=>[['content'=>'Correcta','is_correct'=>true],['content'=>'Incorrecta','is_correct'=>false]],
        ])->assertOk();
        $content=$response->json('content');
        $this->assertStringContainsString('<table>',$content);
        $this->assertStringContainsString('<ul>',$content);
        $this->assertStringContainsString('/istqb-api/api/public/question-images/example.png',$content);
        $this->assertStringNotContainsString('<script',$content);
        $this->assertStringNotContainsString('onclick',$content);
        $this->assertStringNotContainsString('onerror',$content);
        $this->assertIsBool($response->json('options.0.is_correct'));
        $this->assertIsBool($response->json('options.1.is_correct'));
        $this->assertFalse($response->json('options.1.is_correct'));
    }

    public function test_admin_can_upload_and_publicly_read_a_question_image(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $token=$this->postJson('/api/auth/login',['email'=>'admin@istqb.local','password'=>'Cambiar123!'])->assertOk()->json('access_token');
        $upload=$this->withToken($token)->post('/api/admin/question-images',['image'=>UploadedFile::fake()->image('pregunta.png',40,30)])
            ->assertOk()->assertJsonStructure(['url']);
        $url=$upload->json('url');
        $this->assertStringEndsWith('.png',$url);
        $this->get($url)->assertOk()->assertHeader('Content-Type','image/png');
    }

    private function seedQuestions(): void
    {
        foreach (DB::table('exam_chapter_distributions')->get() as $distribution) {
            for ($i=0; $i<$distribution->question_count; $i++) {
                $questionId=DB::table('questions')->insertGetId(['chapter_id'=>$distribution->chapter_id,'content'=>"Pregunta {$distribution->chapter_id}-{$i}",'comment'=>'Explicación','k_level'=>'K2','status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
                foreach ([true,true,false,false] as $n=>$correct) DB::table('question_options')->insert(['question_id'=>$questionId,'content'=>'Opción '.($n+1),'is_correct'=>$correct,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }
}
