<script setup lang="ts">
useHead({
	title: 'La porte du local',
	titleTemplate: '%s · Refuge du Pic Tordu',
	htmlAttrs: { lang: 'fr' },
	bodyAttrs: { class: 'refuge' },
	meta: [{ name: 'description', content: 'Le local technique du refuge.' }],
})
useSeoMeta({ ogTitle: 'Le local du refuge', ogDescription: 'Réservé au gardien', twitterCard: 'summary' })
</script>

<template>
	<p class="tete">La tête de la page</p>
</template>
