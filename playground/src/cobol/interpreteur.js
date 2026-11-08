// Interpréteur d'un sous-ensemble de COBOL (format fixe), suffisant pour exécuter
// le code produit par le générateur. Il lit le source : il n'exécute pas le modèle.
//
// Pris en charge : FILE-CONTROL (SELECT ... ASSIGN ... LINE SEQUENTIAL), FD/SD, niveaux 01-49 et 88,
// PIC X / 9 / S9 / V / éditions (Z 9 . , - B /), VALUE, SIGN LEADING SEPARATE,
// MOVE, ADD, SUBTRACT, COMPUTE, IF/ELSE/END-IF, PERFORM [UNTIL], PERFORM UNTIL ... END-PERFORM,
// OPEN, CLOSE, READ AT END / NOT AT END, WRITE, SORT USING GIVING, DISPLAY, SET ... TO TRUE,
// INITIALIZE, CONTINUE, GO TO [DEPENDING ON], NEXT SENTENCE, STOP RUN, GOBACK,
// OCCURS (indices), REDEFINES, HIGH-VALUE, LOW-VALUE, ALL littéral, ROUNDED, ADD/SUBTRACT GIVING,
// SPECIAL-NAMES C01 IS nom (début de page), WRITE AFTER/BEFORE ADVANCING
// (mêmes octets que GnuCOBOL en LINE SEQUENTIAL), USAGE COMP acceptés mais stockés en DISPLAY,
// et EXEC SQL ... END-EXEC sur une base SQLite (sql.js) :
// SELECT ... INTO, INSERT, UPDATE, DELETE, DECLARE CURSOR, OPEN, FETCH, CLOSE, COMMIT, ROLLBACK.

import { t as tx } from './langue.js';

export class ErreurCobol extends Error {
  constructor(message, ligne) {
    super(ligne ? tx(`ligne ${ligne} : ${message}`, `line ${ligne}: ${message}`) : message);
    this.ligne = ligne;
  }
}

// ---------- Lecture du source ----------
function tokeniser(source) {
  const jetons = [];
  let sql = null; // EXEC SQL en cours, sur plusieurs lignes
  source.split(/\r?\n/).forEach((brute, idx) => {
    const ligne = idx + 1;
    if (brute.length > 6 && (brute[6] === '*' || brute[6] === '/')) return;
    const code = brute.length > 7 ? brute.slice(7, 72) : '';
    let i = 0;
    if (sql) {
      const k = code.toUpperCase().indexOf('END-EXEC');
      if (k < 0) { sql.v += ' ' + code.trim(); return; }
      sql.v = (sql.v + ' ' + code.slice(0, k)).replace(/\s+/g, ' ').trim();
      jetons.push(sql); sql = null; i = k + 8;
    }
    while (i < code.length) {
      const c = code[i];
      if (c === ' ' || c === '\t') { i++; continue; }
      if ((c === ',' || c === ';') && (i + 1 >= code.length || code[i + 1] === ' ')) { i++; continue; }
      const exec = /^EXEC\s+SQL\b/i.exec(code.slice(i));
      if (exec) {
        const deb = i + exec[0].length;
        const k = code.toUpperCase().indexOf('END-EXEC', deb);
        if (k < 0) { sql = { t: 'sql', v: code.slice(deb).trim(), ligne }; return; }
        jetons.push({ t: 'sql', v: code.slice(deb, k).replace(/\s+/g, ' ').trim(), ligne });
        i = k + 8;
        continue;
      }
      if (c === '"' || c === "'") {
        let j = i + 1, val = '';
        for (;;) {
          if (j >= code.length) throw new ErreurCobol(tx('littéral non terminé', 'unterminated literal'), ligne);
          if (code[j] === c) {
            if (code[j + 1] === c) { val += c; j += 2; continue; }
            break;
          }
          val += code[j++];
        }
        jetons.push({ t: 'lit', v: val, ligne });
        i = j + 1;
        continue;
      }
      const precedent = jetons.at(-1)?.v;
      const apresPic = precedent === 'PIC' || precedent === 'PICTURE' || (precedent === 'IS' && /^PIC/.test(jetons.at(-2)?.v || ''));
      let j = i;
      if (apresPic) {
        while (j < code.length && code[j] !== ' ' && !(code[j] === '.' && (j + 1 >= code.length || code[j + 1] === ' '))) j++;
        jetons.push({ t: 'pic', v: code.slice(i, j).toUpperCase(), ligne });
      } else if (c === '(' || c === ')') {
        jetons.push({ t: 'mot', v: c, ligne }); i++; continue;
      } else {
        const sep = (k) => (code[k] === '.' || code[k] === ',' || code[k] === ';') && (k + 1 >= code.length || code[k + 1] === ' ');
        while (j < code.length && !' ()'.includes(code[j]) && !sep(j)) j++;
        if (j > i) jetons.push({ t: 'mot', v: code.slice(i, j).toUpperCase(), ligne });
      }
      if (code[j] === '.' && (j + 1 >= code.length || code[j + 1] === ' ')) { jetons.push({ t: 'point', v: '.', ligne }); j++; }
      i = j;
    }
  });
  if (sql) throw new ErreurCobol(tx('EXEC SQL sans END-EXEC', 'EXEC SQL without END-EXEC'), sql.ligne);
  return jetons;
}

class Lecteur {
  constructor(jetons) { this.j = jetons; this.p = 0; }
  voir(k = 0) { return this.j[this.p + k]; }
  est(...mots) { const x = this.voir(); return x && x.t === 'mot' && mots.includes(x.v); }
  estPoint() { return this.voir()?.t === 'point'; }
  suivant() { const x = this.j[this.p++]; if (!x) throw new ErreurCobol(tx('fin de source inattendue', 'unexpected end of source')); return x; }
  mot(attendu) {
    const x = this.suivant();
    if (x.t !== 'mot' || (attendu && x.v !== attendu)) throw new ErreurCobol(tx(`attendu ${attendu || 'un mot'}, trouvé « ${x.v} »`, `expected ${attendu || 'a word'}, found "${x.v}"`), x.ligne);
    return x.v;
  }
  optionnel(...mots) { if (this.est(...mots)) { this.p++; return true; } return false; }
  point() { const x = this.suivant(); if (x.t !== 'point') throw new ErreurCobol(tx(`point attendu, trouvé « ${x.v} »`, `period expected, found "${x.v}"`), x.ligne); }
  fin() { return this.p >= this.j.length; }
  ligne() { return this.voir()?.ligne; }
}

// ---------- Constantes figuratives ----------
// ZERO, SPACE, HIGH-VALUE, LOW-VALUE, QUOTE et ALL littéral : consommées si présentes, sinon null.
function figuratif(L) {
  const x = L.voir();
  if (!x || x.t !== 'mot') return null;
  if (x.v === 'ALL') {
    L.p++;
    const y = L.voir();
    if (y?.t === 'lit' && y.v.length) { L.p++; return { all: y.v }; }
    const f = figuratif(L);
    if (!f) throw new ErreurCobol(tx('ALL : littéral attendu', 'ALL: literal expected'), x.ligne);
    return f;
  }
  const fig = FIGURATIFS[x.v];
  if (!fig) return null;
  L.p++;
  return { fig };
}
const FIGURATIFS = {
  ZERO: 'ZERO', ZEROS: 'ZERO', ZEROES: 'ZERO', SPACE: 'SPACE', SPACES: 'SPACE',
  'HIGH-VALUE': 'HIGH', 'HIGH-VALUES': 'HIGH', 'LOW-VALUE': 'LOW', 'LOW-VALUES': 'LOW', QUOTE: 'QUOTE', QUOTES: 'QUOTE',
};
const CARACTERE_FIGURATIF = { SPACE: ' ', ZERO: '0', HIGH: '\xff', LOW: '\x00', QUOTE: '"' };
const USAGES = ['DISPLAY', 'COMP', 'COMP-1', 'COMP-2', 'COMP-3', 'COMP-4', 'COMP-5', 'COMPUTATIONAL', 'COMPUTATIONAL-1',
  'COMPUTATIONAL-2', 'COMPUTATIONAL-3', 'COMPUTATIONAL-4', 'COMPUTATIONAL-5', 'BINARY', 'PACKED-DECIMAL', 'INDEX'];
const CLAUSES = ['PIC', 'PICTURE', 'VALUE', 'USAGE', 'SYNC', 'SYNCHRONIZED', 'REDEFINES', 'BLANK', 'OCCURS', 'SIGN',
  'ASCENDING', 'DESCENDING', 'INDEXED', ...USAGES];

// Vue d'une donnée décalée de delta octets (occurrence d'une table) ; ses enfants le sont aussi.
function decaler(el, delta) {
  if (!delta) return el;
  return Object.create(el, {
    pos: { value: el.pos + delta },
    enfants: { get: () => el.enfants.map((e) => decaler(e, delta)) },
  });
}

