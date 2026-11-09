// Base de données de l'atelier : SQLite (sql.js, version JavaScript pur) dans le navigateur.
// Les tables sont décrites dans le modèle ; le contenu est gardé dans le navigateur
// (localStorage), partagé par l'atelier graphique et le terminal.

import { cle } from './langue.js';
import { ddlTable } from './generateur.js';
// Le moteur SQLite en JavaScript pur (sans WebAssembly), servi par le site comme un fichier du build.
import sqlAsm from 'sql.js/dist/sql-asm.js?url';

const CLE = cle('base-v1');
let promesse = null;

function chargerScript(src) {
  return new Promise((ok, ko) => {
    if (globalThis.initSqlJs) return ok();
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => ok();
    s.onerror = () => ko(new Error('moteur SQLite introuvable'));
    document.head.append(s);
  });
}

function versBase64(octets) {
  let s = '';
  for (let i = 0; i < octets.length; i += 0x8000) s += String.fromCharCode(...octets.subarray(i, i + 0x8000));
  return btoa(s);
}
function depuisBase64(t) {
  const s = atob(t);
  const o = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) o[i] = s.charCodeAt(i);
  return o;
}

// Ouvre la base (une seule fois par page). Le moteur (1,3 Mo) n'est chargé qu'à la première demande.
export function ouvrirBase() {
  promesse ??= (async () => {
    await chargerScript(sqlAsm);
    const SQL = await globalThis.initSqlJs();
    let db;
    try {
      const sauve = localStorage.getItem(CLE);
      db = sauve ? new SQL.Database(depuisBase64(sauve)) : new SQL.Database();
    } catch { db = new SQL.Database(); }
    return db;
  })();
  return promesse;
}
export const baseOuverte = () => promesse;

export function sauverBase(db) {
  try { localStorage.setItem(CLE, versBase64(db.export())); } catch { /* stockage plein ou bloqué : la base reste en mémoire */ }
}

// Crée les tables du modèle ; une table dont la description a changé est recréée (vide).
// Renvoie les noms des tables recréées.
export function synchroniser(db, modele) {
  const recreees = [];
  for (const t of modele.tables || []) {
    const attendu = ddlTable(modele, t);
    const r = db.exec('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = ?', [t.nom]);
    const actuel = r[0]?.values[0]?.[0];
    if (actuel === attendu) continue;
    if (actuel) { db.run(`DROP TABLE ${t.nom}`); recreees.push(t.nom); }
    db.run(attendu);
  }
  return recreees;
}

export function contenuTable(db, t) {
  const r = db.exec(`SELECT * FROM ${t.nom} ORDER BY ${t.cle.join(', ')}`);
  return { colonnes: r[0]?.columns || t.rubriques, lignes: r[0]?.values || [] };
}

export function viderTable(db, t) { db.run(`DELETE FROM ${t.nom}`); }

// Remet la base à zéro (toutes les tables supprimées).
export async function reinitialiserBase() {
  if (!promesse) { try { localStorage.removeItem(CLE); } catch {} return; }
  const db = await promesse;
  const r = db.exec('SELECT name FROM sqlite_master WHERE type = \'table\'');
  for (const [nom] of r[0]?.values || []) db.run(`DROP TABLE ${nom}`);
  sauverBase(db);
}

// Valeur d'une colonne mise en forme selon la rubrique (décimales fixes, texte cadré à gauche).
export function formaterValeur(r, v) {
  if (v === null || v === undefined) return '';
  if (r?.type === '9' && typeof v === 'number') return v.toFixed(r.decimales || 0);
  return String(v);
}
