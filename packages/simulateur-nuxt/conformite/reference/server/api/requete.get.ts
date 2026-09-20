export default defineEventHandler((event) => ({
	methode: getMethod(event),
	url: getRequestURL(event).href,
	hote: getRequestHost(event),
	protocole: getRequestProtocol(event),
}))
