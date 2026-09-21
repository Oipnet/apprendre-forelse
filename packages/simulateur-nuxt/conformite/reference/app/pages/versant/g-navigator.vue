<script setup lang="ts">
const valeur = typeof navigator
let acces = ''
try { acces = String((navigator as any).x) } catch (e) { acces = (e as Error).message }
</script>

<template>
	<p>{{ valeur }} · {{ acces }}</p>
</template>
