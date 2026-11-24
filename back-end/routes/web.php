<?php

use App\Http\Controllers\PublicHtmlController;
use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/robots.txt', [SeoController::class, 'robots']);

Route::get('/', PublicHtmlController::class);
Route::get('/{publicPath}', PublicHtmlController::class)
    ->where('publicPath', '(?i:menu(?:/category/.*)?|grills-tabuk|branches|offers|about|contact|gallery|product(?:/.*)?)');
