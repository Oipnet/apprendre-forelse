export default defineEventHandler(async (event) => {
	const { amont } = useRuntimeConfig(event)
	await $fetch(`${amont}/remise-a-zero`)
	const debut = Date.now()
	const erreur = await $fetch(`${amont}/lent`, { timeout: 300 }).catch((error: any) => error.message)
	const appels = (await $fetch<Record<string, number>>(`${amont}/appels`)).lent ?? 0
	return { erreur, appels, avantUneSeconde: Date.now() - debut < 1000 }
})
