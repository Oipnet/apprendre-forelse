<script setup lang="ts">
definePageMeta({
	middleware: [
		(to) => {
			if (to.query.oubli) {
				navigateTo('/garde-fous/connexion')
			}
		},
	],
})
</script>

<template>
	<p class="sans-return">La porte est restée ouverte</p>
</template>
