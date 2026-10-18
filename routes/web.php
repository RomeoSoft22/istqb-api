<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'istqb-api',
    'status' => 'ok',
]));
