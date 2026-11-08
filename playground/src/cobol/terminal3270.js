// Émulation d'un écran de terminal 3270 (24 x 80) : champs protégés ou saisissables,
// navigation au clavier, touches d'attention (Entrée, PF1-PF12, Effacer), clavier bloqué jusqu'à Réinit.

export const LIGNES = 24;
export const COLONNES = 80;

export class Ecran3270 {
  constructor(racine, { surAttention, saisie }) {
    this.racine = racine;
    this.saisie = saisie;          // textarea cachée qui reçoit le clavier (utile sur mobile)
    this.surAttention = surAttention;
    this.champs = [];
    this.curseur = 0;
    this.insertion = false;
    this.bloque = null;            // raison du blocage clavier
    this.occupe = false;
    this.message = { texte: '', erreur: false };
    this.etat = racine.parentElement.querySelector('[data-etat]');
    this.lignesDom = Array.from({ length: LIGNES }, () => {
      const d = document.createElement('div');
      d.className = 't-ligne';
      racine.append(d);
      return d;
    });
    saisie.addEventListener('keydown', (e) => this.clavier(e));
    saisie.addEventListener('input', () => {
      const t = saisie.value;
      saisie.value = '';
      for (const ch of t) this.caractere(ch);
      this.dessiner();
    });
    saisie.addEventListener('paste', (e) => {
      e.preventDefault();
      const t = (e.clipboardData?.getData('text') || '').replace(/\r?\n/g, ' ');
      for (const ch of t) if (!this.bloque) this.caractere(ch);
      this.dessiner();
    });
    racine.addEventListener('pointerdown', (e) => {
      const ligne = e.target.closest('.t-ligne');
      if (ligne) {
        const l = this.lignesDom.indexOf(ligne);
        const rect = ligne.getBoundingClientRect();
        const c = Math.max(0, Math.min(COLONNES - 1, Math.floor(((e.clientX - rect.left) / rect.width) * COLONNES)));
        if (!this.bloque) this.curseur = l * COLONNES + c;
      }
      setTimeout(() => saisie.focus({ preventScroll: true }), 0);
      this.dessiner();
    });
    saisie.addEventListener('focus', () => racine.classList.add('actif'));
    saisie.addEventListener('blur', () => racine.classList.remove('actif'));
  }

  // champs : [{ l, c, long?, texte, protege?, num?, vif?, cache?, couleur?, nom? }]
  definir(champs, { curseur, message } = {}) {
    this.champs = champs.map((ch) => {
      const long = Math.min(ch.long ?? ch.texte.length, COLONNES - ch.c); // rien ne déborde sur la ligne suivante
      const texte = (ch.texte ?? '').slice(0, long);
      return { ...ch, long, pos: ch.l * COLONNES + ch.c, valeur: ch.protege ? texte.padEnd(long) : texte, modifie: false };
    });
    const saisissables = this.saisissables();
    if (typeof curseur === 'string') {
      const f = this.champs.find((x) => x.nom === curseur);
      this.curseur = f ? f.pos : saisissables[0]?.pos ?? 0;
    } else if (typeof curseur === 'number') this.curseur = curseur;
    else this.curseur = saisissables[0]?.pos ?? 0;
    this.message = message || { texte: '', erreur: false };
    this.dessiner();
  }

  saisissables() { return this.champs.filter((c) => !c.protege).sort((a, b) => a.pos - b.pos); }
  champA(pos) { return this.champs.find((c) => !c.protege && pos >= c.pos && pos < c.pos + c.long); }
  valeurs() {
    const v = {};
    for (const c of this.champs) if (c.nom && !c.protege) v[c.nom] = c.valeur.replace(/ +$/, '');
    return v;
  }
  modifies() { return new Set(this.champs.filter((c) => c.modifie && c.nom).map((c) => c.nom)); }

  bloquer(raison) { this.bloque = raison; this.dessiner(); }

  // ----- clavier -----
  clavier(e) {
    const k = e.key;
    if (k === 'Escape' && !e.shiftKey) { e.preventDefault(); this.bloque = null; this.dessiner(); return; } // Réinit
    if (this.occupe) { e.preventDefault(); return; }
    if (this.bloque) {
      if (!['Shift', 'Control', 'Alt', 'Meta'].includes(k)) e.preventDefault();
      return;
    }
    const fn = /^F(\d{1,2})$/.exec(k);
    if (fn && Number(fn[1]) <= 12) { e.preventDefault(); this.attention(`PF${(e.shiftKey ? 12 : 0) + Number(fn[1])}`); return; }
    if (k === 'Enter' && !e.shiftKey) { e.preventDefault(); this.attention('ENTREE'); return; }
    if ((k === 'Escape' && e.shiftKey) || k === 'Pause') { e.preventDefault(); this.attention('EFFACER'); return; }
    if (k === 'PageUp') { e.preventDefault(); this.attention('PF7'); return; }
    if (k === 'PageDown') { e.preventDefault(); this.attention('PF8'); return; }
    let traite = true;
    switch (k) {
      case 'Tab': this.champSuivant(e.shiftKey ? -1 : 1); break;
      case 'Enter': this.nouvelleLigne(); break; // Maj+Entrée : début du premier champ de la ligne suivante
      case 'ArrowLeft': this.deplacer(-1); break;
      case 'ArrowRight': this.deplacer(1); break;
      case 'ArrowUp': this.deplacer(-COLONNES); break;
      case 'ArrowDown': this.deplacer(COLONNES); break;
      case 'Home': this.curseur = this.saisissables()[0]?.pos ?? 0; break;
      case 'End': this.effacerFinDeChamp(); break;
      case 'Insert': this.insertion = !this.insertion; break;
      case 'Backspace': this.retourArriere(); break;
      case 'Delete': this.supprimer(); break;
      default:
        if (k.length === 1 && !e.ctrlKey && !e.metaKey) this.caractere(k);
        else traite = false;
    }
    if (traite) { e.preventDefault(); this.dessiner(); }
  }

