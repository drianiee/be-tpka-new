<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/payment/{status?}', function (?string $status = 'finish') {
    $targetStatus = in_array($status, ['finish', 'unfinish', 'error'], true) ? $status : 'finish';
    $frontend = rtrim((string) config('app.frontend_url'), '/');
    $query = request()->getQueryString();

    return redirect()->away($frontend.'/payment/finish'.($query ? '?'.$query : ''));
});
