<script setup lang="ts">
const { data: sentiers, status, error, pending } = await useFetch('/api/cabane/sentiers')
</script>

<template>
	<ul>
		<li v-for="sentier in sentiers" :key="sentier.slug">{{ sentier.nom }} ({{ sentier.libelle }})</li>
	</ul>
	<p class="etat">{{ status }} · {{ pending }} · {{ error }}</p>
</template>
