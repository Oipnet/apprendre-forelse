let appels = 0

export default defineCachedEventHandler(() => {
	appels++
	throw createError({ statusCode: 503, statusMessage: 'Service Unavailable', message: `Appel ${appels}` })
}, { maxAge: 60 })
