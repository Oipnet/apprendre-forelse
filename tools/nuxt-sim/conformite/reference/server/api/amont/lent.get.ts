export default defineEventHandler(async (event) => {
	const { amont } = useRuntimeConfig(event)
	try {
		return await $fetch(`${amont}/lent`, { timeout: 300, retry: 0 })
	} catch (error: any) {
		return { nom: error.name, message: error.message, statut: error.statusCode ?? null, cause: error.cause?.name ?? null }
	}
})
