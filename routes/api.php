<?php

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\Financial\AccountController;
use App\Http\Controllers\Api\V1\Financial\BalanceAssertionController;
use App\Http\Controllers\Api\V1\Financial\CommodityController;
use App\Http\Controllers\Api\V1\Financial\CommodityPriceController;
use App\Http\Controllers\Api\V1\Financial\InstitutionController;
use App\Http\Controllers\Api\V1\Financial\JournalIssueController;
use App\Http\Controllers\Api\V1\Financial\LotController;
use App\Http\Controllers\Api\V1\Financial\PayeeController;
use App\Http\Controllers\Api\V1\Financial\PostingController;
use App\Http\Controllers\Api\V1\Financial\ReportController;
use App\Http\Controllers\Api\V1\Financial\TransactionController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\SetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/setup', [SetupController::class, 'show']);
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:setup');
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::patch('/user', [CurrentUserController::class, 'update']);
    Route::put('/user/password', [CurrentUserController::class, 'updatePassword']);

    Route::apiResource('tokens', ApiTokenController::class)->only(['index', 'store', 'destroy']);

    Route::apiResource('notes', NoteController::class)->except('show');
    Route::apiResource('attachments', AttachmentController::class)->only(['index', 'store', 'destroy']);
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    Route::prefix('financial')->name('financial.')->group(function () {
        Route::middleware('can:view-finances')->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);
            Route::apiResource('institutions', InstitutionController::class)->only(['index', 'show']);
            Route::apiResource('commodities', CommodityController::class)->only(['index', 'show']);
            Route::apiResource('payees', PayeeController::class)->only(['index', 'show']);
            Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
            Route::get('accounts/{account}/balances', [AccountController::class, 'balances'])->name('accounts.balances');
            Route::get('commodities/{commodity}/balances', [CommodityController::class, 'balances'])->name('commodities.balances');
            Route::get('commodities/{commodity}/price-series', [CommodityController::class, 'priceSeries'])->name('commodities.price-series');
            Route::apiResource('balance-assertions', BalanceAssertionController::class)->only('index');
            Route::get('postings', [PostingController::class, 'index'])->name('postings.index');
            Route::get('lots', [LotController::class, 'index'])->name('lots.index');
            Route::get('journal-issues', [JournalIssueController::class, 'index'])->name('journal-issues.index');
            Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
            Route::apiResource('commodity-prices', CommodityPriceController::class)->only('index');
        });

        Route::middleware('can:manage-finances')->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('institutions', InstitutionController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('commodities', CommodityController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('payees', PayeeController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('transactions', TransactionController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('postings', PostingController::class)->only('update');
            Route::apiResource('balance-assertions', BalanceAssertionController::class)->only(['store', 'destroy']);
            Route::apiResource('commodity-prices', CommodityPriceController::class)->only(['store', 'destroy']);
        });
    });
});
