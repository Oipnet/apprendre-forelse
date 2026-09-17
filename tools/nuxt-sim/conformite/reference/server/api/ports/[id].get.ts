export default defineEventHandler((event) => {
	const id = getRouterParam(event, 'id')
	if (id !== '1') {
		throw createError({ statusCode: 404, statusMessage: 'Port inconnu' })
	}
	return { id, nom: 'Port-Bigorneau' }
})
