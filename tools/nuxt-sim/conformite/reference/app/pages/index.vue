<script setup lang="ts">
const couchettes = ref(32)
const ouvert = computed(() => couchettes.value > 0)
</script>

<template>
	<section>
		<h1>Bienvenue au refuge</h1>
		<p v-if="ouvert">{{ couchettes }} couchettes libres ce soir</p>
		<p v-else>Complet</p>
		<button type="button" @click="couchettes--">Réserver une couchette</button>
	</section>
</template>
