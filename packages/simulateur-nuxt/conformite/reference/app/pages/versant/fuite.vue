<script setup lang="ts">
const visites = useCompteurPartage()
visites.value++
</script>

<template>
	<p class="visites">{{ visites }}</p>
</template>
