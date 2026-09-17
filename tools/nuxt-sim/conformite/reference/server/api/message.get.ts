export default defineEventHandler(() => {
	throw createError({ statusCode: 400, message: 'Il manque la date de la marée' })
})
