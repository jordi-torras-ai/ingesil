<?php

use App\Http\Controllers\DownloadCrawlerRunLogController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(Auth::check() ? '/admin' : '/admin/login');
});

Route::middleware('auth')->get('/admin/crawler-runs/{crawlerRun}/download-log', DownloadCrawlerRunLogController::class)
    ->name('crawler-runs.log.download');
