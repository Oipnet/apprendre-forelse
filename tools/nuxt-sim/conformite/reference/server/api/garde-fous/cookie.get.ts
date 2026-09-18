export default defineEventHandler((event) => {
	setCookie(event, 'passage', 'serveur', { httpOnly: true, path: '/' })
	return { lu: getCookie(event, 'gardien') ?? null, tous: parseCookies(event) }
})
