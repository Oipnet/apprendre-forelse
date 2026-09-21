export default defineNuxtRouteMiddleware((to) => {
	const jeton = useCookie('gardien')
	if (!jeton.value) {
		return navigateTo({ path: '/garde-fous/connexion', query: { retour: to.fullPath } })
	}
})
