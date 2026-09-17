<script setup lang="ts">
const route = useRoute()
const { data: etat } = await useAsyncData('etat', () => $fetch('/api/provisions/compteur'), { dedupe: route.query.dedupe === 'defer' ? 'defer' : 'cancel' })
</script>

<template>
	<p class="etat">En-tête : relevé n° {{ etat?.releve }}</p>
</template>
