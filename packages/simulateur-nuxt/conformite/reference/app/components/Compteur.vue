<script setup lang="ts">
// Deux useState sans clé : une clé est générée par emplacement d'appel.
const a = useState(() => 1)
const b = useState(() => 2)
const unite = useState('unite', () => 'm')
</script>

<template>
	<p class="compteur">{{ a }} {{ b }} {{ unite }}</p>
</template>
