export default defineEventHandler(() => {
	throw createError({ statusCode: 503, statusMessage: 'Criee fermee' })
})
