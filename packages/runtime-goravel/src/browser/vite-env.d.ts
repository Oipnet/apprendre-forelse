declare module 'virtual:goravel-wasm' {
	/** L'URL du binaire Goravel, ou null s'il n'a pas été construit (go/build.sh). */
	const url: string | null;
	export default url;
}

declare module '*?worker&url' {
	const url: string;
	export default url;
}
