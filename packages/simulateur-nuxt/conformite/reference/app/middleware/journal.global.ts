export default defineNuxtRouteMiddleware((to) => {
	if (!to.path.startsWith('/garde-fous')) return
	useState<string[]>('passages', () => []).value.push(to.path)
})
