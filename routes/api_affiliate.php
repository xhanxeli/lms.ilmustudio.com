<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/affiliate')->namespace('Affiliate')->group(function () {
    Route::get('/', 'AffiliateDatabaseController@index');
});
