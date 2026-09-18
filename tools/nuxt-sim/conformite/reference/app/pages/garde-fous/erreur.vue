<script setup lang="ts">
const route = useRoute()
if (route.query.fatale) {
	throw createError({ statusCode: 404, statusMessage: 'Sentier ferme', message: 'Le sentier est fermé.', data: { slug: 'breche-du-bouc' }, fatal: true })
}
if (route.query.montrer) {
	showError({ statusCode: 410, statusMessage: 'Gone', message: 'Ce sentier a disparu.' })
}
if (route.query.plante) {
	throw new Error('Barnabé a mangé le câble')
}
</script>

<template>
	<p class="erreur">Rien de cassé</p>
</template>
