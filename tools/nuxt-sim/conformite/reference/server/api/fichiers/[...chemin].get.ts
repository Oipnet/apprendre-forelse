export default defineEventHandler((event) => ({
	chemin: getRouterParam(event, 'chemin'),
	parametres: getRouterParams(event),
}))
