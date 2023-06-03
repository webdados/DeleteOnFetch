<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\DeleteOnFetch\Http\Controllers'], function()
{
    Route::get('/', 'DeleteOnFetchController@index');
});
