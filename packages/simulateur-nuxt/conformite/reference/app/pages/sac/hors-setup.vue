<script lang="ts">
// Appelé au chargement du module, hors de setup : pas d'instance Nuxt.
const etat = useState('hors-setup', () => 0)
</script>

<template>
	<p>{{ 1 }}</p>
</template>
