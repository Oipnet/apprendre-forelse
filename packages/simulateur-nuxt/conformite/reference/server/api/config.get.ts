export default defineEventHandler((event) => {
	const config = useRuntimeConfig(event)
	return { cleMeteo: config.cleMeteo, nomDuPort: config.public.nomDuPort }
})
