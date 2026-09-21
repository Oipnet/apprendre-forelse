export default defineEventHandler(async () => {
	await new Promise((resolve) => setTimeout(resolve, 10))
	return { pret: true }
})
