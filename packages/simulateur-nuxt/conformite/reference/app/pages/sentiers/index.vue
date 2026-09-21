<script setup lang="ts">
const sentiers = [
	{ slug: 'lac-noir', nom: 'Le lac Noir' },
	{ slug: 'col-des-marmottes', nom: 'Le col des Marmottes' },
]
</script>

<template>
	<ul class="sentiers">
		<li v-for="sentier in sentiers" :key="sentier.slug">
			<NuxtLink :to="`/sentiers/${sentier.slug}`">{{ sentier.nom }}</NuxtLink>
		</li>
	</ul>
</template>
