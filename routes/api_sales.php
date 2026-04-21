<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/sales')->namespace('Sales')->group(function () {
    Route::get('/', 'SalesController@index');
});

