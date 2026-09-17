import '../site.css';
import '../playground.css';
import '../studio.css';
import { mountStudio } from '../app/Studio';
import { mountLessonStudio } from '../app/LessonStudio';

const racine = document.querySelector<HTMLElement>('[data-studio]');
if (racine) void mountStudio(racine, JSON.parse(racine.dataset.config!));

const fiche = document.querySelector<HTMLElement>('[data-studio-lesson]');
if (fiche) mountLessonStudio(fiche, JSON.parse(fiche.dataset.config!));
