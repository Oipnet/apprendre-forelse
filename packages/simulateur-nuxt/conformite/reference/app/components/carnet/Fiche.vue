<script setup lang="ts">
const props = defineProps<{ nom: string }>()
const emit = defineEmits<{ choisi: [nom: string] }>()
const { data: meteo, error } = await useFetch('/api/carnet/meteo')
const route = useRoute()
const compte = ref(0)
</script>

<template>
	<article>
		<h2>{{ props.nom }}</h2>
		<p class="meteo">{{ error ? `erreur ${error.statusCode}` : meteo?.ciel }}</p>
		<p class="route">{{ route.path }}</p>
		<button @click="compte++; emit('choisi', props.nom)">choisir {{ compte }}</button>
	</article>
</template>
