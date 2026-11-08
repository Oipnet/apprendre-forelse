// Interpréteur d'un sous-ensemble de COBOL (format fixe), suffisant pour exécuter
// le code produit par le générateur. Il lit le source : il n'exécute pas le modèle.
//
// Pris en charge : FILE-CONTROL (SELECT ... ASSIGN ... LINE SEQUENTIAL), FD/SD, niveaux 01-49 et 88,
// PIC X / 9 / S9 / V / éditions (Z 9 . , - B /), VALUE, SIGN LEADING SEPARATE,
// MOVE, ADD, SUBTRACT, COMPUTE, IF/ELSE/END-IF, PERFORM [UNTIL], PERFORM UNTIL ... END-PERFORM,
// OPEN, CLOSE, READ AT END / NOT AT END, WRITE, SORT USING GIVING, DISPLAY, SET ... TO TRUE,
// INITIALIZE, CONTINUE, STOP RUN, GOBACK, et EXEC SQL ... END-EXEC sur une base SQLite (sql.js) :
// SELECT ... INTO, INSERT, UPDATE, DELETE, DECLARE CURSOR, OPEN, FETCH, CLOSE, COMMIT, ROLLBACK.

export class ErreurCobol extends Error {
  constructor(message, ligne) {
    super(ligne ? `ligne ${ligne} : ${message}` : message);
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
          if (j >= code.length) throw new ErreurCobol('littéral non terminé', ligne);
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
        while (j < code.length && !' ()'.includes(code[j]) && !(code[j] === '.' && (j + 1 >= code.length || code[j + 1] === ' '))) j++;
        if (j > i) jetons.push({ t: 'mot', v: code.slice(i, j).toUpperCase(), ligne });
      }
      if (code[j] === '.' && (j + 1 >= code.length || code[j + 1] === ' ')) { jetons.push({ t: 'point', v: '.', ligne }); j++; }
      i = j;
    }
  });
  if (sql) throw new ErreurCobol('EXEC SQL sans END-EXEC', sql.ligne);
  return jetons;
}

