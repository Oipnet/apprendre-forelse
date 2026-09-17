<script setup lang="ts">
const route = useRoute()
</script>

<template>
	<main>
		<h1>Refuge du Pic Tordu</h1>
		<p>Chemin : {{ route.path }}</p>
	</main>
</template>