// ---------- Clauses PIC ----------
function developperPic(pic) {
  return pic.replace(/(.)\((\d+)\)/g, (_, c, n) => c.repeat(Number(n)));
}
function analyserPic(pic, signeSepare) {
  const d = developperPic(pic.toUpperCase());
  if (/^X+$/.test(d) || /^A+$/.test(d)) return { genre: 'X', longueur: d.length };
  if (/^S?9+(V9+)?$/.test(d)) {
    const signe = d.startsWith('S');
    const [ent, dec = ''] = d.replace('S', '').split('V');
    const chiffres = ent.length + dec.length;
    return { genre: '9', chiffres, echelle: dec.length, signe, signeSepare: signe && signeSepare, longueur: chiffres + (signe && signeSepare ? 1 : 0) };
  }
  if (/^[Z9.,\-+B/*]+$/.test(d)) {
    const echelle = d.includes('.') ? [...d.split('.')[1]].filter((c) => '9Z'.includes(c)).length : 0;
    return { genre: 'E', masque: d, longueur: d.length, echelle };
  }
  throw new ErreurCobol(tx(`clause PIC non prise en charge : ${pic}`, `unsupported PIC clause: ${pic}`));
}

// ---------- Nombres décimaux exacts (BigInt + échelle) ----------
const P10 = (n) => 10n ** BigInt(n);
class Dec {
  constructor(n, e = 0) { this.n = n; this.e = e; }
  static de(texte) {
    const m = String(texte).match(/^([+-]?)(\d*)(?:\.(\d*))?$/);
    if (!m) return new Dec(0n);
    const frac = m[3] || '';
    const n = BigInt((m[2] || '0') + frac) * (m[1] === '-' ? -1n : 1n);
    return new Dec(n, frac.length);
  }
  aEchelle(e) { // troncature vers zéro, comme COBOL sans ROUNDED
    if (e >= this.e) return this.n * P10(e - this.e);
    return this.n / P10(this.e - e);
  }
  plus(b) { const e = Math.max(this.e, b.e); return new Dec(this.aEchelle(e) + b.aEchelle(e), e); }
  moins(b) { const e = Math.max(this.e, b.e); return new Dec(this.aEchelle(e) - b.aEchelle(e), e); }
  fois(b) { return new Dec(this.n * b.n, this.e + b.e); }
  divise(b) {
    if (b.n === 0n) throw new ErreurCobol(tx('division par zéro', 'division by zero'));
    const e = 12;
    return new Dec((this.n * P10(e + b.e)) / (b.n * P10(this.e)) , e);
  }
  cmp(b) { const e = Math.max(this.e, b.e); const x = this.aEchelle(e), y = b.aEchelle(e); return x < y ? -1 : x > y ? 1 : 0; }
}

// ---------- Programme ----------
export class Programme {
  constructor(source) {
    this.source = source;
    this.donnees = new Map();     // nom -> élément
    this.fichiers = new Map();    // nom -> { assign, organisation, enreg, tri }
    this.paragraphes = new Map(); // nom -> instructions
    this.ordre = [];
    this.mnemoniques = new Map(); this.conditions = new Map();  // nom 88 -> { parent, valeurs }
    this.curseurs = new Map();    // nom -> { sql, entrees }
    this.analyser(tokeniser(source));
  }

  analyser(jetons) {
    const L = new Lecteur(jetons);
    const division = (nom) => { while (!L.fin() && !(L.est(nom) && L.voir(1)?.v === 'DIVISION')) L.p++; };
    // ENVIRONMENT DIVISION facultative ; SPECIAL-NAMES : C01 IS nom (saut au canal 1 = début de page)
    while (!L.fin() && !(L.est('DATA') && L.voir(1)?.v === 'DIVISION') && !L.est('SELECT')) {
      if (L.est('C01', 'CSP') && L.voir(1)?.v === 'IS' && L.voir(2)?.t === 'mot') this.mnemoniques.set(L.voir(2).v, L.voir().v);
      L.p++;
    }
    while (L.est('SELECT')) this.select(L);
    division('DATA');
    L.mot('DATA'); L.mot('DIVISION'); L.point();
    let fichierCourant = null;
    while (!L.fin() && !(L.est('PROCEDURE') && L.voir(1)?.v === 'DIVISION')) {
      if (L.est('FILE', 'WORKING-STORAGE', 'LOCAL-STORAGE', 'LINKAGE') && L.voir(1)?.v === 'SECTION') {
        L.p += 2; L.point(); fichierCourant = null; continue;
      }
      if (L.voir()?.t === 'sql') {
        const x = L.suivant();
        this.sqlDonnees(x);
        if (L.estPoint()) L.point();
        continue;
      }
      if (L.est('FD', 'SD')) {
        const tri = L.mot() === 'SD';
        const nom = L.mot();
        while (!L.estPoint()) L.p++;
        L.point();
        const f = this.fichiers.get(nom);
        if (!f) throw new ErreurCobol(tx(`fichier ${nom} non déclaré dans FILE-CONTROL`, `file ${nom} not declared in FILE-CONTROL`), L.ligne());
        f.tri = tri;
        fichierCourant = f;
        continue;
      }
      this.niveaux(L, fichierCourant);
    }
    this.allouer();
    L.mot('PROCEDURE'); L.mot('DIVISION'); L.point();
    this.procedure(L);
  }

  select(L) {
    L.mot('SELECT');
    L.optionnel('OPTIONAL');
    const nom = L.mot();
    const f = { nom, assign: nom, organisation: 'SEQUENTIAL', enreg: null, tri: false };
    while (!L.estPoint()) {
      if (L.optionnel('ASSIGN')) { L.optionnel('TO'); const x = L.suivant(); f.assign = x.v; }
      else if (L.optionnel('ORGANIZATION')) { L.optionnel('IS'); f.organisation = L.optionnel('LINE') ? (L.mot('SEQUENTIAL'), 'LINE SEQUENTIAL') : L.mot(); }
      else L.p++;
    }
    L.point();
    this.fichiers.set(nom, f);
  }

  niveaux(L, fichier) {
    const pile = [];
    this._racines ??= [];
    const ligne = L.ligne();
    const niveau = Number(L.mot());
    if (!Number.isInteger(niveau)) throw new ErreurCobol(tx(`numéro de niveau attendu`, `level number expected`), ligne);
    const nom = L.est('FILLER') || L.voir()?.t !== 'mot' ? (L.optionnel('FILLER'), null) : L.mot();
    const el = { niveau, nom, ligne, enfants: [], pic: null, valeur: undefined, signeSepare: false };
    if (niveau === 88) {
      L.optionnel('VALUE', 'VALUES'); L.optionnel('IS', 'ARE');
      const valeurs = [];
      while (!L.estPoint()) valeurs.push(this.litteralDonnee(L));
      L.point();
      const parent = this._dernierElementaire;
      if (!parent) throw new ErreurCobol(tx('niveau 88 sans donnée', 'level 88 without a data item'), ligne);
      this.conditions.set(nom, { parent, valeurs });
      return;
    }
    const usage = () => {
      // COMP, COMP-3... : acceptés, mais la zone reste stockée en DISPLAY (un chiffre par octet)
      if (L.est(...USAGES)) { el.usage = L.mot(); return true; }
      return false;
    };
    while (!L.estPoint()) {
      if (L.optionnel('PIC', 'PICTURE')) { L.optionnel('IS'); el.picTexte = L.suivant().v; }
      else if (L.optionnel('VALUE')) { L.optionnel('IS'); el.valeur = this.litteralDonnee(L); }
      else if (L.optionnel('SIGN')) { L.optionnel('IS'); L.optionnel('LEADING'); if (L.optionnel('SEPARATE')) L.optionnel('CHARACTER'); el.signeSepare = true; }
      else if (L.optionnel('USAGE')) { L.optionnel('IS'); if (!usage()) throw new ErreurCobol(tx(`USAGE non pris en charge : ${L.voir()?.v}`, `unsupported USAGE: ${L.voir()?.v}`), ligne); }
      else if (usage()) { /* USAGE implicite */ }
      else if (L.optionnel('SYNC', 'SYNCHRONIZED')) L.optionnel('LEFT', 'RIGHT');
      else if (L.optionnel('REDEFINES')) el.redefinit = L.mot();
      else if (L.optionnel('BLANK')) { L.optionnel('WHEN'); L.mot(); el.blancSiZero = true; }
      else if (L.optionnel('OCCURS')) {
        el.occurs = Number(L.mot());
        if (!Number.isInteger(el.occurs) || el.occurs < 1) throw new ErreurCobol(tx('OCCURS : nombre d\'occurrences attendu', 'OCCURS: number of occurrences expected'), ligne);
        L.optionnel('TIMES');
        while (L.est('ASCENDING', 'DESCENDING', 'INDEXED')) {
          if (L.optionnel('INDEXED')) {
            L.optionnel('BY');
            while (L.voir()?.t === 'mot' && !L.est(...CLAUSES)) this.index(L.mot(), L.ligne());
          } else {
            L.mot(); L.optionnel('KEY'); L.optionnel('IS');
            while (L.voir()?.t === 'mot' && !L.est(...CLAUSES)) L.mot();
          }
        }
      }
      else throw new ErreurCobol(tx(`clause non prise en charge : ${L.voir().v}`, `unsupported clause: ${L.voir().v}`), L.ligne());
    }
    L.point();
    if (!el.picTexte && el.usage === 'INDEX') el.picTexte = 'S9(9)';
    if (el.picTexte) el.pic = analyserPic(el.picTexte, el.signeSepare);
    // rattachement hiérarchique
    if (niveau === 1 || niveau === 77) {
      this._racines.push(el);
      el.fichier = fichier;
      if (fichier) fichier.enreg ??= el;
      this._pile = [el];
    } else {
      const p = this._pile;
      while (p.length && p.at(-1).niveau >= niveau) p.pop();
      if (!p.length) throw new ErreurCobol(tx(`niveau ${niveau} sans groupe parent`, `level ${niveau} without a parent group`), ligne);
      el.parent = p.at(-1);
      el.parent.enfants.push(el);
      p.push(el);
    }
    if (el.nom) {
      if (this.donnees.has(el.nom)) throw new ErreurCobol(tx(`donnée ${el.nom} déclarée deux fois`, `data item ${el.nom} declared twice`), ligne);
      this.donnees.set(el.nom, el);
    }
    this._dernierElementaire = el;
  }

  litteralDonnee(L) {
    const x = L.voir();
    const f = figuratif(L);
    if (f) return f;
    L.p++;
    if (x.t === 'lit') return { alpha: x.v };
    if (/^[+-]?\d*\.?\d+$/.test(x.v)) return { num: x.v };
    throw new ErreurCobol(tx(`valeur non prise en charge : ${x.v}`, `unsupported value: ${x.v}`), x.ligne);
  }

  // INDEXED BY : l'index est une donnée à part, entière
  index(nom, ligne) {
    if (this.donnees.has(nom)) throw new ErreurCobol(tx(`donnée ${nom} déclarée deux fois`, `data item ${nom} declared twice`), ligne);
    const el = { niveau: 77, nom, ligne, enfants: [], pic: analyserPic('S9(9)'), valeur: { num: '1' }, signeSepare: false, estIndex: true };
    this._racines.push(el);
    this.donnees.set(nom, el);
  }

  allouer() {
    // chaque 01 a sa zone mémoire ; les enregistrements d'un même FD partagent la leur,
    // un 01 REDEFINES partage celle du 01 redéfini.
    // longueur = une occurrence ; une donnée OCCURS n occupe n fois sa longueur dans son groupe.
    const taille = (el) => {
      if (el.pic) { if (el.enfants.length) throw new ErreurCobol(tx(`groupe ${el.nom} avec PIC`, `group ${el.nom} with a PIC`), el.ligne); return (el.longueur = el.pic.longueur); }
      if (!el.enfants.length) throw new ErreurCobol(tx(`donnée ${el.nom || 'FILLER'} sans PIC`, `data item ${el.nom || 'FILLER'} without a PIC`), el.ligne);
      let s = 0;
      for (const e of el.enfants) { taille(e); if (!e.redefinit) s += e.longueur * (e.occurs || 1); }
      return (el.longueur = s);
    };
    const placer = (el, zone, pos) => {
      el.zone = zone; el.pos = pos;
      let p = pos;
      for (const e of el.enfants) {
        if (e.redefinit) {
          const cible = el.enfants.find((x) => x.nom === e.redefinit && x !== e);
          if (!cible) throw new ErreurCobol(tx(`REDEFINES ${e.redefinit} : donnée de même niveau introuvable`, `REDEFINES ${e.redefinit}: no data item at the same level`), e.ligne);
          placer(e, zone, cible.pos);
        } else { placer(e, zone, p); p += e.longueur * (e.occurs || 1); }
      }
    };
    for (const r of this._racines) {
      taille(r);
      if (r.occurs) throw new ErreurCobol(tx(`OCCURS interdit au niveau ${r.niveau}`, `OCCURS not allowed at level ${r.niveau}`), r.ligne);
      let zone;
      if (r.redefinit) {
        const cible = this.donnees.get(r.redefinit);
        if (!cible || cible.parent) throw new ErreurCobol(tx(`REDEFINES ${r.redefinit} : niveau 01 introuvable`, `REDEFINES ${r.redefinit}: level 01 not found`), r.ligne);
        zone = cible.zone;
      } else zone = r.fichier && r.fichier.enreg !== r ? r.fichier.enreg.zone : { octets: [] };
      placer(r, zone, 0);
      if (zone.octets.length < r.longueur) zone.octets.push(...Array(r.longueur - zone.octets.length).fill(' '));
    }
  }

  // ---------- PROCEDURE DIVISION ----------
  procedure(L) {
    let courant = null;
    while (!L.fin()) {
      const x = L.voir();
      if (x.t === 'mot' && L.voir(1)?.t === 'point' && !VERBES.has(x.v)) {
        courant = [];
        this.paragraphes.set(x.v, courant);
        this.ordre.push(x.v);
        L.p += 2;
        continue;
      }
      if (x.t === 'mot' && L.voir(1)?.v === 'SECTION') { L.p += 2; L.point(); continue; }
      if (!courant) { courant = []; this.paragraphes.set('(DEBUT)', courant); this.ordre.push('(DEBUT)'); }
      // une phrase (jusqu'au point) n'est regroupée que si elle contient NEXT SENTENCE
      this._phraseSuivante = false;
      const ligne = L.ligne();
      const phrase = this.instructions(L, []);
      if (this._phraseSuivante) courant.push({ v: 'PHRASE', ligne, corps: phrase });
      else courant.push(...phrase);
      if (L.estPoint()) L.point();
    }
  }

  instructions(L, arrets) {
    const liste = [];
    while (!L.fin() && !L.estPoint() && !L.est(...arrets)) liste.push(this.instruction(L));
    return liste;
  }

  instruction(L) {
    const ligne = L.ligne();
    if (L.voir()?.t === 'sql') return this.sqlInstruction(L.suivant());
    const v = L.mot();
    const ins = { v, ligne };
    switch (v) {
      case 'MOVE': {
        ins.source = this.operande(L); L.mot('TO');
        ins.cibles = [];
        while (L.voir()?.t === 'mot' && !VERBES.has(L.voir().v) && !FINS.has(L.voir().v)) ins.cibles.push(this.reference(L));
        return ins;
      }
      case 'ADD': case 'SUBTRACT': {
        ins.sources = [];
        while (!L.est(v === 'ADD' ? 'TO' : 'FROM')) ins.sources.push(this.operande(L));
        L.mot();
        ins.cibles = [];
        const cibles = () => {
          const liste = [];
          while (L.voir()?.t === 'mot' && !VERBES.has(L.voir().v) && !FINS.has(L.voir().v) && !L.est('ROUNDED', 'GIVING')) {
            liste.push(this.reference(L));
            if (L.optionnel('ROUNDED')) ins.arrondi = true;
          }
          return liste;
        };
        ins.cibles = cibles();
        // ADD a TO b GIVING c : c reçoit b + a ; SUBTRACT a FROM b GIVING c : c reçoit b - a
        if (L.optionnel('GIVING')) { ins.base = ins.cibles; ins.cibles = cibles(); }
        return ins;
      }
      case 'COMPUTE': {
        ins.cible = this.reference(L);
        if (L.optionnel('ROUNDED')) ins.arrondi = true;
        if (!(L.optionnel('=') || L.optionnel('EQUAL'))) throw new ErreurCobol(tx('= attendu', '= expected'), ligne);
        ins.expr = this.expression(L);
        return ins;
      }
      case 'DISPLAY': {
        ins.elems = [];
        while (L.voir() && !L.estPoint() && L.voir().t !== 'sql' && (L.voir().t === 'lit' || (!VERBES.has(L.voir().v) && !FINS.has(L.voir().v) && L.voir().v !== 'UPON'))) ins.elems.push(this.operande(L));
        if (L.optionnel('UPON')) L.mot();
        return ins;
      }
      case 'IF': {
        ins.cond = this.condition(L);
        L.optionnel('THEN');
        ins.alors = this.instructions(L, ['ELSE', 'END-IF']);
        ins.sinon = L.optionnel('ELSE') ? this.instructions(L, ['END-IF']) : [];
        L.optionnel('END-IF');
        return ins;
      }
      case 'PERFORM': {
        if (L.est('UNTIL')) {
          L.mot(); ins.jusque = this.condition(L);
          ins.corps = this.instructions(L, ['END-PERFORM']);
          L.mot('END-PERFORM');
          return ins;
        }
        ins.para = L.mot();
        if (L.optionnel('THRU', 'THROUGH')) ins.jusquA = L.mot();
        if (L.optionnel('UNTIL')) ins.jusque = this.condition(L);
        return ins;
      }
      case 'OPEN': {
        ins.ouvertures = [];
        while (L.est('INPUT', 'OUTPUT', 'EXTEND')) {
          const mode = L.mot();
          while (L.voir()?.t === 'mot' && this.fichiers.has(L.voir().v)) ins.ouvertures.push({ mode, f: this.fichier(L) });
        }
        return ins;
      }
      case 'CLOSE': {
        ins.fichiers = [];
        while (L.voir()?.t === 'mot' && this.fichiers.has(L.voir().v)) ins.fichiers.push(this.fichier(L));
        return ins;
      }
      case 'READ': {
        ins.f = this.fichier(L);
        L.optionnel('NEXT'); L.optionnel('RECORD');
        if (L.optionnel('INTO')) ins.into = this.reference(L);
        ins.finFichier = []; ins.sinonFin = [];
        if (L.optionnel('AT')) { L.mot('END'); ins.finFichier = this.instructions(L, ['NOT', 'END-READ']); }
        else if (L.optionnel('END')) ins.finFichier = this.instructions(L, ['NOT', 'END-READ']);
        if (L.est('NOT') && ['AT', 'END'].includes(L.voir(1)?.v)) { L.mot(); L.optionnel('AT'); L.mot('END'); ins.sinonFin = this.instructions(L, ['END-READ']); }
        L.optionnel('END-READ');
        return ins;
      }
      case 'WRITE': {
        ins.enreg = this.reference(L);
        if (L.optionnel('FROM')) ins.depuis = this.operande(L);
        if (L.est('AFTER', 'BEFORE')) {
          ins.avance = L.mot();
          L.optionnel('ADVANCING');
          if (L.optionnel('PAGE')) ins.page = true;
          else if (this.mnemoniques.get(L.voir()?.v) === 'C01') { L.mot(); ins.page = true; }
          else if (this.mnemoniques.has(L.voir()?.v)) throw new ErreurCobol(tx(`ADVANCING ${L.voir().v} : seul le canal C01 (début de page) est pris en charge`, `ADVANCING ${L.voir().v}: only channel C01 (top of page) is supported`), ligne);
          else { ins.lignes = this.operande(L); L.optionnel('LINE', 'LINES'); }
        }
        return ins;
      }
      case 'SORT': {
        ins.f = this.fichier(L);
        ins.cles = [];
        while (L.optionnel('ON')) {
          const sens = L.mot(); L.optionnel('KEY');
          while (L.voir()?.t === 'mot' && this.donnees.has(L.voir().v)) ins.cles.push({ sens, ref: this.reference(L) });
        }
        L.optionnel('WITH'); if (L.optionnel('DUPLICATES')) { L.optionnel('IN'); L.optionnel('ORDER'); }
        L.mot('USING'); ins.entree = this.fichier(L);
        L.mot('GIVING'); ins.sortie = this.fichier(L);
        return ins;
      }
      case 'SET': {
        if (this.conditions.has(L.voir()?.v)) {
          ins.cond = L.mot();
          L.mot('TO'); L.mot('TRUE');
          return ins;
        }
        ins.cibles = [];
        while (L.voir()?.t === 'mot' && !L.est('TO', 'UP', 'DOWN')) ins.cibles.push(this.reference(L));
        if (!ins.cibles.length) throw new ErreurCobol(tx('SET : donnée ou condition attendue', 'SET: data item or condition expected'), ligne);
        ins.mode = L.mot();
        if (ins.mode !== 'TO') L.mot('BY');
        ins.source = this.operande(L);
        return ins;
      }
      case 'INITIALIZE': {
        ins.cibles = [];
        while (L.voir()?.t === 'mot' && this.donnees.has(L.voir().v)) ins.cibles.push(this.reference(L));
        return ins;
      }
      case 'STOP': L.mot('RUN'); return ins;
      case 'GO': {
        L.optionnel('TO');
        const paras = [];
        while (L.voir()?.t === 'mot' && !L.est('DEPENDING') && !VERBES.has(L.voir().v) && !FINS.has(L.voir().v)) paras.push(L.mot());
        if (!paras.length) throw new ErreurCobol(tx('GO TO : paragraphe attendu', 'GO TO: paragraph expected'), ligne);
        if (L.optionnel('DEPENDING')) { L.optionnel('ON'); ins.paras = paras; ins.selon = this.operande(L); }
        else if (paras.length > 1) throw new ErreurCobol(tx('GO TO : plusieurs paragraphes sans DEPENDING ON', 'GO TO: several paragraphs without DEPENDING ON'), ligne);
        else ins.para = paras[0];
        return ins;
      }
      case 'NEXT': L.mot('SENTENCE'); this._phraseSuivante = true; return ins;
      case 'GOBACK': case 'CONTINUE': case 'EXIT': return ins;
      default:
        throw new ErreurCobol(tx(`instruction non prise en charge : ${v}`, `unsupported statement: ${v}`), ligne);
    }
  }

  fichier(L) {
    const ligne = L.ligne();
    const nom = L.mot();
    const f = this.fichiers.get(nom);
    if (!f) throw new ErreurCobol(tx(`fichier inconnu : ${nom}`, `unknown file: ${nom}`), ligne);
    return f;
  }

  // ----- EXEC SQL -----
  // :ZONE -> ? ; renvoie le texte SQL et les données à lier, dans l'ordre
  hotes(texte, ligne) {
    const entrees = [];
    const sql = texte.replace(/:([A-Z0-9][A-Z0-9-]*)/gi, (_, nom) => {
      const el = this.donnees.get(nom.toUpperCase());
      if (!el) throw new ErreurCobol(tx(`EXEC SQL : zone :${nom} inconnue`, `EXEC SQL: unknown host variable :${nom}`), ligne);
      if (el.enfants.length) throw new ErreurCobol(tx(`EXEC SQL : :${nom} est un groupe, donnez une zone élémentaire`, `EXEC SQL: :${nom} is a group, give an elementary item`), ligne);
      entrees.push(el);
      return '?';
    });
    return { sql, entrees };
  }
  listeHotes(texte, ligne) {
    return texte.split(',').map((x) => {
      const m = /^\s*:([A-Z0-9][A-Z0-9-]*)\s*$/i.exec(x);
      if (!m) throw new ErreurCobol(tx(`EXEC SQL : zone :NOM attendue dans INTO, trouvé « ${x.trim()} »`, `EXEC SQL: :NAME expected in INTO, found "${x.trim()}"`), ligne);
      return this.hotes(x, ligne).entrees[0];
    });
  }
  sqlDonnees(x) {
    const m = /^DECLARE\s+([A-Z0-9-]+)\s+CURSOR\s+FOR\s+(SELECT\b.*)$/is.exec(x.v);
    if (m) { this.curseurs.set(m[1].toUpperCase(), { ...this.hotes(m[2], x.ligne), ligne: x.ligne }); return; }
    if (/^INCLUDE\b/i.test(x.v)) return;
    throw new ErreurCobol(tx(`EXEC SQL non pris en charge dans les données : ${x.v.slice(0, 40)}`, `EXEC SQL not supported in the data division: ${x.v.slice(0, 40)}`), x.ligne);
  }
  sqlInstruction(x) {
    const ligne = x.ligne, t = x.v;
    const ins = { v: 'SQL', ligne, texte: t };
    let m;
    if ((m = /^SELECT\s+(.+?)\s+INTO\s+(.+?)\s+FROM\s+(.+)$/is.exec(t))) {
      ins.genre = 'SELECT1';
      Object.assign(ins, this.hotes(`SELECT ${m[1]} FROM ${m[3]}`, ligne));
      ins.into = this.listeHotes(m[2], ligne);
    } else if ((m = /^DECLARE\s+([A-Z0-9-]+)\s+CURSOR\s+FOR\s+(SELECT\b.*)$/is.exec(t))) {
      this.curseurs.set(m[1].toUpperCase(), { ...this.hotes(m[2], ligne), ligne });
      ins.genre = 'RIEN';
    } else if ((m = /^OPEN\s+([A-Z0-9-]+)$/i.exec(t))) { ins.genre = 'OPEN'; ins.curseur = m[1].toUpperCase(); }
    else if ((m = /^FETCH\s+([A-Z0-9-]+)\s+INTO\s+(.+)$/is.exec(t))) { ins.genre = 'FETCH'; ins.curseur = m[1].toUpperCase(); ins.into = this.listeHotes(m[2], ligne); }
    else if ((m = /^CLOSE\s+([A-Z0-9-]+)$/i.exec(t))) { ins.genre = 'CLOSE'; ins.curseur = m[1].toUpperCase(); }
    else if (/^(COMMIT|ROLLBACK)(\s+WORK)?$/i.test(t)) ins.genre = t.split(/\s/)[0].toUpperCase();
    else if (/^INCLUDE\b/i.test(t)) ins.genre = 'RIEN';
    else if (/^(INSERT|UPDATE|DELETE)\b/i.test(t)) { ins.genre = 'MAJ'; Object.assign(ins, this.hotes(t, ligne)); }
    else throw new ErreurCobol(tx(`EXEC SQL non pris en charge : ${t.slice(0, 40)}`, `unsupported EXEC SQL: ${t.slice(0, 40)}`), ligne);
    return ins;
  }

  // NOM ou NOM (i [, j...]) quand NOM ou un de ses groupes porte OCCURS ; i peut être « I + 1 ».
  reference(L) {
    const ligne = L.ligne();
    const nom = L.mot();
    const el = this.donnees.get(nom);
    if (!el) throw new ErreurCobol(tx(`donnée inconnue : ${nom}`, `unknown data item: ${nom}`), ligne);
    let tables = 0;
    for (let e = el; e; e = e.parent) if (e.occurs) tables++;
    if (!tables || !L.est('(')) return { el };
    L.mot('(');
    const indices = [];
    while (!L.est(')')) {
      let i = this.operande(L);
      if (L.est('+', '-') && /^\d+$/.test(L.voir(1)?.v || '')) { const op = L.mot(); i = { op, g: i, d: { num: L.mot() } }; }
      indices.push(i);
    }
    L.mot(')');
    if (indices.length !== tables) throw new ErreurCobol(tx(`${nom} : ${tables} indice(s) attendu(s), ${indices.length} donné(s)`, `${nom}: ${tables} subscript(s) expected, ${indices.length} given`), ligne);
    return { el, indices };
  }

  operande(L) {
    const x = L.voir();
    if (!x) throw new ErreurCobol(tx('fin de source inattendue', 'unexpected end of source'));
    if (x.t === 'mot' && x.v === 'FUNCTION') {
      // fonctions intrinsèques : NUMVAL (texte saisi -> nombre) et TEST-NUMVAL (0 si le texte est un nombre)
      const ligne = L.ligne();
      L.p++;
      const fn = L.mot();
      if (!FONCTIONS.includes(fn)) throw new ErreurCobol(tx(`fonction ${fn} non prise en charge (${FONCTIONS.join(', ')})`, `function ${fn} not supported (${FONCTIONS.join(', ')})`), ligne);
      L.mot('(');
      const arg = this.operande(L);
      L.mot(')');
      return { fn, arg };
    }
    if (x.t === 'lit') { L.p++; return { alpha: x.v }; }
    const f = figuratif(L);
    if (f) return f;
    if (/^[+-]?\d*\.?\d+$/.test(x.v)) { L.p++; return { num: x.v }; }
    return this.reference(L);
  }

  // conditions : relations, noms 88, NOT, AND, OR, parenthèses, sujet implicite (A = 1 OR 2 non géré)
  condition(L) {
    let g = this.condEt(L);
    while (L.optionnel('OR')) g = { ou: [g, this.condEt(L)] };
    return g;
  }
  condEt(L) {
    let g = this.condNon(L);
    while (L.optionnel('AND')) g = { et: [g, this.condNon(L)] };
    return g;
  }
  condNon(L) {
    if (L.optionnel('NOT')) return { non: this.condNon(L) };
    if (L.est('(')) { L.mot(); const c = this.condition(L); L.mot(')'); return c; }
    const x = L.voir();
    if (x.t === 'mot' && this.conditions.has(x.v)) { L.p++; return { nom88: x.v }; }
    const a = this.operande(L);
    L.optionnel('IS');
    let non = L.optionnel('NOT');
    let op;
    if (L.optionnel('=') || L.optionnel('EQUAL')) { L.optionnel('TO'); op = '='; }
    else if (L.optionnel('>') || L.optionnel('GREATER')) { L.optionnel('THAN'); op = '>'; if (L.optionnel('OR')) { L.mot('EQUAL'); L.optionnel('TO'); op = '>='; } }
    else if (L.optionnel('<') || L.optionnel('LESS')) { L.optionnel('THAN'); op = '<'; if (L.optionnel('OR')) { L.mot('EQUAL'); L.optionnel('TO'); op = '<='; } }
    else if (L.optionnel('>=')) op = '>=';
    else if (L.optionnel('<=')) op = '<=';
    else if (L.optionnel('<>')) { op = '='; non = !non; }
    else throw new ErreurCobol(tx(`opérateur de comparaison attendu, trouvé « ${L.voir()?.v} »`, `comparison operator expected, found "${L.voir()?.v}"`), L.ligne());
    const b = this.operande(L);
    const rel = { rel: op, a, b };
    return non ? { non: rel } : rel;
  }

  expression(L) {
    let g = this.terme(L);
    while (L.est('+', '-')) { const op = L.mot(); g = { op, g, d: this.terme(L) }; }
    return g;
  }
  terme(L) {
    let g = this.facteur(L);
    while (L.est('*', '/')) { const op = L.mot(); g = { op, g, d: this.facteur(L) }; }
    return g;
  }
  facteur(L) {
    if (L.optionnel('(')) { const e = this.expression(L); L.mot(')'); return e; }
    if (L.optionnel('-')) return { op: '-', g: { num: '0' }, d: this.facteur(L) };
    return this.operande(L);
  }

  // ---------- Exécution ----------
  // db : base sql.js ouverte. Le programme s'exécute dans une transaction :
  // validée à la fin normale, annulée en cas d'arrêt anormal (comme un abend).
  executer({ fichiers = {}, maxInstructions = 1_000_000, db = null } = {}) {
    const ex = new Execution(this, fichiers, maxInstructions, db);
    if (db) db.run('BEGIN');
    try {
      ex.lancer();
    } catch (e) {
      ex.fermerCurseurs();
      if (db) db.run('ROLLBACK');
      throw e;
    }
    ex.fermerCurseurs();
    if (db) db.run('COMMIT');
    return { sortie: ex.sortie.join(''), fichiers: ex.fs, instructions: ex.compteur, sql: ex.journalSql };
  }
  utiliseSql() {
    return this.curseurs.size > 0 || [...this.paragraphes.values()].some((l) => contientSql(l));
  }
}

