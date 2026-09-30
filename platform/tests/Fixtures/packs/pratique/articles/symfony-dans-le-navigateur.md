---
title: Symfony dans le navigateur, sans serveur
description: Comment un vrai projet Symfony tourne entièrement dans le navigateur, avec PHP compilé en WebAssembly, ses pages comme ses tests.
published: 2026-09-20
updated: 2026-09-25
---

# Symfony dans le navigateur, sans serveur

PHP compilé en WebAssembly exécute le front controller de Symfony.

## Le Service Worker

```php
<?php
echo 'Bonjour';
```

<script>alert('pas de HTML brut')</script>
