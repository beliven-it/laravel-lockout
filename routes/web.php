<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['signed'])->get('/lockout/unlock', \Beliven\Lockout\Http\Controllers\UnlockController::class)->name('lockout.unlock');