function contientSql(liste) {
  return liste.some((i) => i.v === 'SQL' || ['alors', 'sinon', 'corps', 'finFichier', 'sinonFin'].some((k) => i[k] && contientSql(i[k])));
}

const FONCTIONS = ['NUMVAL', 'TEST-NUMVAL'];

// Lecture d'un nombre saisi, comme NUMVAL et TEST-NUMVAL de GnuCOBOL : blancs, un signe (devant ou derrière),
// des chiffres avec au plus un point décimal. erreur : 0 si le texte est valide, sinon la position du premier
// caractère fautif (longueur + 1 s'il n'y a aucun chiffre).
function lireNumval(t) {
  let etat = 'debut', signe = '', chiffres = '', point = false, avecSigne = false;
  for (let i = 0; i < t.length; i++) {
    const c = t[i];
    const ko = { erreur: i + 1, valeur: '0' };
    if (c === ' ') {
      if (etat === 'nombre') etat = 'fin';
      continue;
    }
    if (c === '+' || c === '-') {
      if (avecSigne || etat === 'apres') return ko;
      if (etat === 'debut') { signe = c; avecSigne = true; etat = 'signe'; continue; }
      signe = c; avecSigne = true; etat = 'apres'; continue; // signe derrière le nombre
    }
    if (/[0-9]/.test(c) || c === '.') {
      if (etat === 'fin' || etat === 'apres') return ko;
      if (c === '.') { if (point) return ko; point = true; }
      chiffres += c; etat = 'nombre'; continue;
    }
    return ko;
  }
  if (!/[0-9]/.test(chiffres)) return { erreur: t.length + 1, valeur: '0' };
  const [e, d = ''] = chiffres.split('.');
  return { erreur: 0, valeur: `${signe === '-' ? '-' : ''}${e || '0'}${d ? '.' + d : ''}` };
}

