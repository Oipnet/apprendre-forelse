export default defineEventHandler(async (event) => {
	const { amont, cleMeteo } = useRuntimeConfig(event)
	return await $fetch(`${amont}/cle`, { headers: { 'x-api-key': cleMeteo } })
})