  deplacer(d) { this.curseur = (this.curseur + d + LIGNES * COLONNES) % (LIGNES * COLONNES); }

  champSuivant(sens) {
    const s = this.saisissables();
    if (!s.length) return;
    const actuel = this.champA(this.curseur);
    if (sens > 0) {
      const n = s.find((c) => c.pos > this.curseur) || s[0];
      this.curseur = n.pos;
    } else {
      // Maj+Tab : début du champ courant si on est au milieu, sinon champ précédent
      if (actuel && this.curseur > actuel.pos) { this.curseur = actuel.pos; return; }
      const avant = s.filter((c) => c.pos < this.curseur);
      this.curseur = (avant.at(-1) || s.at(-1)).pos;
    }
  }

  nouvelleLigne() {
    const ligne = Math.floor(this.curseur / COLONNES);
    const s = this.saisissables();
    const n = s.find((c) => Math.floor(c.pos / COLONNES) > ligne) || s[0];
    if (n) this.curseur = n.pos;
  }

  caractere(ch) {
    if (this.bloque) return;
    const f = this.champA(this.curseur);
    if (!f) return this.bloquer('PROTÉGÉ');
    if (f.num && !/[0-9.,+\- ]/.test(ch)) return this.bloquer('NUM');
    if (f.majuscules !== false) ch = ch.toUpperCase();
    const i = this.curseur - f.pos;
    let v = f.valeur.padEnd(f.long);
    if (this.insertion) {
      if (v.at(-1) !== ' ') return this.bloquer('PLEIN');
      v = v.slice(0, i) + ch + v.slice(i, -1);
    } else v = v.slice(0, i) + ch + v.slice(i + 1);
    f.valeur = v.replace(/ +$/, '');
    f.modifie = true;
    if (i + 1 >= f.long) {
      // champ plein : saut automatique au champ suivant
      this.champSuivant(1);
    } else this.curseur++;
  }

  retourArriere() {
    const f = this.champA(this.curseur);
    if (!f || this.curseur === f.pos) { this.deplacer(-1); return; }
    this.curseur--;
    this.supprimer();
  }

  supprimer() {
    const f = this.champA(this.curseur);
    if (!f) return this.bloquer('PROTÉGÉ');
    const i = this.curseur - f.pos;
    const v = f.valeur.padEnd(f.long);
    f.valeur = (v.slice(0, i) + v.slice(i + 1)).replace(/ +$/, '');
    f.modifie = true;
  }

  effacerFinDeChamp() {
    const f = this.champA(this.curseur);
    if (!f) return this.bloquer('PROTÉGÉ');
    f.valeur = f.valeur.slice(0, this.curseur - f.pos).replace(/ +$/, '');
    f.modifie = true;
  }

  attention(touche) {
    if (touche === 'EFFACER') for (const f of this.saisissables()) { f.valeur = ''; f.modifie = true; }
    this.occupe = true;
    this.dessiner();
    // le temps de réponse de l'hôte : court, mais visible
    setTimeout(() => {
      this.occupe = false;
      try {
        this.surAttention(touche, this.valeurs(), this.modifies(), this.curseur);
      } catch (err) {
        this.message = { texte: `ERREUR INTERNE : ${err.message}`.slice(0, 79), erreur: true };
        console.error(err);
      }
      this.dessiner();
    }, 140);
  }

  // ----- affichage -----
  dessiner() {
    const cellules = Array.from({ length: LIGNES * COLONNES }, () => ({ ch: ' ', cl: '' }));
    for (const f of this.champs) {
      const texte = f.cache ? ''.padEnd(f.long) : f.valeur.padEnd(f.long);
      const cl = ['c-' + (f.couleur || (f.protege ? (f.vif ? 'blanc' : 'bleu') : 'vert')), f.protege ? '' : 's', f.erreur ? 'err' : ''].join(' ');
      for (let i = 0; i < f.long; i++) {
        const p = f.pos + i;
        if (p < cellules.length) cellules[p] = { ch: texte[i], cl };
      }
    }
    if (this.message.texte) {
      const m = this.message.texte.slice(0, COLONNES - 1);
      for (let i = 0; i < m.length; i++) cellules[22 * COLONNES + 1 + i] = { ch: m[i], cl: this.message.erreur ? 'c-rouge' : 'c-blanc' };
    }
    cellules[this.curseur].cl += ' curseur';
    this.lignesDom.forEach((d, l) => {
      d.replaceChildren();
      let courant = null, texte = '';
      const pousser = () => { if (texte) { const s = document.createElement('span'); s.className = courant; s.textContent = texte; d.append(s); } };
      for (let c = 0; c < COLONNES; c++) {
        const x = cellules[l * COLONNES + c];
        if (x.cl !== courant) { pousser(); courant = x.cl; texte = ''; }
        texte += x.ch;
      }
      pousser();
    });
    if (this.etat) {
      const l = Math.floor(this.curseur / COLONNES) + 1, c = (this.curseur % COLONNES) + 1;
      const bloc = this.occupe ? 'X SYSTÈME' : this.bloque ? `X ${this.bloque}  (Échap = Réinit)` : '';
      this.etat.querySelector('[data-bloc]').textContent = bloc;
      this.etat.querySelector('[data-ins]').textContent = this.insertion ? 'INS' : '';
      this.etat.querySelector('[data-pos]').textContent = `${String(l).padStart(2, '0')}/${String(c).padStart(3, '0')}`;
    }
  }
}
