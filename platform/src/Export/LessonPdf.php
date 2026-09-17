<?php

namespace App\Export;

use App\Content\Chapter;
use App\Content\LessonRenderer;
use App\Content\Pack;
use App\Content\Track;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Environment;

/**
 * Les fiches de cours en PDF, établies au nom de l'apprenant : une fiche par chapitre, ou le
 * livret d'un parcours entier.
 *
 * dompdf, en PHP pur : aucune dépendance système pour l'auto-hébergement. Les polices DejaVu
 * embarquées couvrent le français ; rien n'est chargé depuis le réseau, et le PHP embarqué dans
 * le HTML reste désactivé (la fiche vient d'un pack). Le pied de page est écrit sur le canvas
 * après le rendu, ce qui évite d'activer cette option pour numéroter les pages.
 */
final class LessonPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly LessonRenderer $renderer,
        #[Autowire('%kernel.cache_dir%/dompdf')] private readonly string $workDir,
    ) {
    }

    /**
     * @param array{chapter: Chapter, number: int, concepts: list<string>, xp: int} $summary voir ChapterSummary
     */
    public function render(Pack $pack, Track $track, array $summary, User $user, \DateTimeImmutable $date = new \DateTimeImmutable()): string
    {
        $html = $this->twig->render('chapter/pdf.html.twig', [
            ...$summary,
            'pack' => $pack,
            'track' => $track,
            'user' => $user,
            'date' => $date,
            'html' => $this->renderer->toHtml((string) $summary['chapter']->lesson),
        ]);

        return $this->toPdf($html, sprintf('%s · %s', $track->title, $summary['chapter']->title), $user, $date);
    }

    /**
     * Le livret : toutes les fiches du parcours, dans l'ordre, derrière une page de garde et un sommaire.
     *
     * @param list<array{chapter: Chapter, number: int, concepts: list<string>, xp: int}> $summaries chapitres dotés d'une fiche
     */
    public function renderBooklet(Pack $pack, Track $track, array $summaries, User $user, \DateTimeImmutable $date = new \DateTimeImmutable()): string
    {
        $html = $this->twig->render('chapter/booklet.html.twig', [
            'pack' => $pack,
            'track' => $track,
            'user' => $user,
            'date' => $date,
            'xp' => array_sum(array_column($summaries, 'xp')),
            'chapters' => array_map(fn (array $summary) => [...$summary, 'html' => $this->renderer->toHtml((string) $summary['chapter']->lesson)], $summaries),
        ]);

        return $this->toPdf($html, sprintf('%s · livret du parcours', $track->title), $user, $date);
    }

    private function toPdf(string $html, string $title, User $user, \DateTimeImmutable $date): string
    {
        (new Filesystem())->mkdir($this->workDir);

        $dompdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'chroot' => $this->workDir,
            'tempDir' => $this->workDir,
            'fontDir' => $this->workDir,
            'fontCache' => $this->workDir,
        ]));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.45, 0.45, 0.45];
        $y = $canvas->get_height() - 30;
        $canvas->page_text(40, $y, sprintf('%s — établi pour %s le %s', $title, $user->getDisplayName(), $date->format('d/m/Y')), $font, 7.5, $grey);
        $canvas->page_text($canvas->get_width() - 100, $y, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7.5, $grey);

        return (string) $dompdf->output();
    }

    /** Nom de fichier ASCII, sûr pour l'en-tête Content-Disposition. */
    public static function filename(Track $track, int $number, Chapter $chapter): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($chapter->title)->lower()->toString();

        return sprintf('%s-chapitre-%02d-%s.pdf', $track->id, $number, '' !== $slug ? $slug : $chapter->id);
    }

    public static function bookletFilename(Track $track): string
    {
        return sprintf('%s-livret.pdf', $track->id);
    }
}
