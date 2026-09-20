<script setup lang="ts">
definePageMeta({ layout: 'provisions' })
const route = useRoute()
// Gestionnaire écrit autrement que dans ProvisionsEtat : voir l'écart connu sur NUXT_E3004 (cas.ts).
const { data: etat } = useAsyncData('etat', () => $fetch('/api/provisions/compteur', {}), { dedupe: route.query.dedupe === 'defer' ? 'defer' : 'cancel' })
const figee = etat.value?.releve ?? 0
</script>

<template>
	<p class="page">Page : relevé n° {{ etat?.releve }} · figé à {{ figee }}</p>
</template>
