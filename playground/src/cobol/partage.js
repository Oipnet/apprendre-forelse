// Partage d'un programme par lien, comme le bac à sable : le modèle (dictionnaire, enregistrements, fichiers appelés
// et leurs données, traitement, langage structuré, sorties, tables, états) voyage dans l'adresse, après le #. Le serveur ne le voit ni ne le garde.
// Format : #programme=<JSON compressé (deflate brut), en base64 pour URL>.

import { generer, normaliser } from './generateur.js';
import { cle, t as tx } from './langue.js';

const PREFIXE = 'programme=';
const VERSION = 2; // 2 : fichiers appelés (appels) ; 1 : un seul fichier, décrit dans le traitement

function versBase64Url(octets) {
  let s = '';
  for (let i = 0; i < octets.length; i += 0x8000) s += String.fromCharCode(...octets.subarray(i, i + 0x8000));
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function depuisBase64Url(t) {
  const s = atob(t.replace(/-/g, '+').replace(/_/g, '/'));
  const o = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) o[i] = s.charCodeAt(i);
  return o;
}
async function transformer(octets, flux) {
  return new Uint8Array(await new Response(new Blob([octets]).stream().pipeThrough(flux)).arrayBuffer());
}

// Ce qui part dans le lien : le modèle seul (pas le contenu de la base, propre à chaque navigateur).
function essentiel(modele) {
  const { version, dictionnaire, structures, appels, traitement, sorties, tables, lignes, etats, defi } = normaliser(modele);
  // le défi relevé voyage avec le programme : celui qui ouvre le lien voit la vérification
  return { v: VERSION, version, dictionnaire, structures, appels, traitement, sorties, tables, lignes, ...(etats.length ? { etats } : {}), ...(defi ? { defi } : {}) };
}

export async function fragmentDe(modele) {
  const json = new TextEncoder().encode(JSON.stringify(essentiel(modele)));
  return PREFIXE + versBase64Url(await transformer(json, new CompressionStream('deflate-raw')));
}

// Lien complet vers la page courante, avec d'où vient la visite (comme les liens du bac à sable).
export async function lienDe(modele, page = location) {
  const url = new URL(page.pathname, page.origin);
  url.search = new URLSearchParams({ utm_source: 'lien', utm_medium: 'partage', utm_campaign: 'atelier-cobol' }).toString();
  return `${url.href}#${await fragmentDe(modele)}`;
}

