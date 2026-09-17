<script setup lang="ts">
const { releve } = await $fetch('/api/provisions/compteur')
const entetes = await $fetch('/api/provisions/entetes')
const { data: transmises } = await useFetch('/api/provisions/entetes')
</script>

<template>
	<p class="releve">Relevé n° {{ releve }}</p>
	<p>{{ entetes }}</p>
	<p>{{ transmises }}</p>
</template>
