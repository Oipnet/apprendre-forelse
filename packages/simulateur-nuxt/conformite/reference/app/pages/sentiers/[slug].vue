<script setup lang="ts">
const route = useRoute()
</script>

<template>
	<h1>Sentier : {{ route.params.slug }}</h1>
	<p>Chemin : {{ route.path }}</p>
</template>