class Lecteur {
  constructor(jetons) { this.j = jetons; this.p = 0; }
  voir(k = 0) { return this.j[this.p + k]; }
  est(...mots) { const x = this.voir(); return x && x.t === 'mot' && mots.includes(x.v); }
  estPoint() { return this.voir()?.t === 'point'; }
  suivant() { const x = this.j[this.p++]; if (!x) throw new ErreurCobol('fin de source inattendue'); return x; }
  mot(attendu) {
    const x = this.suivant();
    if (x.t !== 'mot' || (attendu && x.v !== attendu)) throw new ErreurCobol(`attendu ${attendu || 'un mot'}, trouvé « ${x.v} »`, x.ligne);
    return x.v;
  }
  optionnel(...mots) { if (this.est(...mots)) { this.p++; return true; } return false; }
  point() { const x = this.suivant(); if (x.t !== 'point') throw new ErreurCobol(`point attendu, trouvé « ${x.v} »`, x.ligne); }
  fin() { return this.p >= this.j.length; }
  ligne() { return this.voir()?.ligne; }
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
  throw new ErreurCobol(`clause PIC non prise en charge : ${pic}`);
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
    if (b.n === 0n) throw new ErreurCobol('division par zéro');
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
    this.conditions = new Map();  // nom 88 -> { parent, valeurs }
    this.curseurs = new Map();    // nom -> { sql, entrees }
    this.analyser(tokeniser(source));
  }

  analyser(jetons) {
    const L = new Lecteur(jetons);
    const division = (nom) => { while (!L.fin() && !(L.est(nom) && L.voir(1)?.v === 'DIVISION')) L.p++; };
    division('ENVIRONMENT');
    while (!L.fin() && !L.est('DATA', 'SELECT')) L.p++;
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
        if (!f) throw new ErreurCobol(`fichier ${nom} non déclaré dans FILE-CONTROL`, L.ligne());
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
    if (!Number.isInteger(niveau)) throw new ErreurCobol(`numéro de niveau attendu`, ligne);
    const nom = L.est('FILLER') || L.voir()?.t !== 'mot' ? (L.optionnel('FILLER'), null) : L.mot();
    const el = { niveau, nom, ligne, enfants: [], pic: null, valeur: undefined, signeSepare: false };
    if (niveau === 88) {
      L.optionnel('VALUE', 'VALUES'); L.optionnel('IS', 'ARE');
      const valeurs = [];
      while (!L.estPoint()) valeurs.push(this.litteralDonnee(L));
      L.point();
      const parent = this._dernierElementaire;
      if (!parent) throw new ErreurCobol('niveau 88 sans donnée', ligne);
      this.conditions.set(nom, { parent, valeurs });
      return;
    }
    while (!L.estPoint()) {
      if (L.optionnel('PIC', 'PICTURE')) { L.optionnel('IS'); el.picTexte = L.suivant().v; }
      else if (L.optionnel('VALUE')) { L.optionnel('IS'); el.valeur = this.litteralDonnee(L); }
      else if (L.optionnel('SIGN')) { L.optionnel('IS'); L.optionnel('LEADING'); if (L.optionnel('SEPARATE')) L.optionnel('CHARACTER'); el.signeSepare = true; }
      else if (L.optionnel('USAGE')) { L.optionnel('IS'); if (!L.optionnel('DISPLAY')) throw new ErreurCobol('seul USAGE DISPLAY est pris en charge', ligne); }
      else if (L.optionnel('DISPLAY')) { /* usage par défaut */ }
      else throw new ErreurCobol(`clause non prise en charge : ${L.voir().v}`, L.ligne());
    }
    L.point();
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
      if (!p.length) throw new ErreurCobol(`niveau ${niveau} sans groupe parent`, ligne);
      el.parent = p.at(-1);
      el.parent.enfants.push(el);
      p.push(el);
    }
    if (el.nom) {
      if (this.donnees.has(el.nom)) throw new ErreurCobol(`donnée ${el.nom} déclarée deux fois`, ligne);
      this.donnees.set(el.nom, el);
    }
    this._dernierElementaire = el;
  }

  litteralDonnee(L) {
    const x = L.suivant();
    if (x.t === 'lit') return { alpha: x.v };
    if (['ZERO', 'ZEROS', 'ZEROES'].includes(x.v)) return { fig: 'ZERO' };
    if (['SPACE', 'SPACES'].includes(x.v)) return { fig: 'SPACE' };
    if (/^[+-]?\d*\.?\d+$/.test(x.v)) return { num: x.v };
    throw new ErreurCobol(`valeur non prise en charge : ${x.v}`, x.ligne);
  }

  allouer() {
    // chaque 01 a sa zone mémoire ; les enregistrements d'un même FD partagent la leur
    const taille = (el) => {
      if (el.pic) { if (el.enfants.length) throw new ErreurCobol(`groupe ${el.nom} avec PIC`, el.ligne); return (el.longueur = el.pic.longueur); }
      if (!el.enfants.length) throw new ErreurCobol(`donnée ${el.nom || 'FILLER'} sans PIC`, el.ligne);
      return (el.longueur = el.enfants.reduce((s, e) => s + taille(e), 0));
    };
    const placer = (el, zone, pos) => {
      el.zone = zone; el.pos = pos;
      let p = pos;
      for (const e of el.enfants) { placer(e, zone, p); p += e.longueur; }
    };
    for (const r of this._racines) {
      taille(r);
      const zone = r.fichier && r.fichier.enreg !== r ? r.fichier.enreg.zone : { octets: [] };
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
      courant.push(...this.instructions(L, []));
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
        while (L.voir()?.t === 'mot' && !VERBES.has(L.voir().v) && !FINS.has(L.voir().v) && L.voir().v !== 'ROUNDED') ins.cibles.push(this.reference(L));
        return ins;
      }
      case 'COMPUTE': {
        ins.cible = this.reference(L);
        if (!(L.optionnel('=') || L.optionnel('EQUAL'))) throw new ErreurCobol('= attendu', ligne);
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
        ins.cond = L.mot();
        if (!this.conditions.has(ins.cond)) throw new ErreurCobol(`${ins.cond} n'est pas un nom de condition`, ligne);
        L.mot('TO'); L.mot('TRUE');
        return ins;
      }
      case 'INITIALIZE': {
        ins.cibles = [];
        while (L.voir()?.t === 'mot' && this.donnees.has(L.voir().v)) ins.cibles.push(this.reference(L));
        return ins;
      }
      case 'STOP': L.mot('RUN'); return ins;
      case 'GOBACK': case 'CONTINUE': case 'EXIT': return ins;
      default:
        throw new ErreurCobol(`instruction non prise en charge : ${v}`, ligne);
    }
  }

  fichier(L) {
    const ligne = L.ligne();
    const nom = L.mot();
    const f = this.fichiers.get(nom);
    if (!f) throw new ErreurCobol(`fichier inconnu : ${nom}`, ligne);
    return f;
  }

  // ----- EXEC SQL -----
  // :ZONE -> ? ; renvoie le texte SQL et les données à lier, dans l'ordre
  hotes(texte, ligne) {
    const entrees = [];
    const sql = texte.replace(/:([A-Z0-9][A-Z0-9-]*)/gi, (_, nom) => {
      const el = this.donnees.get(nom.toUpperCase());
      if (!el) throw new ErreurCobol(`EXEC SQL : zone :${nom} inconnue`, ligne);
      if (el.enfants.length) throw new ErreurCobol(`EXEC SQL : :${nom} est un groupe, donnez une zone élémentaire`, ligne);
      entrees.push(el);
      return '?';
    });
    return { sql, entrees };
  }
  listeHotes(texte, ligne) {
    return texte.split(',').map((x) => {
      const m = /^\s*:([A-Z0-9][A-Z0-9-]*)\s*$/i.exec(x);
      if (!m) throw new ErreurCobol(`EXEC SQL : zone :NOM attendue dans INTO, trouvé « ${x.trim()} »`, ligne);
      return this.hotes(x, ligne).entrees[0];
    });
  }
  sqlDonnees(x) {
    const m = /^DECLARE\s+([A-Z0-9-]+)\s+CURSOR\s+FOR\s+(SELECT\b.*)$/is.exec(x.v);
    if (m) { this.curseurs.set(m[1].toUpperCase(), { ...this.hotes(m[2], x.ligne), ligne: x.ligne }); return; }
    if (/^INCLUDE\b/i.test(x.v)) return;
    throw new ErreurCobol(`EXEC SQL non pris en charge dans les données : ${x.v.slice(0, 40)}`, x.ligne);
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
    else throw new ErreurCobol(`EXEC SQL non pris en charge : ${t.slice(0, 40)}`, ligne);
    return ins;
  }

  reference(L) {
    const ligne = L.ligne();
    const nom = L.mot();
    const el = this.donnees.get(nom);
    if (!el) throw new ErreurCobol(`donnée inconnue : ${nom}`, ligne);
    return { el };
  }

  operande(L) {
    const x = L.voir();
    if (x.t === 'lit') { L.p++; return { alpha: x.v }; }
    if (['ZERO', 'ZEROS', 'ZEROES'].includes(x.v)) { L.p++; return { fig: 'ZERO' }; }
    if (['SPACE', 'SPACES'].includes(x.v)) { L.p++; return { fig: 'SPACE' }; }
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
    else throw new ErreurCobol(`opérateur de comparaison attendu, trouvé « ${L.voir()?.v} »`, L.ligne());
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

const VERBES = new Set(['MOVE', 'ADD', 'SUBTRACT', 'COMPUTE', 'DISPLAY', 'IF', 'PERFORM', 'OPEN', 'CLOSE', 'READ',
  'WRITE', 'SORT', 'SET', 'INITIALIZE', 'STOP', 'GOBACK', 'CONTINUE', 'EXIT']);
const FINS = new Set(['ELSE', 'END-IF', 'END-PERFORM', 'END-READ', 'NOT', 'AT', 'END']);

class Arret { }

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
    for (const el of pgm.donnees.values()) if (!el.parent) this.initialiser(el, true);
    for (const r of pgm._racines) if (!r.nom) this.initialiser(r, true);
  }

  // --- mémoire ---
  lire(el) { return el.zone.octets.slice(el.pos, el.pos + el.longueur).join(''); }
  ecrire(el, texte) { const o = el.zone.octets; for (let i = 0; i < el.longueur; i++) o[el.pos + i] = texte[i] ?? ' '; }

  initialiser(el, avecValeurs) {
    if (el.enfants.length) {
      if (avecValeurs && el.valeur) this.ecrire(el, this.alpha(el.valeur, el.longueur));
      else for (const e of el.enfants) this.initialiser(e, avecValeurs);
      return;
    }
    if (avecValeurs && el.valeur !== undefined) return this.affecter(el, el.valeur);
    if (el.pic.genre === '9') this.stockerNombre(el, new Dec(0n));
    else if (el.pic.genre === 'E') this.affecter(el, { fig: 'ZERO' });
    else this.ecrire(el, ' '.repeat(el.longueur));
  }

  alpha(v, longueur) {
    if (v.fig === 'SPACE') return ' '.repeat(longueur);
    if (v.fig === 'ZERO') return '0'.repeat(longueur);
    return (v.alpha ?? v.num).padEnd(longueur).slice(0, longueur);
  }

  // valeur numérique d'un élément ou d'un littéral
  nombre(o) {
    if (o.num !== undefined) return Dec.de(o.num);
    if (o.fig === 'ZERO') return new Dec(0n);
    if (o.alpha !== undefined || o.fig) throw new ErreurCobol('valeur non numérique dans un calcul');
    const el = o.el;
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
    if (p.genre === 'E') return this.ecrire(el, editer(p.masque, d));
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

  estNumerique(o) { return o.num !== undefined || o.fig === 'ZERO' || (o.el && o.el.pic && o.el.pic.genre === '9'); }

  affecter(el, source) {
    if (el.enfants.length || el.pic.genre === 'X') {
      // cible alphanumérique ou groupe : copie de caractères, cadrée à gauche
      let t;
      if (source.el) {
        const s = source.el;
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
    if (source.fig === 'SPACE') return this.ecrire(el, ' '.repeat(el.longueur));
    this.stockerNombre(el, this.nombre(source));
  }

  texte(o) {
    if (o.el) {
      const el = o.el;
      // GnuCOBOL affiche les numériques signés avec leur signe et la virgule implicite
      if (!el.enfants.length && el.pic.genre === '9') {
        const p = el.pic, d = this.nombre(o);
        let n = d.aEchelle(p.echelle); const neg = n < 0n; if (neg) n = -n;
        let s = n.toString().padStart(p.chiffres, '0');
        if (p.echelle) s = s.slice(0, -p.echelle) + '.' + s.slice(-p.echelle);
        return p.signe ? (neg ? '-' : '+') + s : s;
      }
      return this.lire(el);
    }
    if (o.fig === 'SPACE') return ' ';
    if (o.fig === 'ZERO') return '0';
    return o.alpha ?? o.num;
  }

  comparer(a, b) {
    if (this.estNumerique(a) && this.estNumerique(b)) return this.nombre(a).cmp(this.nombre(b));
    let x = this.texte(a), y = this.texte(b);
    if (a.fig === 'SPACE') x = ' '.repeat(y.length);
    if (b.fig === 'SPACE') y = ' '.repeat(x.length);
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
    if (!f.enreg) throw new ErreurCobol(`fichier ${f.nom} sans enregistrement`);
    return f.enreg;
  }
  lignesDe(f) {
    const contenu = this.fs[f.assign];
    if (contenu === undefined) throw new ErreurCobol(`fichier ${f.assign} introuvable (OPEN INPUT ${f.nom})`);
    const lignes = contenu.split(/\r?\n/);
    if (lignes.at(-1) === '') lignes.pop();
    return lignes;
  }

  lancer() {
    try {
      const debut = this.pgm.ordre[0];
      if (!debut) return;
      this.executerDepuis(0);
    } catch (e) {
      if (!(e instanceof Arret)) throw e;
    }
  }

  executerDepuis(index, jusquA = null) {
    for (let i = index; i < this.pgm.ordre.length; i++) {
      const nom = this.pgm.ordre[i];
      this.bloc(this.pgm.paragraphes.get(nom));
      if (jusquA !== null && nom === jusquA) return;
    }
  }

  performer(nom, jusquA) {
    const i = this.pgm.ordre.indexOf(nom);
    if (i < 0) throw new ErreurCobol(`paragraphe inconnu : ${nom}`);
    if (jusquA) return this.executerDepuis(i, jusquA);
    this.bloc(this.pgm.paragraphes.get(nom));
  }

  bloc(liste) { for (const ins of liste) this.instruction(ins); }

  instruction(ins) {
    if (++this.compteur > this.max) throw new ErreurCobol(`plus de ${this.max} instructions : boucle infinie ?`, ins.ligne);
    try {
      this.faire(ins);
    } catch (e) {
      if (e instanceof ErreurCobol && !e.ligne) throw new ErreurCobol(e.message, ins.ligne);
      throw e;
    }
  }

  faire(ins) {
    switch (ins.v) {
      case 'MOVE': for (const c of ins.cibles) this.affecter(c.el, ins.source); return;
      case 'ADD': case 'SUBTRACT': {
        let total = new Dec(0n);
        for (const s of ins.sources) total = total.plus(this.nombre(s));
        for (const c of ins.cibles) {
          const v = this.nombre(c);
          this.stockerNombre(c.el, ins.v === 'ADD' ? v.plus(total) : v.moins(total));
        }
        return;
      }
      case 'COMPUTE': this.stockerNombre(ins.cible.el, this.calculer(ins.expr)); return;
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
      case 'CLOSE': for (const f of ins.fichiers) this.ouverts.delete(f); return;
      case 'READ': {
        const o = this.ouverts.get(ins.f);
        if (!o || o.mode !== 'INPUT') throw new ErreurCobol(`READ ${ins.f.nom} : fichier non ouvert en lecture`);
        if (o.pos >= o.lignes.length) { this.bloc(ins.finFichier); return; }
        const enr = this.enregistrement(ins.f);
        this.ecrire(enr, o.lignes[o.pos++].padEnd(enr.longueur).slice(0, enr.longueur));
        if (ins.into) this.affecter(ins.into.el, { el: enr });
        this.bloc(ins.sinonFin);
        return;
      }
      case 'WRITE': {
        const enr = ins.enreg.el;
        const f = [...this.pgm.fichiers.values()].find((x) => x.enreg === enr || (enr.zone === x.enreg?.zone));
        if (!f) throw new ErreurCobol(`${enr.nom} n'est l'enregistrement d'aucun fichier`);
        const o = this.ouverts.get(f);
        if (!o || o.mode === 'INPUT') throw new ErreurCobol(`WRITE ${enr.nom} : fichier non ouvert en écriture`);
        if (ins.depuis) this.affecter(enr, ins.depuis);
        this.fs[f.assign] += this.lire(enr).replace(/ +$/, '') + '\n';
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
        const { parent, valeurs } = this.pgm.conditions.get(ins.cond);
        this.affecter(parent, valeurs[0]);
        return;
      }
      case 'INITIALIZE': for (const c of ins.cibles) this.initialiser(c.el, false); return;
      case 'STOP': case 'GOBACK': throw new Arret();
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
    if (!this.db) throw new ErreurCobol('EXEC SQL : aucune base de données n\'est disponible');
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
      if (ligne.length !== into.length) throw new ErreurCobol(`EXEC SQL : ${ligne.length} colonne(s) lue(s) pour ${into.length} zone(s) INTO`, ins.ligne);
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
        if (!c) throw new ErreurCobol(`curseur ${ins.curseur} non déclaré`, ins.ligne);
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
