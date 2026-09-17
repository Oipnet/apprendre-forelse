export default defineEventHandler(async (event) => {
	const { slug } = await getValidatedQuery(event, (query: Record<string, unknown>) => {
		if (typeof query.slug !== 'string') throw new Error('Le paramètre slug est obligatoire')
		return { slug: query.slug }
	})
	return trouverSentier(slug) ?? null
})
