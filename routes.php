<?php

Route::get('/', function () {
    return Redirect::away('https://hyn.readme.io/docs/installation#2-using-the-automatic-installer');
});

Route::get('/download', [
    'uses' => 'Hyn\Installer\Http\Controllers\DownloadController@generate'
]);