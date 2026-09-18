/**
 * Modules Node que happy-dom importe sans s'en servir dans un navigateur (fs, net, zlib…) :
 * tout accès rend un objet inerte, et les quelques noms utilisés au chargement sont exportés.
 */
const handler = { get: () => new Proxy(function () {}, handler), apply: () => undefined, construct: () => ({}) }
const empty = new Proxy(function () {}, handler)
export default empty
export const Readable = class {}, Writable = class {}, Transform = class {}, PassThrough = class {}, pipeline = () => {}, promisify = (f) => f, inspect = (x) => String(x), createServer = () => ({}), request = () => ({}), Agent = class {}, constants = {}, createHash = () => ({ update: () => ({ digest: () => '' }) }), webcrypto = globalThis.crypto, randomUUID = () => globalThis.crypto.randomUUID(), readFileSync = () => '', existsSync = () => false, promises = {}, performance = globalThis.performance, isIP = () => 0, lookup = () => {}, spawnSync = () => ({}), inflateSync = () => new Uint8Array(), createGunzip = () => ({}), createInflate = () => ({}), createBrotliDecompress = () => ({}), TextDecoder = globalThis.TextDecoder, TextEncoder = globalThis.TextEncoder
