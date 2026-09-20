<script setup lang="ts">
const route = useRoute()
const jeton = useCookie('gardien', { maxAge: 3600, sameSite: 'lax' })
if (route.query.entrer) {
	jeton.value = 'aime'
}
const visites = useCookie<number>('visites', { default: () => 0 })
visites.value++
</script>

<template>
	<p class="connexion">Retour : {{ route.query.retour }} · jeton {{ jeton }} · visites {{ visites }}</p>
</template>
