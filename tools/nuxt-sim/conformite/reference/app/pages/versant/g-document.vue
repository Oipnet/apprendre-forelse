<script setup lang="ts">
const valeur = typeof document
let acces = ''
try { acces = String((document as any).x) } catch (e) { acces = (e as Error).message }
</script>

<template>
	<p>{{ valeur }} · {{ acces }}</p>
</template>
