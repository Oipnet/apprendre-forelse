<script setup lang="ts">
const cote = import.meta.server ? 'serveur' : 'navigateur'
const monte = ref(false)
onMounted(() => { monte.value = true })
const stockage = typeof window === 'undefined' ? 'aucun' : 'localStorage'
</script>

<template>
	<p class="cote">{{ cote }} · {{ monte }} · {{ stockage }}</p>
</template>