const VERBES = new Set(['MOVE', 'ADD', 'SUBTRACT', 'COMPUTE', 'DISPLAY', 'IF', 'PERFORM', 'OPEN', 'CLOSE', 'READ',
  'WRITE', 'SORT', 'SET', 'INITIALIZE', 'STOP', 'GO', 'GOBACK', 'CONTINUE', 'EXIT', 'NEXT']);
const FINS = new Set(['ELSE', 'END-IF', 'END-PERFORM', 'END-READ', 'NOT', 'AT', 'END']);

class Arret { }
// NEXT SENTENCE : remonte jusqu'à la fin de la phrase.
class PhraseSuivante { }
// GO TO : remonte jusqu'à la boucle qui déroule les paragraphes.
class Saut { constructor(para) { this.para = para; } }

class Execution {
  constructor(pgm, fichiers, max, db) {
    this.pgm = pgm;
    this.db = db;
    this.ouvertsSql = new Map();
    this.journalSql = { executees: 0, erreurs: [] };
    this.fs = { ...fichiers };
    this.sortie = [];
    this.compteur = 0;
    this.max = max;
    this.ouverts = new Map();
    // valeurs initiales
    for (const r of pgm._racines) if (!r.redefinit) this.initialiser(r, true);
  }

  // --- mémoire ---
  lire(el) { return el.zone.octets.slice(el.pos, el.pos + el.longueur).join(''); }
  ecrire(el, texte) { const o = el.zone.octets; for (let i = 0; i < el.longueur; i++) o[el.pos + i] = texte[i] ?? ' '; }

