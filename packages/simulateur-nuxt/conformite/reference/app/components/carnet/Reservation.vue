<script setup lang="ts">
const emit = defineEmits<{ reservee: [numero: number] }>()
const nom = ref('')
const couchettes = ref(1)
const erreurs = ref<Record<string, string>>({})
const message = ref('')

const { data: etat } = await useAsyncData('carnet-etat', () => $fetch<{ libres: number }>('/api/carnet/etat'))

async function reserver() {
	erreurs.value = {}
	message.value = ''
	try {
		const reponse = await $fetch<{ numero: number }>('/api/carnet/reservations', { method: 'POST', body: { nom: nom.value, couchettes: couchettes.value } })
		message.value = `Réservation n° ${reponse.numero}`
		emit('reservee', reponse.numero)
		await refreshNuxtData('carnet-etat')
	} catch (error: any) {
		if (error.statusCode === 422) erreurs.value = error.data?.data ?? {}
		else message.value = error.data?.statusMessage ?? error.message
	}
}
</script>

<template>
	<form @submit.prevent="reserver">
		<p class="etat">{{ etat?.libres }} couchettes libres</p>
		<input v-model="nom" name="nom">
		<span v-if="erreurs.nom" class="erreur-nom">{{ erreurs.nom }}</span>
		<input v-model.number="couchettes" name="couchettes" type="number">
		<span v-if="erreurs.couchettes" class="erreur-couchettes">{{ erreurs.couchettes }}</span>
		<button type="submit">Réserver</button>
		<p v-if="message" class="message">{{ message }}</p>
	</form>
</template>
