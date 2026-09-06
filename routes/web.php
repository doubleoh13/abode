<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/dev/login', function (Request $request) {
    abort_unless(app()->environment('local'), 404);

    Auth::login(User::query()->where('email', config('development.user_email'))->firstOrFail());
    $request->session()->regenerate();

    return response()->noContent();
})->name('development.login');

Route::view('/{path?}', 'app')
    ->where('path', '^(?!api($|/)|docs($|/)|sanctum($|/)).*')
    ->name('spa');
