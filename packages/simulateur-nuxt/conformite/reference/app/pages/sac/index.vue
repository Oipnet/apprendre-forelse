<script setup lang="ts">
const denivele = useDenivele(450)
const config = useAppConfig()
</script>

<template>
	<section>
		<h1>{{ config.refuge.nom }}</h1>
		<SentierCarte nom="Le lac Noir" :denivele="450" />
		<MeteoBandeau />
		<LazyMeteoIcone />
		<Compteur />
		<p class="denivele">{{ denivele }} · {{ formatDuree(95) }} · {{ altitude() }} · {{ PAS_PAR_METRE }}</p>
	</section>
</template>
