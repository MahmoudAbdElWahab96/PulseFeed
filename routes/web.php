<?php

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Scramble's default docs routes do not survive this app's provider boot
// order, so they are registered explicitly: /docs/api (viewer UI) and
// /docs/api.json (OpenAPI 3.1 spec).
Scramble::registerUiRoute('docs/api');
Scramble::registerJsonSpecificationRoute('docs/api.json');
