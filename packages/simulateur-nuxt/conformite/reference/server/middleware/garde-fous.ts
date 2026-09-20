export default defineEventHandler((event) => {
	if (!event.path.startsWith('/api/garde-fous/')) return
	event.context.gardien = getHeader(event, 'authorization') === 'Bearer cabane-4271'
	if (event.method === 'PATCH' && !event.context.gardien) {
		throw createError({ statusCode: 401, statusMessage: 'Unauthorized', message: 'Jeton du gardien manquant.' })
	}
})
