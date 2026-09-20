<script setup lang="ts">
definePageMeta({ layout: 'provisions' })
const { data: sentier } = await useFetch(() => '/api/cabane/sentier?slug=lac-noir', { key: 'etat' })
</script>

<template>
	<h1>{{ sentier?.nom ?? 'Pas de sentier' }}</h1>
</template>
