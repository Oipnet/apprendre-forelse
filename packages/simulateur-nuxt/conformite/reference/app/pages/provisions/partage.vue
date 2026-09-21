<script setup lang="ts">
const { data: premier } = await useFetch('/api/provisions/compteur')
const { data: second } = await useFetch('/api/provisions/compteur')
const { data: anonyme } = await useAsyncData(() => $fetch('/api/provisions/compteur'))
</script>

<template>
	<ProvisionsReleve />
	<ProvisionsReleve />
	<p class="appels">{{ premier?.releve }} · {{ second?.releve }} · {{ anonyme?.releve }}</p>
</template>
