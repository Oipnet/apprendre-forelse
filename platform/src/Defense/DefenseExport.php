<?php

namespace App\Defense;

use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\Defense;
use App\Repository\DefenseRepository;

/** Les soutenances d'une cohorte en CSV (séparateur « ; », pour un tableur réglé en français), pour le dossier de l'école. */
final readonly class DefenseExport
{
    public function __construct(
        private DefenseRepository $defenses,
        private ContentRepository $content,
    ) {
    }

    public static function filename(Cohort $cohort): string
    {
        return sprintf('soutenances-%s.csv', $cohort->getCode());
    }

    public function csv(Cohort $cohort): string
    {
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Flux temporaire indisponible.');
        // BOM : un tableur ouvre l'UTF-8 sans casser les accents.
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['Date', 'Heure', 'Durée (min)', 'Parcours', 'Modalité', 'Lieu', 'Apprenant', 'Email', 'Résultat', 'Évaluateur', 'Évaluée le', 'Grille', 'Commentaire'], ';', escape: '');
        foreach ($this->defenses->findByCohort($cohort) as $defense) {
            fputcsv($out, array_map(self::cell(...), $this->row($defense)), ';', escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : elle reste du texte. */
    private static function cell(string|int $value): string|int
    {
        return \is_string($value) && 1 === preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @return list<string|int> */
    private function row(Defense $defense): array
    {
        $track = $this->content->findTrack($defense->getTrackId());
        $grades = $defense->getGrades();
        $grid = [];
        foreach ($track->chapters ?? [] as $chapter) {
            if (isset($grades[$chapter->id])) {
                $grid[] = $chapter->title.' : '.$grades[$chapter->id]->label();
            }
        }

        return [
            $defense->getStartsAt()->format('d/m/Y'),
            $defense->getStartsAt()->format('H:i'),
            $defense->getDurationMinutes(),
            $track->title ?? $defense->getTrackId(),
            $defense->getMode()->label(),
            $defense->getPlace(),
            (string) $defense->getLearner()?->getDisplayName(),
            (string) $defense->getLearner()?->getEmail(),
            $defense->getOutcome()?->label() ?? ($defense->isBooked() ? 'Réservée' : 'Libre'),
            (string) $defense->getEvaluatorName(),
            $defense->getEvaluatedAt()?->format('d/m/Y') ?? '',
            implode(' | ', $grid),
            (string) $defense->getComment(),
        ];
    }
}
