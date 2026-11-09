// Les solutions de référence des défis : leur SYSOUT sous GnuCOBOL est l'attendue du défi (en français et en anglais).
export const SOLUTIONS = {
  // les codes sont pris dans le modèle (vendeur, nom, montant), pour servir aussi au défi en anglais
  '01': (m) => {
    const [vendeur, nom, montant] = m.dictionnaire.map((d) => d.code);
    Object.assign(m.appels[0], { argument: [vendeur], rupture: 1 });
    Object.assign(m.traitement, { filtres: [{ rubrique: montant, op: '>', valeur: '0' }], detail: true, colonnes: [vendeur, nom, montant], totaux: [montant], compter: true });
    return m;
  },
};
