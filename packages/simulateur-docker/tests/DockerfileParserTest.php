<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Dockerfile\ParseError;
use Forelse\DockerSim\Dockerfile\Parser;
use PHPUnit\Framework\TestCase;

final class DockerfileParserTest extends TestCase
{
    public function testEtapesDrapeauxEtContinuations(): void
    {
        $file = (new Parser())->parse(<<<'DOCKERFILE'
            # syntax=docker/dockerfile:1
            ARG PHP_VERSION=8.4
            FROM composer:2 AS vendor
            COPY composer.json composer.lock ./
            RUN composer install \
                # un commentaire au milieu
                --no-dev
            FROM php:${PHP_VERSION}-apache
            COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
            CMD ["apache2-foreground"]
            DOCKERFILE);
        $this->assertCount(1, $file->globalArgs);
        $this->assertCount(2, $file->stages);
        $this->assertSame('vendor', $file->stages[0]->name);
        $run = $file->stages[0]->instructions[2];
        $this->assertSame('RUN', $run->name);
        $this->assertSame('composer install     --no-dev', $run->arguments);
        $this->assertSame(5, $run->line);
        $this->assertSame(7, $run->endLine);
        $copy = $file->stages[1]->instructions[1];
        $this->assertSame(['from' => 'vendor', 'chown' => 'www-data:www-data'], $copy->flags);
        $this->assertSame(['apache2-foreground'], $file->stages[1]->instructions[2]->exec);
    }

    public function testInstructionInconnue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('dockerfile parse error on line 2: unknown instruction: RUNN');
        (new Parser())->parse("FROM php:8.4-cli\nRUNN echo\n");
    }

    public function testInstructionAvantFrom(): void
    {
        $this->expectExceptionMessage('no build stage in current context');
        (new Parser())->parse("RUN echo\nFROM php\n");
    }

    public function testHeredoc(): void
    {
        $file = (new Parser())->parse("FROM alpine\nCOPY <<EOF /etc/motd\nBienvenue\nEOF\nRUN echo ok\n");
        $this->assertSame("Bienvenue\n", $file->stages[0]->instructions[1]->heredoc);
        $this->assertSame('RUN', $file->stages[0]->instructions[2]->name);
    }
}
