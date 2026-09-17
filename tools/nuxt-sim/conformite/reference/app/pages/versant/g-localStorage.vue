<script setup lang="ts">
const valeur = typeof localStorage
let acces = ''
try { acces = String((localStorage as any).x) } catch (e) { acces = (e as Error).message }
</script>

<template>
	<p>{{ valeur }} · {{ acces }}</p>
</template>
