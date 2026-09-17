export default defineEventHandler(() => {
	throw createError({ statusCode: 404, statusMessage: 'Sentier introuvable', data: { slug: 'chemin-des-douaniers' } })
})
