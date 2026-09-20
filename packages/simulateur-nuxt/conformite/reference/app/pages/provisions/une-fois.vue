<script setup lang="ts">
const releve = useState('releve-une-fois', () => 0)
await callOnce(async () => {
	releve.value = (await $fetch('/api/provisions/compteur')).releve
})
</script>

<template>
	<p class="releve">Relevé n° {{ releve }}</p>
</template>
