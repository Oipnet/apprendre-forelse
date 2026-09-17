<template>
	<div class="refuge">
		<header>
			<p class="titre">Refuge du Pic Tordu</p>
			<nav>
				<NuxtLink to="/">Accueil</NuxtLink>
				<NuxtLink to="/sentiers">Sentiers</NuxtLink>
				<NuxtLink to="/topo">Topo</NuxtLink>
			</nav>
		</header>
		<main>
			<slot />
		</main>
		<footer>2 350 m</footer>
	</div>
</template>
