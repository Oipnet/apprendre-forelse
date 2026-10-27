<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
</head>
<body>
    <h1>{{ $titre }}</h1>
    <p>Laravel {{ $version }}, in your browser.</p>

    <h2>Things to try</h2>
    <ul>
        @foreach ($idees as $idee)
            <li>{{ $idee }}</li>
        @endforeach
    </ul>

    <p><a href="{{ route('bonjour', ['prenom' => 'Ada']) }}">A JSON response</a></p>
</body>
</html>
