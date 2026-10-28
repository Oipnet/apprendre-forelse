<?php

use Illuminate\Support\Facades\Route;

// Modifiez ce code : l'aperçu se met à jour à chaque frappe.
Route::get('/', function () {
    return view('accueil', [
        'titre' => 'Bonjour Laravel !',
        'version' => app()->version(),
        'idees' => collect([
            'Ajouter une route avec Route::get()',
            'Créer un contrôleur dans app/Http/Controllers/',
            'Lancer « route:list » dans la console',
        ]),
    ]);
});

// Essayez /bonjour/Ada dans la barre d'adresse de l'aperçu.
Route::get('/bonjour/{prenom}', fn (string $prenom) => [
    'message' => "Bonjour {$prenom} !",
])->name('bonjour');
