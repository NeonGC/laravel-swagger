<?php

use Illuminate\Support\Facades\Route;
use RonasIT\Support\AutoDoc\Http\Controllers\AutoDocController;

Route::middleware('web')->group(function () {
    Route::get('/auto-doc/documentation', ['uses' => AutoDocController::class . '@documentation']);
    Route::get('/auto-doc/{file}', ['uses' => AutoDocController::class . '@getFile']);
    foreach (config('auto-doc.routes') as $route) {
        Route::middleware(config('auto-doc.middlewares'))->get($route, ['uses' => AutoDocController::class . '@index']);
    }
});
