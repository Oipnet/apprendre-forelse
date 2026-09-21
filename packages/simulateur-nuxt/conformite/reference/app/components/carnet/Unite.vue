<script setup lang="ts">
const unite = useCarnetUnite()
const { refuge } = useAppConfig()
const config = useRuntimeConfig()
</script>

<template>
	<p class="unite">{{ refuge.altitude }} {{ unite }} · {{ config.public.nomDuPort }}</p>
	<NuxtLink to="/sentiers">Sentiers</NuxtLink>
	<CarnetFiche nom="Enfant" />
</template>
