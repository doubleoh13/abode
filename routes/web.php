<?php

use Illuminate\Support\Facades\Route;

Route::view('/{path?}', 'app')
    ->where('path', '^(?!api($|/)|docs($|/)|sanctum($|/)).*')
    ->name('spa');
