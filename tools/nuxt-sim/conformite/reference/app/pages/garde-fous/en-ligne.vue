<script setup lang="ts">
definePageMeta({
	middleware: [
		(to) => {
			if (to.query.bloquer) return abortNavigation()
			if (to.query.erreur) return abortNavigation(createError({ statusCode: 403, statusMessage: 'Forbidden', message: 'Réservé au gardien.' }))
			if (to.query.ailleurs) return navigateTo('/garde-fous/connexion', { redirectCode: 301 })
		},
	],
})
</script>

<template>
	<p class="en-ligne">Passage libre</p>
</template>
