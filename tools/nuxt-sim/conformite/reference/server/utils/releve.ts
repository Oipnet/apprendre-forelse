let appels = 0

export const releveEnCache = defineCachedFunction(async () => ({ appel: ++appels }), { maxAge: 60, name: 'releve' })
