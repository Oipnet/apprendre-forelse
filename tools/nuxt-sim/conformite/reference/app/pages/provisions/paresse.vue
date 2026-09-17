<script setup lang="ts">
const { data, status, pending } = useLazyFetch('/api/provisions/compteur')
const { status: navigateur, data: rien } = await useFetch('/api/provisions/compteur', { server: false })
</script>

<template>
	<p>{{ status }} · {{ pending }} · {{ data }} · {{ navigateur }} · {{ rien }}</p>
</template>
