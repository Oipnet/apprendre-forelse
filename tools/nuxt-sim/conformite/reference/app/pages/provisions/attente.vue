<script setup lang="ts">
const { data: lent } = await useFetch('/api/provisions/compteur', { lazy: true })
const { data: navigateur, status } = await useLazyFetch('/api/provisions/compteur', { server: false })
</script>

<template>
	<p v-if="navigateur" class="conseil">{{ navigateur }}</p>
	<p v-else class="conseil conseil--attente">Aimé réfléchit… ({{ status }})</p>
	<p>{{ lent?.releve }}</p>
</template>
