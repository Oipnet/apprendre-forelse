export default defineEventHandler(async (event) => {
	const body = await readBody<{ nom?: string }>(event)
	if (!body?.nom) {
		throw createError({ statusCode: 422, statusMessage: 'Nom manquant', data: { champ: 'nom' } })
	}
	setResponseStatus(event, 201)
	return { id: '2', nom: body.nom }
})