  // référence (avec ses indices éventuels) -> donnée à la bonne occurrence
  res(o) {
    if (!o.indices) return o.el;
    const tables = [];
    for (let e = o.el; e; e = e.parent) if (e.occurs) tables.unshift(e);
    let delta = 0;
    tables.forEach((t, k) => {
      const i = Number(this.calculer(o.indices[k]).aEchelle(0));
      if (i < 1 || i > t.occurs) throw new ErreurCobol(tx(`${o.el.nom} : indice ${i} hors de la table ${t.nom || 'FILLER'} (1 à ${t.occurs})`, `${o.el.nom}: subscript ${i} out of table ${t.nom || 'FILLER'} (1 to ${t.occurs})`));
      delta += (i - 1) * t.longueur;
    });
    return decaler(o.el, delta);
  }

  // toutes les occurrences d'une table ; les REDEFINES ne sont pas initialisés (ils recouvrent)
  initialiser(el, avecValeurs) {
    for (let k = 0; k < (el.occurs || 1); k++) this.initialiserUn(decaler(el, k * el.longueur), avecValeurs);
  }
  initialiserUn(el, avecValeurs) {
    if (el.enfants.length) {
      if (avecValeurs && el.valeur) this.ecrire(el, this.alpha(el.valeur, el.longueur));
      else for (const e of el.enfants) if (!e.redefinit) this.initialiser(e, avecValeurs);
      return;
    }
    if (avecValeurs && el.valeur !== undefined) return this.affecter(el, el.valeur);
    if (el.pic.genre === '9') this.stockerNombre(el, new Dec(0n));
    else if (el.pic.genre === 'E') this.affecter(el, { fig: 'ZERO' });
    else this.ecrire(el, ' '.repeat(el.longueur));
  }

