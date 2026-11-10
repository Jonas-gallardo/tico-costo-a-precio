<?php

use App\Http\Controllers\PreciosController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/precios');

Route::get('/precios', [PreciosController::class, 'index'])->name('precios.index');
Route::post('/precios/recalcular', [PreciosController::class, 'recalcular'])->name('precios.recalcular');
