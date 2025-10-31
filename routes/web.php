<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['signed'])->get('/lockout/unlock')->name('lockout.unlock');
