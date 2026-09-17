<script setup lang="ts">
const { data: vide } = await useAsyncData('vide', async () => {})
const { data: rien } = await useFetch('/api/provisions/vide')
const { data: un } = await useAsyncData('double', () => $fetch('/api/cabane/sentier?slug=lac-noir'))
const { data: deux } = await useAsyncData('double', () => $fetch('/api/cabane/sentier?slug=col-des-marmottes'))
</script>

<template>
	<p>{{ vide }} · {{ rien }} · {{ un?.slug }} · {{ deux?.slug }}</p>
</template>
