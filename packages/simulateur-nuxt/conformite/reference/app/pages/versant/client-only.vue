<template>
	<section>
		<ClientOnly fallback-tag="p" fallback="Chargement de la carte…">
			<p class="carte">Carte</p>
		</ClientOnly>
		<ClientOnly>
			<p class="carte">Carte</p>
			<template #fallback>
				<p class="attente">Patience…</p>
			</template>
		</ClientOnly>
		<ClientOnly>
			<p class="carte">Sans attente</p>
		</ClientOnly>
	</section>
</template>
