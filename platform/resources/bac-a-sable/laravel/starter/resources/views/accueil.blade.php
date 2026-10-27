<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
</head>
<body>
    <h1>{{ $titre }}</h1>
    <p>Laravel {{ $version }}, dans votre navigateur.</p>

    <h2>À essayer</h2>
    <ul>
        @foreach ($idees as $idee)
            <li>{{ $idee }}</li>
        @endforeach
    </ul>

    <p><a href="{{ route('bonjour', ['prenom' => 'Ada']) }}">Une réponse JSON</a></p>
</body>
</html>
