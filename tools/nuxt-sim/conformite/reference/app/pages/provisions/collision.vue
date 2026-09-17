<script setup lang="ts">
definePageMeta({ layout: 'provisions' })
const { data: sentier } = await useFetch('/api/cabane/sentier', { query: { slug: 'lac-noir' }, key: 'etat', dedupe: 'defer' })
</script>

<template>
	<h1>{{ sentier?.nom ?? 'Pas de sentier' }}</h1>
	<p>{{ sentier }}</p>
</template>
