export default defineEventHandler(async (event) => {
	const { amont } = useRuntimeConfig(event)
	try {
		return await $fetch(`${amont}/panne`, { retry: 0 })
	} catch (error: any) {
		throw createError({ statusCode: 502, statusMessage: 'Bad Gateway', message: 'La station météo ne répond pas.', data: { statut: error.statusCode ?? null, message: error.message, corps: error.data ?? null } })
	}
})
