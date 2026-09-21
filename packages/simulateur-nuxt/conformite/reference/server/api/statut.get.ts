export default defineEventHandler((event) => {
	setResponseStatus(event, 202, 'Pris en compte')
	return { statut: getResponseStatus(event), texte: getResponseStatusText(event) }
})
