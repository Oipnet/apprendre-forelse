<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

/** Les noms aléatoires de Docker (« festive_hopper ») : un adjectif et un nom de scientifique. */
final class Names
{
    private const LEFT = ['admiring', 'amazing', 'awesome', 'blissful', 'bold', 'brave', 'charming', 'clever', 'compassionate', 'confident', 'cool', 'dazzling', 'determined', 'eager', 'ecstatic', 'elegant', 'epic', 'festive', 'focused', 'friendly', 'gallant', 'gifted', 'goofy', 'happy', 'hopeful', 'inspiring', 'jolly', 'keen', 'kind', 'laughing', 'loving', 'lucid', 'magical', 'modest', 'musing', 'nice', 'nifty', 'optimistic', 'peaceful', 'pensive', 'practical', 'quirky', 'relaxed', 'serene', 'sharp', 'silly', 'sleepy', 'stoic', 'sweet', 'tender', 'trusting', 'upbeat', 'vibrant', 'wizardly', 'youthful', 'zealous'];
    private const RIGHT = ['agnesi', 'babbage', 'bardeen', 'bohr', 'borg', 'brattain', 'cannon', 'carson', 'chatelet', 'cori', 'curie', 'darwin', 'dijkstra', 'einstein', 'euler', 'fermat', 'fermi', 'franklin', 'galois', 'germain', 'goldberg', 'hamilton', 'hodgkin', 'hopper', 'hypatia', 'kapitsa', 'knuth', 'lalande', 'lamarr', 'lovelace', 'lumiere', 'meitner', 'mirzakhani', 'noether', 'pascal', 'pasteur', 'perrin', 'poincare', 'ritchie', 'shannon', 'sinoussi', 'tesla', 'thompson', 'torvalds', 'turing', 'villani', 'wozniak', 'yonath'];

    /** @param callable(string): bool $taken */
    public static function generate(callable $taken): string
    {
        for ($i = 0; $i < 50; ++$i) {
            $name = self::LEFT[random_int(0, \count(self::LEFT) - 1)].'_'.self::RIGHT[random_int(0, \count(self::RIGHT) - 1)];
            if (!$taken($name)) {
                return $name;
            }
        }

        return 'container_'.bin2hex(random_bytes(3));
    }
}
