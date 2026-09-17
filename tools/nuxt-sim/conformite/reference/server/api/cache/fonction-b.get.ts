export default defineEventHandler(async () => ({ route: 'b', ...(await releveEnCache()) }))
