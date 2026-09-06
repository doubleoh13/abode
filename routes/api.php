<?php

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/user', function (Request $request) {
        return new UserResource($request->user()->load('permissions'));
    })->middleware('auth:sanctum');
});