  alpha(v, longueur) {
    if (v.fig) return CARACTERE_FIGURATIF[v.fig].repeat(longueur);
    if (v.all !== undefined) return v.all.repeat(Math.ceil(longueur / v.all.length)).slice(0, longueur);
    return (v.alpha ?? v.num).padEnd(longueur).slice(0, longueur);
  }

  // valeur numérique d'un élément ou d'un littéral
  nombre(o) {
    if (o.num !== undefined) return Dec.de(o.num);
    if (o.fn) {
      const r = lireNumval(this.texte(o.arg));
      return o.fn === 'TEST-NUMVAL' ? new Dec(BigInt(r.erreur)) : r.erreur ? new Dec(0n) : Dec.de(r.valeur);
    }
    if (o.fig === 'ZERO') return new Dec(0n);
    if (o.alpha !== undefined || o.fig || o.all !== undefined) throw new ErreurCobol(tx('valeur non numérique dans un calcul', 'non-numeric value in a computation'));
    const el = this.res(o);
    const p = el.pic;
    if (!p || p.genre !== '9') {
      const t = this.lire(el).trim();
      return Dec.de(t.replace(/[^0-9.-]/g, '') || '0');
    }
    let brut = this.lire(el);
    let negatif = false;
    if (p.signe && p.signeSepare) { negatif = brut[0] === '-'; brut = brut.slice(1); }
    else if (p.signe) {
      const der = brut.at(-1);
      if (der >= 'p' && der <= 'y') { negatif = true; brut = brut.slice(0, -1) + String(der.charCodeAt(0) - 112); }
    }
    const chiffres = [...brut].map((c) => (c >= '0' && c <= '9' ? c : '0')).join('');
    const n = BigInt(chiffres || '0');
    return new Dec(negatif ? -n : n, p.echelle);
  }

  stockerNombre(el, d) {
    const p = el.pic;
    if (p.genre === 'E') return this.ecrire(el, el.blancSiZero && d.n === 0n ? '' : editer(p.masque, d));
    if (p.genre === 'X') { // nombre vers alphanumérique : chiffres entiers sans signe
      const n = d.aEchelle(0);
      return this.ecrire(el, (n < 0n ? -n : n).toString().padEnd(el.longueur).slice(0, el.longueur));
    }
    let n = d.aEchelle(p.echelle);
    const negatif = n < 0n && p.signe;
    if (n < 0n) n = -n;
    let chiffres = n.toString().padStart(p.chiffres, '0').slice(-p.chiffres); // troncature à gauche
    if (p.signe && p.signeSepare) chiffres = (negatif ? '-' : '+') + chiffres;
    else if (negatif) chiffres = chiffres.slice(0, -1) + String.fromCharCode(112 + Number(chiffres.at(-1)));
    this.ecrire(el, chiffres);
  }

  // ROUNDED : arrondi à la décimale de la cible, la moitié s'éloignant de zéro
  arrondir(el, d, oui) {
    const e = el.pic?.echelle ?? 0;
    if (!oui || d.e <= e) return d;
    const k = P10(d.e - e), n = d.n < 0n ? -d.n : d.n;
    const r = (n + k / 2n) / k;
    return new Dec(d.n < 0n ? -r : r, e);
  }

  estNumerique(o) { return o.fn !== undefined || o.num !== undefined || o.fig === 'ZERO' || (o.el && o.el.pic && o.el.pic.genre === '9'); }

  affecter(el, source) {
    if (el.enfants.length || el.pic.genre === 'X') {
      // cible alphanumérique ou groupe : copie de caractères, cadrée à gauche
      let t;
      if (source.el) {
        const s = this.res(source);
        if (!el.enfants.length && s.pic?.genre === '9' && !s.enfants.length) {
          const p = s.pic, d = this.nombre(source);
          let n = d.aEchelle(p.echelle); if (n < 0n) n = -n;
          t = n.toString().padStart(p.chiffres, '0');
        } else t = this.lire(s);
      } else t = this.alpha(source, el.longueur);
      return this.ecrire(el, t.padEnd(el.longueur).slice(0, el.longueur));
    }
    if (source.alpha !== undefined && el.pic.genre !== 'X') {
      if (el.pic.genre === 'E') return this.ecrire(el, source.alpha.padEnd(el.longueur).slice(0, el.longueur));
      return this.stockerNombre(el, Dec.de(source.alpha.trim() || '0'));
    }
    if ((source.fig && source.fig !== 'ZERO') || source.all !== undefined) return this.ecrire(el, this.alpha(source, el.longueur));
    // même PIC numérique des deux côtés : GnuCOBOL copie les octets tels quels (des espaces restent des espaces)
    if (source.el) {
      const s = this.res(source), a = s.pic, b = el.pic;
      if (a && !s.enfants.length && a.genre === '9' && b.genre === '9' && a.chiffres === b.chiffres && a.echelle === b.echelle
        && a.signe === b.signe && a.signeSepare === b.signeSepare) return this.ecrire(el, this.lire(s));
    }
    this.stockerNombre(el, this.nombre(source));
  }

  texte(o) {
    if (o.el) {
      const el = this.res(o);
      // GnuCOBOL affiche les numériques signés avec leur signe et la virgule implicite
      if (!el.enfants.length && el.pic.genre === '9') {
        const p = el.pic;
        if (!p.signe) { // non signé : les octets tels quels (des espaces restent des espaces)
          const brut = this.lire(el);
          return p.echelle ? `${brut.slice(0, -p.echelle)}.${brut.slice(-p.echelle)}` : brut;
        }
        const d = this.nombre(o);
        let n = d.aEchelle(p.echelle); const neg = n < 0n; if (neg) n = -n;
        let s = n.toString().padStart(p.chiffres, '0');
        if (p.echelle) s = s.slice(0, -p.echelle) + '.' + s.slice(-p.echelle);
        return p.signe ? (neg ? '-' : '+') + s : s;
      }
      return this.lire(el);
    }
    if (o.fig) return CARACTERE_FIGURATIF[o.fig];
    // TEST-NUMVAL est une fonction entière : GnuCOBOL l'affiche sur 9 chiffres
    if (o.fn === 'TEST-NUMVAL') return this.nombre(o).n.toString().padStart(9, '0');
    if (o.fn) { const d = this.nombre(o), neg = d.n < 0n, a = (neg ? -d.n : d.n).toString().padStart(d.e + 1, '0'); return (neg ? '-' : '') + (d.e ? `${a.slice(0, -d.e)}.${a.slice(-d.e)}` : a); }
    return o.all ?? o.alpha ?? o.num;
  }

