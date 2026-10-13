<?php

namespace App\Export;

use App\Entity\Certificate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/**
 * Le certificat de réussite en PDF, une page A4 paysage : titulaire, parcours, résumé (exercices, sans solution
 * consultée) et l'adresse où le vérifier, qui donne le détail exercice par exercice. Même réglage de dompdf que les
 * fiches de cours (LessonPdf) : rien n'est chargé depuis le réseau.
 */
final class CertificatePdf
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.cache_dir%/dompdf')] private readonly string $workDir,
    ) {
    }

    public function render(Certificate $certificate): string
    {
        (new Filesystem())->mkdir($this->workDir);

        $dompdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'chroot' => $this->workDir,
            'tempDir' => $this->workDir,
            'fontDir' => $this->workDir,
            'fontCache' => $this->workDir,
        ]));
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml($this->twig->render('certificate/pdf.html.twig', ['certificate' => $certificate]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Nom de fichier ASCII, sûr pour l'en-tête Content-Disposition. */
    public static function filename(Certificate $certificate): string
    {
        return sprintf('certificat-%s-%s.pdf', $certificate->getTrackId(), $certificate->getCode());
    }
}
