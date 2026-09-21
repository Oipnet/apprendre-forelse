export default defineEventHandler((event) => ({ accept: getHeader(event, 'accept') ?? null, agent: getHeader(event, 'user-agent') ?? null, hote: getHeader(event, 'host') ?? null }))
