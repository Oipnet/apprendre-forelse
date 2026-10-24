/** `url` de Node : l'URL du navigateur, plus la conversion de chemins de fichiers et de domaines (cookies de happy-dom). */
export const URL = globalThis.URL, URLSearchParams = globalThis.URLSearchParams
export function fileURLToPath(u) { return String(u).replace(/^file:\/\//, '') }
export function pathToFileURL(p) { return new URL('file://' + p) }
// Comme Node : le domaine en punycode, ou une chaîne vide s'il est invalide.
export function domainToASCII(d) { try { return new URL('http://' + d).hostname } catch { return '' } }
export default { URL, URLSearchParams, fileURLToPath, pathToFileURL, domainToASCII }
