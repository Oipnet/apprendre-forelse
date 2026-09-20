export default defineEventHandler(async (event) => {
	const { nom, couchettes } = await readBody<{ nom: string, couchettes: number }>(event)
	await useStorage('data').setItem('nuitees:12-juillet', { nom, couchettes })
	await useStorage().setItem('compteur', 3)
	await useStorage().setItem('texte', 'bonjour')
	return { ok: true }
})
