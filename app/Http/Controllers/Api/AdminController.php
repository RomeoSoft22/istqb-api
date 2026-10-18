<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'attempts'=>DB::table('exam_attempts')->count(),
            'completed'=>DB::table('exam_attempts')->where('status','completed')->count(),
            'expired'=>DB::table('exam_attempts')->where('status','expired')->count(),
            'in_progress'=>DB::table('exam_attempts')->where('status','in_progress')->count(),
            'passed'=>DB::table('exam_attempts')->where('passed',true)->count(),
            'questions'=>DB::table('questions')->whereNull('deleted_at')->count(),
        ]);
    }

    public function catalogs(): JsonResponse
    {
        $chapters=DB::table('chapters')->whereNull('deleted_at')->orderBy('code')->get();
        foreach($chapters as $chapter)$chapter->learning_objectives=DB::table('learning_objectives')->where('chapter_id',$chapter->id)->whereNull('deleted_at')->orderBy('name')->get();
        return response()->json([
            'chapters'=>$chapters,
            'k_levels'=>[['code'=>'K1','name'=>'Recordar'],['code'=>'K2','name'=>'Comprender'],['code'=>'K3','name'=>'Aplicar']],
            'roles'=>DB::table('roles')->orderBy('name')->get(['id','name','description']),
        ]);
    }

    public function saveChapter(Request $request, ?int $id=null): JsonResponse
    {
        $data=$request->validate(['code'=>['required','string','max:20',Rule::unique('chapters')->ignore($id)],'name'=>['required','string','max:255'],'description'=>['nullable','string'],'active'=>['required','boolean']]);
        $values=$data+['updated_at'=>now()];
        if($id)DB::table('chapters')->where('id',$id)->update($values);else$id=DB::table('chapters')->insertGetId($values+['created_at'=>now()]);
        $this->audit($request,'chapter.saved','chapters',$id);return response()->json(DB::table('chapters')->find($id));
    }

    public function saveObjective(Request $request, ?int $id=null): JsonResponse
    {
        $data=$request->validate(['chapter_id'=>['required','exists:chapters,id'],'code'=>['nullable','string','max:50'],'name'=>['required','string','max:255'],'description'=>['nullable','string'],'active'=>['required','boolean']]);
        $values=$data+['updated_at'=>now()];if($id)DB::table('learning_objectives')->where('id',$id)->update($values);else$id=DB::table('learning_objectives')->insertGetId($values+['created_at'=>now()]);
        $this->audit($request,'objective.saved','learning_objectives',$id);return response()->json(DB::table('learning_objectives')->find($id));
    }

    public function configuration(): JsonResponse
    {
        $config=DB::table('exam_configurations')->where('active',true)->first();abort_unless($config,404);
        $config->distributions=DB::table('exam_chapter_distributions as d')->join('chapters as c','c.id','=','d.chapter_id')->where('d.exam_configuration_id',$config->id)->get(['d.chapter_id','d.question_count','c.name']);
        return response()->json($config);
    }

    public function saveConfiguration(Request $request): JsonResponse
    {
        $data=$request->validate(['name'=>['required','string'],'total_questions'=>['required','integer','min:1'],'duration_minutes'=>['required','integer','min:1'],
            'passing_correct_answers'=>['required','integer','min:1'],'daily_attempt_limit'=>['required','integer','min:1'],'captcha_enabled'=>['required','boolean'],
            'distributions'=>['required','array','min:1'],'distributions.*.chapter_id'=>['required','exists:chapters,id'],'distributions.*.question_count'=>['required','integer','min:0']]);
        if(collect($data['distributions'])->sum('question_count')!==$data['total_questions'])throw ValidationException::withMessages(['distributions'=>'La distribución por capítulos debe sumar el total de preguntas.']);
        if($data['passing_correct_answers']>$data['total_questions'])throw ValidationException::withMessages(['passing_correct_answers'=>'La nota mínima no puede superar el total.']);
        $config=DB::table('exam_configurations')->where('active',true)->first();abort_unless($config,404);
        DB::transaction(function()use($data,$config,$request){DB::table('exam_configurations')->where('id',$config->id)->update(collect($data)->except('distributions')->all()+['updated_at'=>now()]);DB::table('exam_chapter_distributions')->where('exam_configuration_id',$config->id)->delete();foreach($data['distributions'] as $d)DB::table('exam_chapter_distributions')->insert($d+['exam_configuration_id'=>$config->id]);$this->audit($request,'exam.configuration.updated','exam_configurations',$config->id);});
        return $this->configuration();
    }

    public function questions(Request $request): JsonResponse
    {
        $query=DB::table('questions as q')->join('chapters as c','c.id','=','q.chapter_id')->leftJoin('learning_objectives as l','l.id','=','q.learning_objective_id')->whereNull('q.deleted_at');
        if($request->filled('chapter_id'))$query->where('q.chapter_id',$request->integer('chapter_id'));
        if($request->filled('k_level'))$query->where('q.k_level',$request->string('k_level'));
        if($request->filled('status'))$query->where('q.status',$request->string('status'));
        if($request->filled('search')){
            $search=trim((string)$request->input('search'));
            $query->where(function($subquery)use($search){
                if(ctype_digit($search))$subquery->orWhere('q.id',(int)$search);
                $subquery->orWhere('q.content','like','%'.$search.'%')
                    ->orWhere('q.comment','like','%'.$search.'%')
                    ->orWhere('l.name','like','%'.$search.'%');
            });
        }
        $perPage=min(100,max(10,$request->integer('per_page',25)));
        return response()->json($query->select('q.*','c.name as chapter_name','l.name as learning_objective_name')->orderByDesc('q.id')->paginate($perPage));
    }

    public function question(int $id): JsonResponse
    {
        $question=DB::table('questions')->whereNull('deleted_at')->find($id); abort_unless($question,404);
        $question->options=DB::table('question_options')->where('question_id',$id)->whereNull('deleted_at')->get()->map(function($option){
            $option->is_correct=(bool)$option->is_correct;
            $option->active=(bool)$option->active;
            return $option;
        });
        return response()->json($question);
    }

    public function uploadQuestionImage(Request $request): JsonResponse
    {
        $request->validate(['image'=>['required','file','max:5120']]);
        $file=$request->file('image');
        $imageInfo=@getimagesize($file->getRealPath());
        $types=[
            IMAGETYPE_JPEG=>['jpg','image/jpeg'],IMAGETYPE_PNG=>['png','image/png'],
            IMAGETYPE_GIF=>['gif','image/gif'],IMAGETYPE_WEBP=>['webp','image/webp'],
        ];
        if(!$imageInfo||!isset($types[$imageInfo[2]]))throw ValidationException::withMessages(['image'=>'El archivo debe ser una imagen JPG, PNG, GIF o WebP válida.']);
        $filename=Str::uuid().'.'.$types[$imageInfo[2]][0];
        $privateDir=storage_path('app/private/question-images');
        $stored=false;
        if((is_dir($privateDir)||@mkdir($privateDir,0775,true))&&is_writable($privateDir)){
            $target=$privateDir.DIRECTORY_SEPARATOR.$filename;
            $stored=@copy($file->getRealPath(),$target);
            if($stored)@chmod($target,0664);
        }
        if(!$stored){
            $publicDir=public_path('question-images');
            if((!is_dir($publicDir)&&!@mkdir($publicDir,0775,true))||!is_writable($publicDir)){
                throw ValidationException::withMessages(['image'=>'No se pudo escribir la imagen. Revise permisos de storage/app/private o public/question-images.']);
            }
            $target=$publicDir.DIRECTORY_SEPARATOR.$filename;
            if(!@copy($file->getRealPath(),$target)){
                throw ValidationException::withMessages(['image'=>'No se pudo guardar la imagen en producción. Revise permisos de public/question-images.']);
            }
            @chmod($target,0664);
        }
        $basePath=rtrim((string)(parse_url(config('app.url'),PHP_URL_PATH)?:''),'/');
        return response()->json(['url'=>$basePath.'/api/public/question-images/'.$filename]);
    }

    public function questionImage(string $filename)
    {
        $privatePath=storage_path('app/private/question-images/'.basename($filename));
        $publicPath=public_path('question-images/'.basename($filename));
        abort_unless(is_file($privatePath)||is_file($publicPath),404);
        $mime=match(strtolower(pathinfo($filename,PATHINFO_EXTENSION))){'jpg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',default=>'application/octet-stream'};
        try{$contents=file_get_contents(is_file($privatePath)?$privatePath:$publicPath);}
        catch(\Throwable $exception){report($exception);abort(500,'No se pudo leer la imagen desde storage.');}
        return response($contents,200,['Content-Type'=>$mime,'Content-Length'=>(string)strlen($contents),'Cache-Control'=>'public, max-age=31536000, immutable','X-Content-Type-Options'=>'nosniff']);
    }

    public function saveQuestion(Request $request,?int $id=null): JsonResponse
    {
        $data=$request->validate([
            'chapter_id'=>['required','exists:chapters,id'],'learning_objective_id'=>['nullable','exists:learning_objectives,id'],
            'content'=>['required','string'],'comment'=>['nullable','string'],'k_level'=>['required',Rule::in(['K1','K2','K3'])],
            'status'=>['required',Rule::in(['draft','active','inactive'])],'options'=>['required','array','min:2'],
            'options.*.content'=>['required','string'],'options.*.is_correct'=>['required','boolean'],
        ]);
        $data['content']=$this->sanitizeRichText($data['content']);
        $data['comment']=$this->sanitizeRichText($data['comment']??null);
        if(!collect($data['options'])->contains(fn($o)=>$o['is_correct']))throw ValidationException::withMessages(['options'=>'Debe marcar al menos una respuesta correcta.']);
        if($data['learning_objective_id'] && !DB::table('learning_objectives')->where('id',$data['learning_objective_id'])->where('chapter_id',$data['chapter_id'])->exists())
            throw ValidationException::withMessages(['learning_objective_id'=>'El objetivo no pertenece al capítulo seleccionado.']);
        $questionId=DB::transaction(function()use($data,$id,$request){
            $values=collect($data)->except('options')->all()+['updated_by'=>$request->user()->id,'updated_at'=>now()];
            if($id){$old=DB::table('questions')->find($id);abort_unless($old,404);$values['version']=$old->version+1;DB::table('questions')->where('id',$id)->update($values);DB::table('question_options')->where('question_id',$id)->update(['deleted_at'=>now()]);$questionId=$id;}
            else{$questionId=DB::table('questions')->insertGetId($values+['created_by'=>$request->user()->id,'created_at'=>now()]);}
            foreach($data['options'] as $option)DB::table('question_options')->insert($option+['question_id'=>$questionId,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($request,$id?'question.updated':'question.created','questions',$questionId);
            return $questionId;
        });
        return $this->question($questionId);
    }

    public function deleteQuestion(Request $request,int $id): JsonResponse
    {
        DB::table('questions')->where('id',$id)->update(['deleted_at'=>now(),'status'=>'inactive','updated_by'=>$request->user()->id]);
        $this->audit($request,'question.deleted','questions',$id); return response()->json(['deleted'=>true]);
    }

    public function users(): JsonResponse
    {
        return response()->json(DB::table('users as u')
            ->leftJoin('user_roles as ur','ur.user_id','=','u.id')
            ->leftJoin('roles as r','r.id','=','ur.role_id')
            ->whereNull('u.deleted_at')
            ->select('u.id','u.name','u.email','u.status','u.last_login_at','u.created_at','r.id as role_id','r.name as role_name')
            ->orderBy('u.name')->paginate(25));
    }

    public function saveUser(Request $request,?int $id=null): JsonResponse
    {
        $rules=['name'=>['required','string','max:255'],'email'=>['required','email',Rule::unique('users')->ignore($id)],
            'status'=>['required',Rule::in(['active','inactive','blocked'])],'role_id'=>['required','exists:roles,id'],
            'password'=>[$id?'nullable':'required','string','min:10']];
        $data=$request->validate($rules);
        if($id===$request->user()->id && $data['status']!=='active')throw ValidationException::withMessages(['status'=>'No puede dar de baja a su propio usuario.']);
        $roleId=$data['role_id'];unset($data['role_id']);
        if(empty($data['password']))unset($data['password']); else $data['password']=Hash::make($data['password']);
        $user=DB::transaction(function()use($data,$id,$roleId,$request){
            $user=$id?User::query()->findOrFail($id):new User;$user->fill($data);$user->save();
            DB::table('user_roles')->where('user_id',$user->id)->delete();
            DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>$roleId]);
            $this->audit($request,$id?'user.updated':'user.created','users',$user->id);return $user;
        });
        return response()->json($user,$id?200:201);
    }

    public function setUserStatus(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['status'=>['required',Rule::in(['active','inactive','blocked'])]]);
        if($id===$request->user()->id && $data['status']!=='active')throw ValidationException::withMessages(['status'=>'No puede dar de baja a su propio usuario.']);
        $user=User::query()->findOrFail($id);$user->status=$data['status'];$user->save();
        $this->audit($request,'user.status.updated','users',$id);
        return response()->json(['id'=>$id,'status'=>$user->status]);
    }

    public function reports(Request $request): JsonResponse
    {
        $query=DB::table('exam_attempts')->orderByDesc('started_at');
        if($request->filled('status'))$query->where('status',$request->string('status'));
        if($request->filled('date_from'))$query->whereDate('started_at','>=',$request->date('date_from'));
        if($request->filled('date_to'))$query->whereDate('started_at','<=',$request->date('date_to'));
        $reports=$query->paginate(30);
        $reports->setCollection($reports->getCollection()->map(function($report){$report->passed=$report->passed===null?null:(bool)$report->passed;return $report;}));
        return response()->json($reports);
    }

    public function report(int $id): JsonResponse
    {
        $attempt=DB::table('exam_attempts')->find($id);abort_unless($attempt,404);unset($attempt->resume_token_hash);$attempt->passed=$attempt->passed===null?null:(bool)$attempt->passed;
        $questions=DB::table('attempt_questions')->where('exam_attempt_id',$id)->orderBy('position')->get();
        foreach($questions as $q){$q->is_correct=$q->is_correct===null?null:(bool)$q->is_correct;$q->options=DB::table('attempt_options')->where('attempt_question_id',$q->id)->orderBy('position')->get();$q->options->each(fn($option)=>$option->is_correct_snapshot=(bool)$option->is_correct_snapshot);$q->selected_option_ids=DB::table('attempt_answers as a')->join('attempt_selected_options as s','s.attempt_answer_id','=','a.id')->where('a.attempt_question_id',$q->id)->pluck('s.attempt_option_id');}
        return response()->json(['attempt'=>$attempt,'questions'=>$questions]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file'=>['required','file','max:10240']]); $file=$request->file('file');
        $extension=strtolower($file->getClientOriginalExtension());
        if(!in_array($extension,['xlsx','xls'],true))throw ValidationException::withMessages(['file'=>'El archivo debe ser Excel .xlsx o .xls.']);
        $importDir=storage_path('app/private/question-imports');
        if((!is_dir($importDir)&&!@mkdir($importDir,0775,true))||!is_writable($importDir))throw ValidationException::withMessages(['file'=>'No se pudo escribir el Excel en storage/app/private/question-imports.']);
        $path='question-imports/'.Str::uuid().'.'.$extension;
        if(!@copy($file->getRealPath(),storage_path('app/private/'.$path)))throw ValidationException::withMessages(['file'=>'No se pudo guardar el Excel importado.']);
        $importId=DB::table('question_imports')->insertGetId(['user_id'=>$request->user()->id,'original_name'=>$file->getClientOriginalName(),'stored_path'=>$path,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
        $rows=IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null,true,true,false); $headers=array_map(fn($v)=>mb_strtolower(trim((string)$v)),array_shift($rows));
        $required=['pregunta','respuesta 1','respuestacorrecta 1','respuesta 2','respuestacorrecta 2','nivel k','capitulo de aprendizaje','objetivo de aprendizaje','comentario'];
        foreach($required as $column)if(!in_array($column,$headers,true))throw ValidationException::withMessages(['file'=>"Falta la columna: {$column}"]);
        $ok=0;$failed=0;
        foreach($rows as $offset=>$row){$number=$offset+2;$values=array_combine($headers,array_pad($row, count($headers), null));if(!trim((string)($values['pregunta']??'')))continue;
            try{DB::transaction(function()use($values,$request){
                $chapterLabel=trim((string)$values['capitulo de aprendizaje']);
                $chapter=null;
                if(preg_match('/cap[ií]tulo\s*(\d+)/iu',$chapterLabel,$matches)){
                    $chapter=DB::table('chapters')->where('code',$matches[1])->whereNull('deleted_at')->first();
                }
                if(!$chapter){
                    $chapter=DB::table('chapters')->whereRaw('LOWER(name)=?',[mb_strtolower($chapterLabel)])->whereNull('deleted_at')->first();
                }
                if(!$chapter)throw new \RuntimeException('Capítulo no encontrado.');
                $k=strtoupper(trim((string)$values['nivel k']));
                if(!in_array($k,['K1','K2','K3'],true))throw new \RuntimeException('Nivel K inválido.');
                $objectiveName=trim((string)$values['objetivo de aprendizaje']);
                if($objectiveName==='')throw new \RuntimeException('El objetivo de aprendizaje es obligatorio.');
                $objectiveId=DB::table('learning_objectives')->where('chapter_id',$chapter->id)->whereRaw('LOWER(name)=?',[mb_strtolower($objectiveName)])->whereNull('deleted_at')->value('id');
                if(!$objectiveId)$objectiveId=DB::table('learning_objectives')->insertGetId(['chapter_id'=>$chapter->id,'name'=>$objectiveName,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
                $options=[];
                for($i=1;$i<=4;$i++){
                    $content=trim((string)($values["respuesta {$i}"]??'')); if($content==='')continue;
                    $flag=strtoupper(trim((string)($values["respuestacorrecta {$i}"]??'')));
                    if(!in_array($flag,['SI','SÍ','NO'],true))throw new \RuntimeException("respuestaCorrecta {$i} debe contener SI o NO.");
                    $options[]=['content'=>$content,'is_correct'=>in_array($flag,['SI','SÍ'],true)];
                }
                if(count($options)<2)throw new \RuntimeException('La pregunta debe tener al menos dos respuestas.');
                if(!collect($options)->contains('is_correct',true))throw new \RuntimeException('La pregunta no tiene respuesta correcta.');
                $questionId=DB::table('questions')->insertGetId(['chapter_id'=>$chapter->id,'learning_objective_id'=>$objectiveId,
                    'content'=>trim((string)$values['pregunta']),'comment'=>trim((string)($values['comentario']??''))?:null,'k_level'=>$k,
                    'status'=>'active','version'=>1,'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
                foreach($options as $option)DB::table('question_options')->insert($option+['question_id'=>$questionId,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            });$ok++;}catch(\Throwable $e){$failed++;DB::table('question_import_errors')->insert(['question_import_id'=>$importId,'row_number'=>$number,'message'=>$e->getMessage(),'row_data'=>json_encode($values),'created_at'=>now(),'updated_at'=>now()]);}
        }
        DB::table('question_imports')->where('id',$importId)->update(['status'=>'completed','total_rows'=>count($rows),'imported_rows'=>$ok,'failed_rows'=>$failed,'updated_at'=>now()]);
        return response()->json(['import_id'=>$importId,'imported'=>$ok,'failed'=>$failed]);
    }

    private function audit(Request $request,string $action,string $entity,int $id):void
    {
        DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'action'=>$action,'entity_type'=>$entity,'entity_id'=>$id,'ip_address'=>$request->ip(),'user_agent'=>$request->userAgent(),'created_at'=>now()]);
    }

    private function sanitizeRichText(?string $html): ?string
    {
        if($html===null)return null;
        $document=new \DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="rich-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();libxml_use_internal_errors($previous);
        $root=$document->getElementById('rich-root');
        if(!$root)return strip_tags($html);
        $allowed=['div','p','br','strong','b','em','i','u','h2','h3','h4','ul','ol','li','blockquote','table','thead','tbody','tfoot','tr','th','td','img'];
        $clean=function(\DOMNode $node)use(&$clean,$allowed){
            foreach(iterator_to_array($node->childNodes) as $child)$clean($child);
            if(!$node instanceof \DOMElement||$node->getAttribute('id')==='rich-root')return;
            $tag=strtolower($node->tagName);
            if(!in_array($tag,$allowed,true)){
                $parent=$node->parentNode;if(!$parent)return;
                while($node->firstChild)$parent->insertBefore($node->firstChild,$node);
                $parent->removeChild($node);return;
            }
            $allowedAttributes=$tag==='img'?['src','alt','width','height']:($tag==='td'||$tag==='th'?['colspan','rowspan']:[]);
            foreach(iterator_to_array($node->attributes) as $attribute)if(!in_array(strtolower($attribute->name),$allowedAttributes,true))$node->removeAttribute($attribute->name);
            if($tag==='img'){
                $src=$node->getAttribute('src');$path=(string)(parse_url($src,PHP_URL_PATH)?:'');
                if(!str_starts_with($path,'/istqb-api/api/public/question-images/')&&!str_starts_with($path,'/api/public/question-images/'))$node->parentNode?->removeChild($node);
            }
        };
        $clean($root);
        $result='';foreach(iterator_to_array($root->childNodes) as $child)$result.=$document->saveHTML($child);
        return trim($result);
    }
}