  comparer(a, b) {
    if (this.estNumerique(a) && this.estNumerique(b)) return this.nombre(a).cmp(this.nombre(b));
    // une constante figurative prend la longueur de l'autre opérande
    const fig = (o) => o.fig !== undefined || o.all !== undefined;
    let x = fig(a) && !fig(b) ? null : this.texte(a), y = fig(b) && !fig(a) ? null : this.texte(b);
    if (x === null) x = this.alpha(a, y.length);
    if (y === null) y = this.alpha(b, x.length);
    const n = Math.max(x.length, y.length);
    x = x.padEnd(n); y = y.padEnd(n);
    return x < y ? -1 : x > y ? 1 : 0;
  }

  evaluer(c) {
    if (c.ou) return this.evaluer(c.ou[0]) || this.evaluer(c.ou[1]);
    if (c.et) return this.evaluer(c.et[0]) && this.evaluer(c.et[1]);
    if (c.non) return !this.evaluer(c.non);
    if (c.nom88) {
      const { parent, valeurs } = this.pgm.conditions.get(c.nom88);
      return valeurs.some((v) => this.comparer({ el: parent }, v) === 0);
    }
    const r = this.comparer(c.a, c.b);
    return { '=': r === 0, '>': r > 0, '<': r < 0, '>=': r >= 0, '<=': r <= 0 }[c.rel];
  }

  calculer(e) {
    if (e.op) {
      const g = this.calculer(e.g), d = this.calculer(e.d);
      return e.op === '+' ? g.plus(d) : e.op === '-' ? g.moins(d) : e.op === '*' ? g.fois(d) : g.divise(d);
    }
    return this.nombre(e);
  }

  // --- fichiers ---
  enregistrement(f) {
    if (!f.enreg) throw new ErreurCobol(tx(`fichier ${f.nom} sans enregistrement`, `file ${f.nom} has no record`));
    return f.enreg;
  }
  lignesDe(f) {
    const contenu = this.fs[f.assign];
    if (contenu === undefined) throw new ErreurCobol(tx(`fichier ${f.assign} introuvable (OPEN INPUT ${f.nom})`, `file ${f.assign} not found (OPEN INPUT ${f.nom})`));
    const lignes = contenu.split(/\r?\n/);
    if (lignes.at(-1) === '') lignes.pop();
    return lignes;
  }

  fermer(f) {
    const o = this.ouverts.get(f);
    if (o?.ouverte) this.fs[f.assign] += '\n';
    this.ouverts.delete(f);
  }

  lancer() {
    try {
      const debut = this.pgm.ordre[0];
      if (!debut) return;
      this.executerDepuis(0);
    } catch (e) {
      if (e instanceof Saut) throw new ErreurCobol(tx(`GO TO ${e.para} : sortie d un PERFORM non prise en charge`, `GO TO ${e.para}: leaving a PERFORM is not supported`));
      if (e instanceof PhraseSuivante) throw new ErreurCobol(tx('NEXT SENTENCE hors d\'une phrase', 'NEXT SENTENCE outside a sentence'));
      if (!(e instanceof Arret)) throw e;
    }
    // STOP RUN ferme les fichiers restés ouverts
    for (const f of [...this.ouverts.keys()]) this.fermer(f);
  }

  // Déroule les paragraphes à partir de index, jusqu'à jusquA inclus (PERFORM THRU) ou jusqu'à la fin.
  // Un GO TO vers un paragraphe de la plage continue depuis lui ; hors de la plage, il remonte.
  executerDepuis(index, jusquA = null) {
    const fin = jusquA === null ? this.pgm.ordre.length - 1 : this.pgm.ordre.indexOf(jusquA);
    for (let i = index; i <= fin && i < this.pgm.ordre.length; i++) {
      try {
        this.bloc(this.pgm.paragraphes.get(this.pgm.ordre[i]));
      } catch (e) {
        if (!(e instanceof Saut)) throw e;
        const j = this.pgm.ordre.indexOf(e.para);
        if (j < 0) throw new ErreurCobol(tx(`GO TO ${e.para} : paragraphe inconnu`, `GO TO ${e.para}: unknown paragraph`));
        if (j < index || j > fin) throw e;
        i = j - 1;
      }
    }
  }

  performer(nom, jusquA) {
    const i = this.pgm.ordre.indexOf(nom);
    if (i < 0) throw new ErreurCobol(tx(`paragraphe inconnu : ${nom}`, `unknown paragraph: ${nom}`));
    this.executerDepuis(i, jusquA || nom);
  }

  bloc(liste) { for (const ins of liste) this.instruction(ins); }

  instruction(ins) {
    if (++this.compteur > this.max) throw new ErreurCobol(tx(`plus de ${this.max} instructions : boucle infinie ?`, `more than ${this.max} statements: infinite loop?`), ins.ligne);
    try {
      this.faire(ins);
    } catch (e) {
      if (e instanceof ErreurCobol && !e.ligne) throw new ErreurCobol(e.message, ins.ligne);
      throw e;
    }
  }

  faire(ins) {
    switch (ins.v) {
      case 'MOVE': for (const c of ins.cibles) this.affecter(this.res(c), ins.source); return;
      case 'ADD': case 'SUBTRACT': {
        let total = new Dec(0n);
        for (const s of ins.sources) total = total.plus(this.nombre(s));
        if (ins.base) {
          const v = this.nombre(ins.base[0]);
          for (const c of ins.cibles) { const el = this.res(c); this.stockerNombre(el, this.arrondir(el, ins.v === 'ADD' ? v.plus(total) : v.moins(total), ins.arrondi)); }
          return;
        }
        for (const c of ins.cibles) {
          const el = this.res(c), v = this.nombre({ el });
          this.stockerNombre(el, this.arrondir(el, ins.v === 'ADD' ? v.plus(total) : v.moins(total), ins.arrondi));
        }
        return;
      }
      case 'COMPUTE': { const el = this.res(ins.cible); this.stockerNombre(el, this.arrondir(el, this.calculer(ins.expr), ins.arrondi)); return; }
      case 'PHRASE':
        try { this.bloc(ins.corps); } catch (e) { if (!(e instanceof PhraseSuivante)) throw e; }
        return;
      case 'NEXT': throw new PhraseSuivante();
      case 'DISPLAY': this.sortie.push(ins.elems.map((o) => this.texte(o)).join('') + '\n'); return;
      case 'IF': this.bloc(this.evaluer(ins.cond) ? ins.alors : ins.sinon); return;
      case 'PERFORM':
        if (ins.corps) { while (!this.evaluer(ins.jusque)) this.bloc(ins.corps); return; }
        if (ins.jusque) { while (!this.evaluer(ins.jusque)) this.performer(ins.para, ins.jusquA); return; }
        this.performer(ins.para, ins.jusquA); return;
      case 'OPEN':
        for (const { mode, f } of ins.ouvertures) {
          if (mode === 'INPUT') this.ouverts.set(f, { mode, lignes: this.lignesDe(f), pos: 0 });
          else { if (mode === 'OUTPUT') this.fs[f.assign] = ''; this.ouverts.set(f, { mode }); }
        }
        return;
      case 'CLOSE': for (const f of ins.fichiers) this.fermer(f); return;
      case 'READ': {
        const o = this.ouverts.get(ins.f);
        if (!o || o.mode !== 'INPUT') throw new ErreurCobol(tx(`READ ${ins.f.nom} : fichier non ouvert en lecture`, `READ ${ins.f.nom}: file not open for input`));
        if (o.pos >= o.lignes.length) { this.bloc(ins.finFichier); return; }
        const enr = this.enregistrement(ins.f);
        this.ecrire(enr, o.lignes[o.pos++].padEnd(enr.longueur).slice(0, enr.longueur));
        if (ins.into) this.affecter(this.res(ins.into), { el: enr });
        this.bloc(ins.sinonFin);
        return;
      }
      case 'WRITE': {
        const enr = this.res(ins.enreg);
        const f = [...this.pgm.fichiers.values()].find((x) => x.enreg === enr || (enr.zone === x.enreg?.zone));
        if (!f) throw new ErreurCobol(tx(`${enr.nom} n'est l'enregistrement d'aucun fichier`, `${enr.nom} is not the record of any file`));
        const o = this.ouverts.get(f);
        if (!o || o.mode === 'INPUT') throw new ErreurCobol(tx(`WRITE ${enr.nom} : fichier non ouvert en écriture`, `WRITE ${enr.nom}: file not open for output`));
        if (ins.depuis) this.affecter(enr, ins.depuis);
        // octets de GnuCOBOL en LINE SEQUENTIAL : AFTER n = n sauts puis la ligne (0 = retour chariot),
        // AFTER PAGE = saut de page puis la ligne ; BEFORE : la ligne puis les sauts ; sans ADVANCING : ligne + saut.
        // Après un AFTER, la ligne reste ouverte : la fermeture du fichier ajoute le dernier saut.
        let t = this.lire(enr).replace(/ +$/, '');
        if (ins.avance) {
          let sauts = '\f';
          if (!ins.page) {
            const n = Number(this.nombre(ins.lignes).aEchelle(0));
            if (n < 0 || n > 99) throw new ErreurCobol(tx(`WRITE ${ins.avance} ADVANCING ${n} : de 0 à 99 lignes`, `WRITE ${ins.avance} ADVANCING ${n}: from 0 to 99 lines`));
            sauts = n === 0 ? '\r' : '\n'.repeat(n);
          }
          t = ins.avance === 'AFTER' ? sauts + t : t + sauts;
        } else t += '\n';
        o.ouverte = ins.avance === 'AFTER';
        this.fs[f.assign] += t;
        return;
      }
      case 'SORT': {
        const enr = this.enregistrement(ins.f);
        const lignes = this.lignesDe(ins.entree).map((l) => l.padEnd(enr.longueur).slice(0, enr.longueur));
        const cles = lignes.map((l) => {
          this.ecrire(enr, l);
          return ins.cles.map((k) => (k.ref.el.pic?.genre === '9' ? this.nombre(k.ref) : this.lire(k.ref.el)));
        });
        const idx = lignes.map((_, i) => i);
        idx.sort((a, b) => {
          for (let k = 0; k < ins.cles.length; k++) {
            const x = cles[a][k], y = cles[b][k];
            const r = x instanceof Dec ? x.cmp(y) : x < y ? -1 : x > y ? 1 : 0;
            if (r) return ins.cles[k].sens === 'DESCENDING' ? -r : r;
          }
          return a - b;
        });
        const sortieEnr = this.enregistrement(ins.sortie);
        this.fs[ins.sortie.assign] = idx.map((i) => lignes[i].padEnd(sortieEnr.longueur).slice(0, sortieEnr.longueur).replace(/ +$/, '') + '\n').join('');
        return;
      }
      case 'SET': {
        if (ins.cond) {
          const { parent, valeurs } = this.pgm.conditions.get(ins.cond);
          this.affecter(parent, valeurs[0]);
          return;
        }
        for (const c of ins.cibles) {
          const el = this.res(c);
          if (ins.mode === 'TO') this.affecter(el, ins.source);
          else {
            const v = this.nombre({ el }), pas = this.nombre(ins.source);
            this.stockerNombre(el, ins.mode === 'UP' ? v.plus(pas) : v.moins(pas));
          }
        }
        return;
      }
      case 'INITIALIZE':
        for (const c of ins.cibles) {
          const el = this.res(c);
          if (c.indices) this.initialiserUn(el, false); else this.initialiser(el, false);
        }
        return;
      case 'STOP': case 'GOBACK': throw new Arret();
      case 'GO': {
        if (!ins.selon) throw new Saut(ins.para);
        const n = Number(this.nombre(ins.selon).aEchelle(0));
        if (n >= 1 && n <= ins.paras.length) throw new Saut(ins.paras[n - 1]);
        return; // hors plage : on continue en séquence
      }
      case 'CONTINUE': case 'EXIT': return;
      case 'SQL': return this.sql(ins);
    }
  }

