<script setup lang="ts">
const route = useRoute()
const difficulte = computed(() => route.query.difficulte ?? 'toutes')
const { data: sentiers } = await useFetch('/api/cabane/sentiers', {
	query: { difficulte },
	transform: (liste) => liste.map((sentier) => ({ slug: sentier.slug, nom: sentier.nom })),
})
const { data: lac } = await useFetch('/api/cabane/sentier', { query: { slug: 'lac-noir' }, pick: ['nom', 'difficulte'] })
</script>

<template>
	<p>{{ difficulte }} : {{ sentiers?.map((sentier) => sentier.nom).join(', ') }}</p>
	<p>{{ lac }}</p>
</template>
