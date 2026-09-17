<script setup lang="ts">
const valeur = typeof location
let acces = ''
try { acces = String((location as any).x) } catch (e) { acces = (e as Error).message }
</script>

<template>
	<p>{{ valeur }} · {{ acces }}</p>
</template>
