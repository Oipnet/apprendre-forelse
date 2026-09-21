export default defineEventHandler((event) => {
	setHeader(event, 'x-criee', 'ouverte')
	return { demande: getHeader(event, 'x-demande') ?? null }
})
