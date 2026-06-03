<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::view('/schedule', 'pages.schedule')->name('schedule');
Route::view('/directions', 'pages.directions')->name('directions');
Route::view('/login', 'pages.login')->name('login');
Route::view('/account', 'pages.account')->name('account');
