export default defineEventHandler(async () => {
	const data = useStorage('data')
	return {
		nuitee: await data.getItem('nuitees:12-juillet'),
		absente: await data.getItem('nuitees:13-juillet'),
		existe: await data.hasItem('nuitees:12-juillet'),
		cles: await data.getKeys('nuitees'),
		compteur: await useStorage().getItem('compteur'),
		texte: await useStorage().getItem('texte'),
		racine: (await useStorage().getKeys()).filter((cle) => !cle.startsWith('root:') && !cle.startsWith('src:') && !cle.startsWith('build:') && !cle.startsWith('cache:') && !cle.startsWith('data:')),
	}
})
