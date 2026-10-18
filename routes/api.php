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
Route::post('auth/login',[AuthController::class,'login'])->middleware('throttle:5,1');
Route::middleware('auth:api')->group(function(){
    Route::get('auth/me',[AuthController::class,'me']); Route::post('auth/refresh',[AuthController::class,'refresh']); Route::post('auth/logout',[AuthController::class,'logout']);
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
        Route::middleware('permission:users.manage')->group(function(){
            Route::get('users',[AdminController::class,'users']); Route::post('users',[AdminController::class,'saveUser']); Route::put('users/{id}',[AdminController::class,'saveUser']);
            Route::patch('users/{id}/status',[AdminController::class,'setUserStatus']);
        });
        Route::middleware('permission:reports.view')->group(function(){
            Route::get('reports',[AdminController::class,'reports']); Route::get('reports/{id}',[AdminController::class,'report']);
        });
    });
});
