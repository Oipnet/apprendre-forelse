<?php

namespace App\Scratch;

use App\Entity\ScratchShare;

/** Un lien court tout juste créé, et sa clé de suppression : la seule fois où elle sort du serveur. */
final readonly class CreatedScratchShare
{
    public function __construct(
        public ScratchShare $share,
        public string $deleteKey,
    ) {
    }
}
