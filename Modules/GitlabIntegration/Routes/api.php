<?php

use Illuminate\Routing\Router;

Route::middleware('auth:api')->group(static function (Router $router) {
    $router->get('/settings', 'SettingsController@get')->name('settings.get');
    $router->put('/settings', 'SettingsController@set')->name('settings.set');
});
