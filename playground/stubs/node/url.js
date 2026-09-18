/** `url` de Node : l'URL du navigateur, plus la conversion de chemins de fichiers. */
export const URL = globalThis.URL, URLSearchParams = globalThis.URLSearchParams
export function fileURLToPath(u) { return String(u).replace(/^file:\/\//, '') }
export function pathToFileURL(p) { return new URL('file://' + p) }
export default { URL, URLSearchParams, fileURLToPath, pathToFileURL }
