/** `vm` de Node, réduit à ce que happy-dom en utilise : un script évalué dans la page. */
export class Script { constructor(code) { this.code = code } runInContext(ctx) { return (0, eval)(this.code) } }
export function isContext() { return true }
export function createContext(ctx) { return ctx }
export default { Script, isContext, createContext }