// Le modèle contenu dans un fragment, vérifié : null si le fragment n'est pas un programme lisible.
export async function lireFragment(hash = location.hash) {
  const h = hash.replace(/^#/, '');
  if (!h.startsWith(PREFIXE)) return null;
  try {
    const octets = await transformer(depuisBase64Url(h.slice(PREFIXE.length)), new DecompressionStream('deflate-raw'));
    if (octets.length > 512 * 1024) return null;
    const m = JSON.parse(new TextDecoder().decode(octets));
    if (!m || typeof m !== 'object' || !Array.isArray(m.dictionnaire) || !Array.isArray(m.structures) || !m.structures.length || typeof m.traitement !== 'object') return null;
    const brut = {
      dictionnaire: m.dictionnaire, structures: m.structures, traitement: m.traitement,
      sorties: Array.isArray(m.sorties) ? m.sorties : [], tables: Array.isArray(m.tables) ? m.tables : [],
      lignes: Array.isArray(m.lignes) ? m.lignes : [],
      etats: Array.isArray(m.etats) ? m.etats : [],
      // version 1 : les données du fichier unique ; version 2 : chaque fichier appelé porte les siennes
      ...(Array.isArray(m.appels) ? { appels: m.appels } : { donnees: Array.isArray(m.donnees) ? m.donnees : [] }),
      // version du modèle (noms des zones) : un lien plus ancien est repris par normaliser
      ...(Number.isInteger(m.version) ? { version: m.version } : {}),
      ...(typeof m.defi === 'string' && /^\w{1,8}$/.test(m.defi) ? { defi: m.defi } : {}),
    };
    const modele = normaliser(brut);
    generer(modele); // un modèle que le générateur refuse n'est pas ouvert
    return modele;
  } catch {
    return null;
  }
}

// Retire le programme de l'adresse, une fois lu (un rechargement ne le rouvre pas).
export function oublierFragment() {
  history.replaceState(null, '', location.pathname + location.search);
}

const CLE_MODELE = cle('modele-v1');
const CLE_PRECEDENT = cle('modele-precedent');
const CLE_OUVERT = cle('partage-ouvert');

const bouton = (texte, action, principal = false) => {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = principal ? 'bouton principal' : 'bouton';
  b.textContent = texte;
  b.addEventListener('click', action);
  return b;
};

// Bandeau d'arrivée par un lien partagé : on propose d'ouvrir le programme, sans écraser le modèle du visiteur
// d'office (il reste récupérable). Après ouverture, la page est rechargée sur le programme reçu.
export async function bandeauReception(zone) {
  if (!zone) return;
  try {
    if (sessionStorage.getItem(CLE_OUVERT)) {
      sessionStorage.removeItem(CLE_OUVERT);
      const precedent = localStorage.getItem(CLE_PRECEDENT);
      zone.replaceChildren(texte(tx('Programme partagé ouvert. Modifiez-le, exécutez-le, puis partagez votre version.', 'Shared program opened. Change it, run it, then share your version.')),
        ...(precedent ? [bouton(tx('Revenir à mon programme', 'Back to my program'), () => { localStorage.setItem(CLE_MODELE, precedent); localStorage.removeItem(CLE_PRECEDENT); location.reload(); })] : []));
      zone.hidden = false;
    }
  } catch { /* stockage indisponible */ }
  const recu = await lireFragment();
  // un lien de défi (#defi=01) a son propre bandeau (atelier.js)
  if (location.hash.startsWith(`#${PREFIXE}`) && !recu) {
    zone.replaceChildren(texte(tx('Ce lien de partage est abîmé ou incomplet : le programme ne peut pas être ouvert.', 'This share link is damaged or incomplete: the program cannot be opened.')), bouton(tx('Fermer', 'Close'), () => { oublierFragment(); zone.hidden = true; }));
    zone.hidden = false;
    return;
  }
  if (!recu) return;
  const nom = recu.traitement?.programme || tx('sans nom', 'unnamed');
  const donnees = recu.appels.reduce((n, a) => n + (a.donnees || []).length, 0);
  zone.replaceChildren(
    texte(tx(`Ce lien contient le programme ${nom} : ${recu.appels.length} fichier(s) appelé(s), ${recu.lignes.length} ligne(s) de traitement, ${donnees} ligne(s) de données. L'ouvrir remplace votre programme, qui reste récupérable.`, `This link holds program ${nom}: ${recu.appels.length} called file(s), ${recu.lignes.length} line(s) of code, ${donnees} line(s) of data. Opening it replaces your program, which you can get back.`)),
    bouton(tx('Ouvrir le programme', 'Open the program'), () => {
      try {
        const actuel = localStorage.getItem(CLE_MODELE);
        if (actuel) localStorage.setItem(CLE_PRECEDENT, actuel);
        localStorage.setItem(CLE_MODELE, JSON.stringify(recu));
        sessionStorage.setItem(CLE_OUVERT, '1');
      } catch {
        zone.replaceChildren(texte(tx('Votre navigateur bloque le stockage local : impossible d\'ouvrir le programme ici.', 'Your browser blocks local storage: the program cannot be opened here.')));
        return;
      }
      oublierFragment();
      location.reload();
    }, true),
    bouton(tx('Garder le mien', 'Keep mine'), () => { oublierFragment(); zone.hidden = true; }),
  );
  zone.hidden = false;
}

function texte(t) {
  const p = document.createElement('p');
  p.textContent = t;
  return p;
}

// Affiche le lien de partage dans une zone, avec un bouton pour le copier ; renvoie vrai s'il est déjà copié.
export async function montrerLien(zone, modele) {
  const lien = await lienDe(modele);
  const champ = document.createElement('input');
  champ.readOnly = true;
  champ.value = lien;
  champ.setAttribute('aria-label', tx('Lien de partage', 'Share link'));
  champ.addEventListener('focus', () => champ.select());
  const copier = bouton(tx('Copier', 'Copy'), async () => {
    try { await navigator.clipboard.writeText(lien); copier.textContent = tx('Copié', 'Copied'); } catch { champ.select(); copier.textContent = tx('Lien sélectionné', 'Link selected'); }
  }, true);
  zone.replaceChildren(texte(tx(`Le programme voyage dans le lien (${lien.length} caractères) : rien n'est gardé sur le serveur. Celui qui l'ouvre choisit de le charger.`, `The program travels inside the link (${lien.length} characters): nothing is kept on the server. Whoever opens it chooses to load it.`)), champ, copier);
  zone.hidden = false;
  try { await navigator.clipboard.writeText(lien); copier.textContent = tx('Copié', 'Copied'); return true; } catch { return false; }
}
