<?php

namespace App\Content;

final readonly class Pack
{
    /**
     * @param list<string> $trackIds
     * @param string|null  $engine   version(s) du moteur avec lesquelles ce pack fonctionne
     *                               (clé « moteur » de pack.yaml, syntaxe Composer : ^1.2, >=1.2 <2.0)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $version,
        public string $license,
        public array $trackIds,
        public string $directory,
        public ?string $engine = null,
        /** @var list<string> exercices de Pratique (dossiers de practice/) */
        public array $practiceIds = [],
        /**
         * Les environnements que ce pack déclare nécessaires, et où les chercher (clé « environments »).
         *
         * @var list<PackEnvironment>
         */
        public array $environments = [],
    ) {
    }
}
