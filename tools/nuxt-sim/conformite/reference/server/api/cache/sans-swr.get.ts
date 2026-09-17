let releve = 0

export default defineCachedEventHandler(() => ({ releve: ++releve }), { maxAge: 1, swr: false })
