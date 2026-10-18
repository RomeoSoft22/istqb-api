<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exams:expire', function () {
    DB::table('exam_attempts')->where('status','in_progress')->where('expires_at','<=',now())->orderBy('id')->chunkById(100,function($attempts){
        foreach($attempts as $attempt){DB::transaction(function()use($attempt){
            $locked=DB::table('exam_attempts')->lockForUpdate()->find($attempt->id);if(!$locked || $locked->status!=='in_progress')return;
            $config=DB::table('exam_configurations')->find($locked->exam_configuration_id);
            $correct=DB::table('attempt_questions')->where('exam_attempt_id',$locked->id)->where('is_correct',true)->count();
            $answered=DB::table('attempt_questions')->where('exam_attempt_id',$locked->id)->whereNotNull('answered_at')->count();
            DB::table('exam_attempts')->where('id',$locked->id)->update(['status'=>'expired','finished_at'=>now(),'completion_type'=>'expired',
                'correct_answers'=>$correct,'incorrect_answers'=>$answered-$correct,'unanswered_questions'=>$config->total_questions-$answered,
                'score'=>$correct,'passed'=>$correct >= $config->passing_correct_answers,'updated_at'=>now()]);
        });}
    });
    $this->info('Intentos vencidos procesados.');
})->purpose('Cierra y califica los exámenes cuyo tiempo terminó');

Schedule::command('exams:expire')->everyMinute()->withoutOverlapping();
