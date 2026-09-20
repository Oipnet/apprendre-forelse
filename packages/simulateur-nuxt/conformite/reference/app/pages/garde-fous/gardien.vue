<script setup lang="ts">
definePageMeta({ middleware: 'gardien' })
const jeton = useCookie('gardien')
const passages = useState<string[]>('passages')
</script>

<template>
	<p class="gardien">Bonjour {{ jeton }} · {{ passages }}</p>
</template>
