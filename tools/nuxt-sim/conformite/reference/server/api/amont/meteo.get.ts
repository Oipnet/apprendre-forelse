export default defineEventHandler(async (event) => {
	const { amont } = useRuntimeConfig(event)
	const releve = await $fetch<{ temperature: number, vent: number }>(`${amont}/meteo`)
	return { ciel: 'Éclaircies', ...releve }
})
