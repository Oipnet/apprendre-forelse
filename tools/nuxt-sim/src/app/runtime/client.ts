/**
 * Point d'entrée du module navigateur (/_nuxt/@nuxt-sim/client.js) : Vue, vue-router et le runtime
 * dans un seul fichier, pour que les pages de l'apprenant et le runtime partagent la même instance de Vue.
 * Les modules /_nuxt/@nuxt-sim/vue.js, vue-router.js et imports.js en réexportent les morceaux.
 */
import * as vue from 'vue';
import * as vueRouter from 'vue-router';
import * as nuxt from './composables.ts';

export { startClient } from './app.ts';
export { vue as __vue, vueRouter as __vueRouter, nuxt as __nuxt };
