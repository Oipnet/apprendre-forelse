export default defineEventHandler(async () => ({ route: 'a', ...(await releveEnCache()) }))
