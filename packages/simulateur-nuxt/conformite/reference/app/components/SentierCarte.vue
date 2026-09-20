<script setup lang="ts">
defineProps<{ nom: string, denivele: number }>()
</script>

<template>
	<article class="carte">
		<h2>{{ nom }}</h2>
		<p>{{ denivele }} m · {{ libelleDifficulte(denivele > 1000 ? 'difficile' : 'facile') }}</p>
	</article>
</template>
