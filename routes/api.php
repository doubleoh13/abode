<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CommodityController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return new UserResource($request->user()->load('permissions'));
    });

    Route::middleware('can:view-finances')->group(function () {
        Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);
        Route::apiResource('institutions', InstitutionController::class)->only(['index', 'show']);
        Route::apiResource('commodities', CommodityController::class)->only(['index', 'show']);
    });

    Route::middleware('can:manage-finances')->group(function () {
        Route::apiResource('accounts', AccountController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('institutions', InstitutionController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('commodities', CommodityController::class)->only(['store', 'update', 'destroy']);
    });
});
