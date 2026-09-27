<?php

namespace App\Export;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** Un PDF personnalisé, affiché dans le navigateur : généré à chaque demande, jamais mis en cache. */
final class PdfResponse extends Response
{
    public function __construct(string $pdf, string $filename)
    {
        parent::__construct($pdf, self::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
