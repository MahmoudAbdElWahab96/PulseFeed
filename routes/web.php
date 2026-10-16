<?php

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Scramble::registerUiRoute('docs/api');
Scramble::registerJsonSpecificationRoute('docs/api.json');
