<script setup lang="ts">
const { data } = await useAsyncData('releve', () => $fetch('/api/provisions/compteur'))
</script>

<template>
	<p class="releve">Relevé n° {{ data?.releve }}</p>
</template>
