<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicExamController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->group(function(){
    Route::get('question-images/{filename}',[AdminController::class,'questionImage'])->where('filename','[A-Za-z0-9._-]+');
    Route::get('exam/configuration',[PublicExamController::class,'configuration']);
    Route::post('exam/start',[PublicExamController::class,'start'])->middleware('throttle:30,1');
    Route::get('exam/{number}',[PublicExamController::class,'show']);
    Route::put('exam/{number}/questions/{question}',[PublicExamController::class,'answer']);
    Route::post('exam/{number}/finish',[PublicExamController::class,'finish']);
    Route::get('exam/{number}/result',[PublicExamController::class,'result']);
});
// El ingreso se hace en identidad.juarezcampos.net; aquí solo se canjea el código y se renueva el token.
Route::prefix('auth')->group(function(){
    Route::get('config',[AuthController::class,'config']);
    Route::post('exchange',[AuthController::class,'exchange'])->middleware('throttle:20,1');
    Route::post('refresh',[AuthController::class,'refresh'])->middleware('throttle:60,1');
    Route::post('logout',[AuthController::class,'logout']);
});
Route::middleware('auth:api')->group(function(){
    Route::get('auth/me',[AuthController::class,'me']);
    Route::prefix('admin')->group(function(){
        Route::get('dashboard',[AdminController::class,'dashboard']); Route::get('catalogs',[AdminController::class,'catalogs']);
        Route::middleware('permission:questions.manage')->group(function(){
            Route::post('question-images',[AdminController::class,'uploadQuestionImage']);
            Route::post('chapters',[AdminController::class,'saveChapter']); Route::put('chapters/{id}',[AdminController::class,'saveChapter']);
            Route::post('learning-objectives',[AdminController::class,'saveObjective']); Route::put('learning-objectives/{id}',[AdminController::class,'saveObjective']);
            Route::get('questions',[AdminController::class,'questions']); Route::get('questions/{id}',[AdminController::class,'question']);
            Route::post('questions',[AdminController::class,'saveQuestion']); Route::put('questions/{id}',[AdminController::class,'saveQuestion']); Route::delete('questions/{id}',[AdminController::class,'deleteQuestion']);
            Route::post('questions/import',[AdminController::class,'import']);
        });
        Route::middleware('permission:exam.configure')->group(function(){
            Route::get('configuration',[AdminController::class,'configuration']); Route::put('configuration',[AdminController::class,'saveConfiguration']);
        });
        Route::middleware('permission:reports.view')->group(function(){
            Route::get('reports',[AdminController::class,'reports']); Route::get('reports/{id}',[AdminController::class,'report']);
        });
    });
});
