import sentiers from '../../data/sentiers.json'

export default defineEventHandler(() => sentiers.map((sentier) => ({ ...sentier, libelle: libelleDifficulte(sentier.difficulte), total: NOMBRE_DE_SENTIERS })))
