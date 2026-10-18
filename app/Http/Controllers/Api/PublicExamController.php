<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicExamController extends Controller
{
    public function configuration(): JsonResponse
    {
        $config = DB::table('exam_configurations')->where('active', true)->first();
        abort_unless($config, 503, 'No existe un examen activo.');
        $config=$this->normalizeConfig($config);
        return response()->json([
            'name'=>$config->name, 'total_questions'=>$config->total_questions, 'duration_minutes'=>$config->duration_minutes,
            'passing_correct_answers'=>$config->passing_correct_answers, 'captcha_enabled'=>(bool)$config->captcha_enabled && (bool)config('services.recaptcha.enabled', true),
            'recaptcha_site_key'=>config('services.recaptcha.site_key'),
            'recaptcha_type'=>config('services.recaptcha.type', 'v2'),
            'recaptcha_action'=>config('services.recaptcha.enterprise_action') ?: 'START_EXAM',
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'captcha_token'=>['nullable','string'],
            'client'=>['nullable','array'],
            'mode'=>['nullable','in:exam,training'],
        ]);
        $mode=$data['mode']??'exam';
        $config = DB::table('exam_configurations')->where('active', true)->first();
        abort_unless($config, 503, 'No existe un examen activo.');
        $config=$this->normalizeConfig($config);
        $ip = $request->ip() ?? '0.0.0.0';
        $startOfDay = Carbon::now('America/Lima')->startOfDay()->utc();
        $attempts = DB::table('exam_attempts')->where('ip_address',$ip)->where('started_at','>=',$startOfDay)->count();
        if ($attempts >= $config->daily_attempt_limit) return response()->json(['message'=>'Se alcanzó el límite de intentos diarios para esta IP.'], 429);
        $captchaEnabled=(bool)$config->captcha_enabled && (bool)config('services.recaptcha.enabled', true);
        if ($captchaEnabled) $this->verifyCaptcha($data['captcha_token'] ?? '', $ip);

        [$attempt, $plainToken] = DB::transaction(function () use ($config,$request,$ip,$data,$mode,$captchaEnabled) {
            $plainToken = Str::random(80);
            $id = DB::table('exam_attempts')->insertGetId([
                'resume_token_hash'=>hash('sha256',$plainToken), 'exam_configuration_id'=>$config->id, 'mode'=>$mode, 'status'=>'in_progress',
                'started_at'=>now(), 'expires_at'=>now()->addMinutes((int)$config->duration_minutes), 'last_activity_at'=>now(),
                'ip_address'=>$ip, 'user_agent'=>$request->userAgent(), 'browser'=>data_get($data,'client.browser'),
                'browser_version'=>data_get($data,'client.browser_version'), 'operating_system'=>data_get($data,'client.operating_system'),
                'device_type'=>data_get($data,'client.device_type'), 'metadata'=>json_encode($data['client'] ?? []),
                'captcha_verified_at'=>$captchaEnabled ? now() : null, 'unanswered_questions'=>$config->total_questions,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
            $prefix=$mode==='training'?'TR':'EX';
            DB::table('exam_attempts')->where('id',$id)->update(['attempt_number'=>$prefix.'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT)]);
            $position = 1;
            $distributions = DB::table('exam_chapter_distributions')->where('exam_configuration_id',$config->id)->get();
            foreach ($distributions as $distribution) {
                $distribution->question_count=(int)$distribution->question_count;
                $questions = DB::table('questions')->where('chapter_id',$distribution->chapter_id)->where('status','active')
                    ->whereNull('deleted_at')->inRandomOrder()->limit($distribution->question_count)->get();
                if ($questions->count() !== $distribution->question_count) throw ValidationException::withMessages(['exam'=>'No existen suficientes preguntas activas en todos los capítulos.']);
                foreach ($questions as $question) {
                    $aqId = DB::table('attempt_questions')->insertGetId([
                        'exam_attempt_id'=>$id,'question_id'=>$question->id,'chapter_id'=>$question->chapter_id,
                        'learning_objective_id'=>$question->learning_objective_id,'question_snapshot'=>$question->content,
                        'comment_snapshot'=>$question->comment,'k_level_snapshot'=>$question->k_level,'position'=>$position++,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                    $options = DB::table('question_options')->where('question_id',$question->id)->where('active',true)->whereNull('deleted_at')->inRandomOrder()->get();
                    foreach ($options as $i=>$option) DB::table('attempt_options')->insert([
                        'attempt_question_id'=>$aqId,'question_option_id'=>$option->id,'option_snapshot'=>$option->content,
                        'is_correct_snapshot'=>$option->is_correct,'position'=>$i+1,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            return [DB::table('exam_attempts')->find($id),$plainToken];
        });
        return response()->json(['attempt_number'=>$attempt->attempt_number,'resume_token'=>$plainToken,'expires_at'=>$attempt->expires_at],201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $attempt = $this->attempt($request,$number);
        if ($attempt->status === 'in_progress' && now()->gte($attempt->expires_at)) { $this->finalize($attempt->id,'expired'); $attempt=DB::table('exam_attempts')->find($attempt->id); }
        $training=$attempt->mode==='training';
        $questions = DB::table('attempt_questions')->where('exam_attempt_id',$attempt->id)->orderBy('position')->get()->map(function ($q) use ($training) {
            $answered=$q->answered_at!==null;
            $q->required_selection_count=DB::table('attempt_options')->where('attempt_question_id',$q->id)->where('is_correct_snapshot',true)->count();
            $columns=['id','option_snapshot as content','position'];
            if($training&&$answered)$columns[]='is_correct_snapshot as is_correct';
            $q->options=DB::table('attempt_options')->where('attempt_question_id',$q->id)->orderBy('position')->get($columns);
            if($training&&$answered){$q->is_correct=(bool)$q->is_correct;$q->options->each(fn($option)=>$option->is_correct=(bool)$option->is_correct);}
            $q->selected_option_ids=DB::table('attempt_answers as a')->join('attempt_selected_options as s','s.attempt_answer_id','=','a.id')->where('a.attempt_question_id',$q->id)->pluck('s.attempt_option_id');
            if(!$training||!$answered)unset($q->comment_snapshot,$q->is_correct);
            return $q;
        });
        return response()->json(['attempt'=>$this->castAttemptBooleans($attempt),'questions'=>$questions]);
    }

    public function answer(Request $request, string $number, int $question): JsonResponse
    {
        $data=$request->validate(['option_ids'=>['present','array'],'option_ids.*'=>['integer','distinct'],'response_time_seconds'=>['nullable','integer','min:0']]);
        $attempt=$this->attempt($request,$number); abort_if($attempt->status!=='in_progress',409,'El examen ya fue cerrado.');
        if (now()->gte($attempt->expires_at)) { $this->finalize($attempt->id,'expired'); return response()->json(['message'=>'El tiempo terminó.','status'=>'expired'],409); }
        $aq=DB::table('attempt_questions')->where('id',$question)->where('exam_attempt_id',$attempt->id)->first(); abort_unless($aq,404);
        if($attempt->mode==='training'&&$aq->answered_at!==null)return response()->json(['message'=>'Esta respuesta ya fue comprobada y no puede modificarse.'],409);
        $valid=DB::table('attempt_options')->where('attempt_question_id',$aq->id)->pluck('id')->all();
        if (array_diff($data['option_ids'],$valid)) throw ValidationException::withMessages(['option_ids'=>'Existe una alternativa inválida.']);
        $correct=DB::table('attempt_options')->where('attempt_question_id',$aq->id)->where('is_correct_snapshot',true)->pluck('id')->sort()->values()->all();
        $selected=collect($data['option_ids'])->sort()->values()->all(); $isCorrect=$correct===$selected;
        DB::transaction(function () use ($aq,$data,$isCorrect,$attempt) {
            $answerId=DB::table('attempt_answers')->where('attempt_question_id',$aq->id)->value('id');
            $values=['is_correct'=>$isCorrect,'answered_at'=>now(),'response_time_seconds'=>$data['response_time_seconds']??null,'updated_at'=>now()];
            if ($answerId) { DB::table('attempt_answers')->where('id',$answerId)->update($values); DB::table('attempt_selected_options')->where('attempt_answer_id',$answerId)->delete(); }
            else $answerId=DB::table('attempt_answers')->insertGetId($values+['attempt_question_id'=>$aq->id,'created_at'=>now()]);
            foreach($data['option_ids'] as $optionId) DB::table('attempt_selected_options')->insert(['attempt_answer_id'=>$answerId,'attempt_option_id'=>$optionId]);
            DB::table('attempt_questions')->where('id',$aq->id)->update(['is_correct'=>$isCorrect,'answered_at'=>now(),'updated_at'=>now()]);
            DB::table('exam_attempts')->where('id',$attempt->id)->update(['last_activity_at'=>now(),'updated_at'=>now()]);
        });
        if($attempt->mode==='training')return response()->json([
            'saved'=>true,'is_correct'=>$isCorrect,'correct_option_ids'=>$correct,'comment'=>$aq->comment_snapshot,
        ]);
        return response()->json(['saved'=>true]);
    }

    public function finish(Request $request,string $number): JsonResponse
    {
        $attempt=$this->attempt($request,$number); if($attempt->status==='in_progress') $this->finalize($attempt->id,now()->gte($attempt->expires_at)?'expired':'completed');
        return $this->result($request,$number);
    }

    public function result(Request $request,string $number): JsonResponse
    {
        $attempt=$this->attempt($request,$number); abort_if($attempt->status==='in_progress',409,'El examen todavía está en curso.');
        $questions=DB::table('attempt_questions')->where('exam_attempt_id',$attempt->id)->orderBy('position')->get()->map(function($q){
            $q->required_selection_count=DB::table('attempt_options')->where('attempt_question_id',$q->id)->where('is_correct_snapshot',true)->count();
            $q->options=DB::table('attempt_options')->where('attempt_question_id',$q->id)->orderBy('position')->get(['id','option_snapshot as content','is_correct_snapshot as is_correct','position']);
            $q->is_correct=$q->is_correct===null?null:(bool)$q->is_correct;
            $q->options->each(fn($option)=>$option->is_correct=(bool)$option->is_correct);
            $q->selected_option_ids=DB::table('attempt_answers as a')->join('attempt_selected_options as s','s.attempt_answer_id','=','a.id')->where('a.attempt_question_id',$q->id)->pluck('s.attempt_option_id'); return $q;
        });
        return response()->json(['attempt'=>$this->castAttemptBooleans($attempt),'questions'=>$questions]);
    }

    private function attempt(Request $request,string $number): object
    {
        $attempt=DB::table('exam_attempts')->where('attempt_number',$number)->first(); abort_unless($attempt,404);
        $token=(string)$request->header('X-Resume-Token'); abort_unless($token && hash_equals($attempt->resume_token_hash,hash('sha256',$token)),403,'Token de reanudación inválido.');
        unset($attempt->resume_token_hash); return $attempt;
    }

    private function finalize(int $id,string $status): void
    {
        DB::transaction(function()use($id,$status){
            $attempt=DB::table('exam_attempts')->lockForUpdate()->find($id); if($attempt->status!=='in_progress')return;
            $correct=DB::table('attempt_questions')->where('exam_attempt_id',$id)->where('is_correct',true)->count();
            $answered=DB::table('attempt_questions')->where('exam_attempt_id',$id)->whereNotNull('answered_at')->count();
            $config=$this->normalizeConfig(DB::table('exam_configurations')->find($attempt->exam_configuration_id));
            DB::table('exam_attempts')->where('id',$id)->update(['status'=>$status,'finished_at'=>now(),'completion_type'=>$status,
                'correct_answers'=>$correct,'incorrect_answers'=>$answered-$correct,'unanswered_questions'=>$config->total_questions-$answered,
                'score'=>$correct,'passed'=>$correct >= $config->passing_correct_answers,'updated_at'=>now()]);
        });
    }

    private function verifyCaptcha(string $token,string $ip): void
    {
        if (config('services.recaptcha.type') === 'enterprise') {
            if (!config('services.recaptcha.site_key') || !config('services.recaptcha.enterprise_project_id') || !config('services.recaptcha.enterprise_api_key')) {
                if (app()->isProduction()) {
                    throw ValidationException::withMessages(['captcha_token'=>'CAPTCHA Enterprise no está configurado.']);
                }
                return;
            }

            if (trim($token) === '') {
                throw ValidationException::withMessages(['captcha_token'=>'Debe validar el CAPTCHA antes de iniciar.']);
            }

            $this->verifyEnterpriseCaptcha($token);
            return;
        }

        $secret=config('services.recaptcha.secret_key');
        if (!$secret) { if(app()->isProduction()) throw ValidationException::withMessages(['captcha_token'=>'CAPTCHA no está configurado.']); return; }

        if (trim($token) === '') {
            throw ValidationException::withMessages(['captcha_token'=>'Debe validar el CAPTCHA antes de iniciar.']);
        }

        $response=Http::asForm()->timeout(8)->post(config('services.recaptcha.verify_url'),['secret'=>$secret,'response'=>$token,'remoteip'=>$ip]);
        if(!$response->successful() || !$response->json('success')) throw ValidationException::withMessages(['captcha_token'=>'No se pudo validar el CAPTCHA.']);
    }

    private function verifyEnterpriseCaptcha(string $token): void
    {
        $siteKey=config('services.recaptcha.site_key');
        $projectId=config('services.recaptcha.enterprise_project_id');
        $apiKey=config('services.recaptcha.enterprise_api_key');
        $action=config('services.recaptcha.enterprise_action');

        if (!$siteKey || !$projectId || !$apiKey) {
            if (app()->isProduction()) {
                throw ValidationException::withMessages(['captcha_token'=>'CAPTCHA Enterprise no está configurado.']);
            }
            return;
        }

        $event=[
            'token'=>$token,
            'siteKey'=>$siteKey,
        ];
        if($action)$event['expectedAction']=$action;

        $response=Http::timeout(10)->post("https://recaptchaenterprise.googleapis.com/v1/projects/{$projectId}/assessments?key={$apiKey}", [
            'event'=>$event,
        ]);

        $valid=$response->successful()
            && $response->json('tokenProperties.valid') === true
            && (!$action || !$response->json('tokenProperties.action') || $response->json('tokenProperties.action') === $action);

        if (!$valid) {
            Log::warning('recaptcha_enterprise_validation_failed', [
                'http_status'=>$response->status(),
                'response'=>$response->json(),
                'expected_action'=>$action,
            ]);
            throw ValidationException::withMessages(['captcha_token'=>'No se pudo validar el CAPTCHA.']);
        }
    }

    private function castAttemptBooleans(object $attempt): object
    {
        $attempt->passed=$attempt->passed===null?null:(bool)$attempt->passed;
        return $attempt;
    }

    private function normalizeConfig(object $config): object
    {
        $config->id=(int)$config->id;
        $config->total_questions=(int)$config->total_questions;
        $config->duration_minutes=(int)$config->duration_minutes;
        $config->passing_correct_answers=(int)$config->passing_correct_answers;
        $config->daily_attempt_limit=(int)$config->daily_attempt_limit;
        $config->captcha_enabled=(bool)$config->captcha_enabled;
        return $config;
    }
}
