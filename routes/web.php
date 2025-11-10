<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['signed'])->get('/lockout/unlock', \Beliven\Lockout\Http\Controllers\UnlockController::class)->name('lockout.unlock');
Route::middleware(['signed'])->get('/lockout/lock', \Beliven\Lockout\Http\Controllers\LockController::class)->name('lockout.lock');
