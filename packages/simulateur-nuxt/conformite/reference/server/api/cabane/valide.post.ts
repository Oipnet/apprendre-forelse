export default defineEventHandler(async (event) => {
	const query = getQuery(event)
	const body = await readValidatedBody(event, (data: any) => {
		if (query.mode === 'faux') return data?.nom ? true : false
		if (query.mode === 'transforme') return { nom: String(data?.nom ?? '').toUpperCase() }
		if (!data?.nom) throw createError({ statusCode: 422, statusMessage: 'Nom manquant', data: { champ: 'nom' } })
		return data
	})
	setResponseStatus(event, 201)
	return body
})
