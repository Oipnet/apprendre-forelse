export default defineEventHandler(async (event) => {
	const { amont } = useRuntimeConfig(event)
	await $fetch(`${amont}/remise-a-zero`)
	await $fetch(`${amont}/panne`).catch(() => {})
	const parDefaut = (await $fetch<Record<string, number>>(`${amont}/appels`)).panne ?? 0
	await $fetch(`${amont}/remise-a-zero`)
	await $fetch(`${amont}/panne`, { retry: 2 }).catch(() => {})
	const deux = (await $fetch<Record<string, number>>(`${amont}/appels`)).panne ?? 0
	await $fetch(`${amont}/remise-a-zero`)
	await $fetch(`${amont}/panne`, { method: 'POST', body: {} }).catch(() => {})
	const post = (await $fetch<Record<string, number>>(`${amont}/appels`)).panne ?? 0
	return { parDefaut, deux, post }
})