  // ----- EXEC SQL -----
  valeurSql(el) {
    if (el.pic.genre === '9') {
      const d = this.nombre({ el });
      const neg = d.n < 0n, a = (neg ? -d.n : d.n).toString().padStart(d.e + 1, '0');
      const t = d.e ? `${a.slice(0, -d.e)}.${a.slice(-d.e)}` : a;
      return Number((neg ? '-' : '') + t);
    }
    return this.lire(el).replace(/ +$/, '');
  }
  versZone(el, v) {
    if (v === null || v === undefined) return this.initialiser(el, false);
    if (el.pic.genre === '9' || el.pic.genre === 'E') {
      const n = typeof v === 'number' ? v.toFixed(el.pic.echelle || 0) : String(v).trim();
      return this.stockerNombre(el, Dec.de(/^[+-]?\d*\.?\d*$/.test(n) ? n : '0'));
    }
    this.ecrire(el, String(v).padEnd(el.longueur).slice(0, el.longueur));
  }
  sqlcode(n) {
    const el = this.pgm.donnees.get('SQLCODE');
    if (el) this.stockerNombre(el, new Dec(BigInt(n)));
  }
  sql(ins) {
    if (ins.genre === 'RIEN') return;
    if (!this.db) throw new ErreurCobol(tx('EXEC SQL : aucune base de données n\'est disponible', 'EXEC SQL: no database is available'));
    this.journalSql.executees++;
    try {
      this.sqlcode(this.sqlFaire(ins));
    } catch (e) {
      if (e instanceof ErreurCobol) throw e;
      const msg = String(e.message || e);
      const code = /UNIQUE|PRIMARY KEY/i.test(msg) ? -803 : /no such table|no such column/i.test(msg) ? -204 : /NOT NULL/i.test(msg) ? -407 : -1;
      this.journalSql.erreurs.push({ ligne: ins.ligne, code, message: msg });
      this.sqlcode(code);
    }
  }
  sqlFaire(ins) {
    const lier = (entrees) => entrees.map((el) => this.valeurSql(el));
    const remplir = (into, ligne) => {
      if (ligne.length !== into.length) throw new ErreurCobol(tx(`EXEC SQL : ${ligne.length} colonne(s) lue(s) pour ${into.length} zone(s) INTO`, `EXEC SQL: ${ligne.length} column(s) read for ${into.length} INTO host variable(s)`), ins.ligne);
      into.forEach((el, i) => this.versZone(el, ligne[i]));
    };
    switch (ins.genre) {
      case 'SELECT1': {
        const st = this.db.prepare(ins.sql);
        try {
          st.bind(lier(ins.entrees));
          if (!st.step()) return 100;
          const ligne = st.get();
          if (st.step()) return -811; // plus d'une ligne : il faut un curseur
          remplir(ins.into, ligne);
          return 0;
        } finally { st.free(); }
      }
      case 'MAJ':
        this.db.run(ins.sql, lier(ins.entrees));
        return this.db.getRowsModified() ? 0 : 100;
      case 'OPEN': {
        const c = this.pgm.curseurs.get(ins.curseur);
        if (!c) throw new ErreurCobol(tx(`curseur ${ins.curseur} non déclaré`, `cursor ${ins.curseur} not declared`), ins.ligne);
        if (this.ouvertsSql.has(ins.curseur)) return -502;
        const st = this.db.prepare(c.sql);
        st.bind(lier(c.entrees));
        this.ouvertsSql.set(ins.curseur, st);
        return 0;
      }
      case 'FETCH': {
        const st = this.ouvertsSql.get(ins.curseur);
        if (!st) return -501;
        if (!st.step()) return 100;
        remplir(ins.into, st.get());
        return 0;
      }
      case 'CLOSE': {
        const st = this.ouvertsSql.get(ins.curseur);
        if (!st) return -501;
        st.free(); this.ouvertsSql.delete(ins.curseur);
        return 0;
      }
      case 'COMMIT': this.fermerCurseurs(); this.db.run('COMMIT'); this.db.run('BEGIN'); return 0;
      case 'ROLLBACK': this.fermerCurseurs(); this.db.run('ROLLBACK'); this.db.run('BEGIN'); return 0;
    }
    return 0;
  }
  fermerCurseurs() {
    for (const st of this.ouvertsSql.values()) st.free();
    this.ouvertsSql.clear();
  }
}

// Édition numérique : Z, 9, point, virgule, B, /, signe moins fixe ou flottant.
export function editer(masque, d) {
  const chars = [...masque];
  const flottant = chars.filter((c) => c === '-').length > 1;
  const premierMoins = chars.indexOf('-');
  const estChiffre = (c, i) => c === '9' || c === 'Z' || (flottant && c === '-' && i !== premierMoins);
  const iPoint = chars.indexOf('.');
  const echelle = iPoint < 0 ? 0 : chars.slice(iPoint).filter((c, k) => estChiffre(c, iPoint + k)).length;
  const total = chars.filter(estChiffre).length;
  let n = d.aEchelle(echelle);
  const negatif = n < 0n; if (negatif) n = -n;
  const chiffres = n.toString().padStart(total, '0').slice(-total);
  const out = [];
  let k = 0, significatif = false, premierSignif = -1, signeFixe = -1;
  chars.forEach((c, i) => {
    if (estChiffre(c, i)) {
      const ch = chiffres[k++];
      if (!significatif && (c === '9' || ch !== '0')) { significatif = true; premierSignif = i; }
      out.push(significatif ? ch : ' ');
    } else if ('.,B/'.includes(c)) {
      out.push(significatif ? (c === 'B' ? ' ' : c) : ' ');
    } else if (c === '-') {
      out.push(' ');
      if (!flottant) signeFixe = i;
    } else out.push(c);
  });
  if (negatif) {
    if (signeFixe >= 0) out[signeFixe] = '-';
    else if (flottant) out[premierSignif > 0 ? premierSignif - 1 : premierMoins] = '-';
  }
  return out.join('');
}
