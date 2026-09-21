<script setup lang="ts">
const config = useRuntimeConfig()
const { data: releve, error } = await useFetch(`${config.public.amont}/meteo`)
const { data: cle } = await useFetch('/api/amont/cle')
</script>

<template>
	<p class="releve">{{ releve }} · {{ error?.statusCode }}</p>
	<p class="cle">{{ cle }}</p>
</template>
