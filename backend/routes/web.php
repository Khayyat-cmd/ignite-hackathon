<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'AMAN Backend', 'version' => 'v1', 'health' => '/up']);
});
