export default defineEventHandler(() => {
	throw createError({ statusCode: 409, statusMessage: 'Marée basse' })
})
