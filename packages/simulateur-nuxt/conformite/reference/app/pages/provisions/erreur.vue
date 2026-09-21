<script setup lang="ts">
const { data, error, status } = await useFetch('/api/provisions/introuvable', { default: () => [] })
</script>

<template>
	<p class="etat">{{ status }} · {{ data }}</p>
	<p v-if="error" class="erreur">{{ error.statusCode }} · {{ error.statusMessage }} · {{ error.message }} · {{ error.data?.data }} · {{ error.data?.url }}</p>
</template>
