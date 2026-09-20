<script setup lang="ts">
import type { NuxtError } from '#app'

defineProps<{ error: NuxtError }>()
</script>

<template>
	<NuxtLayout>
	<main class="page-erreur">
		<h1>{{ error.statusCode }} · {{ error.statusMessage }}</h1>
		<p class="message">{{ error.message }}</p>
		<p class="data">{{ error.data }}</p>
		<button type="button" @click="clearError({ redirect: '/' })">Retour au refuge</button>
	</main>
	</NuxtLayout>
</template>
