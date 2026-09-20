export default defineEventHandler(async (event) => ({ recu: await readBody(event) }))
