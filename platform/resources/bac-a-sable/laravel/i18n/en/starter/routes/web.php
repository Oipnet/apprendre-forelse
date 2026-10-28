<?php

use Illuminate\Support\Facades\Route;

// Edit this code: the preview updates as you type.
Route::get('/', function () {
    return view('accueil', [
        'titre' => 'Hello Laravel!',
        'version' => app()->version(),
        'idees' => collect([
            'Add a route with Route::get()',
            'Create a controller in app/Http/Controllers/',
            'Run "route:list" in the console',
        ]),
    ]);
});

// Try /bonjour/Ada in the preview's address bar.
Route::get('/bonjour/{prenom}', fn (string $prenom) => [
    'message' => "Hello {$prenom}!",
])->name('bonjour');
