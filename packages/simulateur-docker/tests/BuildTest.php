<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class BuildTest extends SimulatorTestCase
{
    private const METADONNEES = <<<'DOCKERFILE'
        FROM alpine:3.20
        ARG VERSION=1
        LABEL app=menu
        EXPOSE 8080
        ENV MODE=prod
        HEALTHCHECK CMD true
        ENTRYPOINT ["sh", "-c"]
        CMD ["echo", "a"]
        STOPSIGNAL SIGTERM
        VOLUME /data
        USER root
        WORKDIR /app
        COPY a.txt .
        RUN echo run > /app/r

        DOCKERFILE;

    /**
     * Ce qu'un vrai `docker build` (BuildKit, Docker 29.3) reprend du cache quand une seule instruction change,
     * relevé sur ce Dockerfile : [WORKDIR, COPY, RUN] en cache ?
     *
     * @return iterable<string, array{0: string, 1: string, 2: array<string,string>, 3: array{bool, bool, bool}}>
     */
    public static function changementsDeMetadonnees(): iterable
    {
        yield 'rien' => ['x', 'x', [], [true, true, true]];
        yield 'LABEL' => ['app=menu', 'app=carte', [], [true, true, true]];
        yield 'EXPOSE' => ['EXPOSE 8080', 'EXPOSE 9090', [], [true, true, true]];
        yield 'HEALTHCHECK' => ['CMD true', 'CMD false', [], [true, true, true]];
        yield 'ENTRYPOINT' => ['["sh", "-c"]', '["sh"]', [], [true, true, true]];
        yield 'CMD' => ['"echo", "a"', '"echo", "b"', [], [true, true, true]];
        yield 'STOPSIGNAL' => ['SIGTERM', 'SIGINT', [], [true, true, true]];
        yield 'VOLUME' => ['VOLUME /data', 'VOLUME /autre', [], [true, true, true]];
        // ENV et ARG arrivent dans l'environnement du RUN, et nulle part ailleurs.
        yield 'ENV' => ['MODE=prod', 'MODE=dev', [], [true, true, false]];
        yield 'ARG, valeur par défaut (inutilisé)' => ['VERSION=1', 'VERSION=2', [], [true, true, false]];
        yield '--build-arg d\'un ARG déclaré' => ['x', 'x', ['VERSION' => '7'], [true, true, false]];
        yield '--build-arg d\'un ARG non déclaré' => ['x', 'x', ['INCONNU' => '1'], [true, true, true]];
        // Le dossier de WORKDIR appartient à l'utilisateur ; COPY sans --chown copie en root.
        yield 'USER' => ['USER root', 'USER nobody', [], [false, false, false]];
    }

    /** @param array<string,string> $buildArgs @param array{bool, bool, bool} $attendu */
    #[DataProvider('changementsDeMetadonnees')]
    public function testUneMetadonneeNeRejouePasLesCouchesQuiNeLUtilisentPas(string $avant, string $apres, array $buildArgs, array $attendu): void
    {
        $this->files(['Dockerfile' => self::METADONNEES, 'a.txt' => 'contenu']);
        $premier = $this->docker()->build('.', null, ['menu']);
        $this->assertTrue($premier->success, $premier->output);

        $this->files(['Dockerfile' => str_replace($avant, $apres, self::METADONNEES)]);
        $second = $this->docker()->build('.', null, ['menu'], buildArgs: $buildArgs);
        $this->assertTrue($second->success, $second->output);

        $cache = static fn (string $instruction): bool => array_values(array_filter($second->steps, static fn (array $etape) => $etape['instruction'] === $instruction))[0]['cached'];
        $this->assertSame($attendu, [$cache('WORKDIR'), $cache('COPY'), $cache('RUN')], $second->output);
    }

    public function testUneVariableDansUnCheminRejoueLEtape(): void
    {
        $dockerfile = "FROM alpine:3.20\nWORKDIR /app\nARG VERSION=1\nCOPY a.txt \${VERSION}.txt\nRUN echo run > /tmp/r\n";
        $this->files(['Dockerfile' => $dockerfile, 'a.txt' => 'contenu']);
        $this->assertTrue($this->docker()->build('.', null, ['menu'])->success);
        $this->files(['Dockerfile' => str_replace('VERSION=1', 'VERSION=5', $dockerfile)]);
        $second = $this->docker()->build('.', null, ['menu']);
        $cache = static fn (string $instruction): bool => array_values(array_filter($second->steps, static fn (array $etape) => $etape['instruction'] === $instruction))[0]['cached'];
        $this->assertSame([true, false, false], [$cache('WORKDIR'), $cache('COPY'), $cache('RUN')], $second->output);
    }

    public function testLeCacheDesCouches(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-cli\nRUN apt-get update && apt-get install -y --no-install-recommends git\nCOPY . /app\n",
            'index.php' => '<?php echo 1;',
        ]);
        $first = $this->docker()->build('.', null, ['app']);
        $this->assertTrue($first->success, $first->output);
        $this->assertSame(0, $first->cachedSteps());

        file_put_contents($this->project.'/index.php', '<?php echo 2;');
        $second = $this->docker()->build('.', null, ['app']);
        $this->assertTrue($second->success, $second->output);
        $this->assertStringContainsString("RUN apt-get update && apt-get install -y --no-install-recommends git\n#6 CACHED", $second->output);
        $this->assertStringContainsString("COPY . /app\n#7 DONE", $second->output);
    }

    public function testNettoyerDansUneAutreCoucheNeRendPasLaPlace(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-cli\nRUN apt-get update && apt-get install -y libicu-dev\nRUN rm -rf /var/lib/apt/lists/*\n"]);
        $separate = $this->docker()->build('.', null, ['separe']);
        $this->files(['Dockerfile' => "FROM php:8.4-cli\nRUN apt-get update && apt-get install -y libicu-dev && rm -rf /var/lib/apt/lists/*\n"]);
        $together = $this->docker()->build('.', null, ['ensemble']);
        $this->assertTrue($separate->success && $together->success);
        $this->assertGreaterThan($together->image->size() + 15_000_000, $separate->image->size());
    }

    public function testAptSansUpdate(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-apache\nRUN apt-get install -y libzip-dev\n"]);
        $result = $this->docker()->build('.');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('E: Unable to locate package libzip-dev', $result->output);
        $this->assertStringContainsString('did not complete successfully: exit code: 100', $result->output);
        $this->assertStringContainsString('   2 | >>> RUN apt-get install -y libzip-dev', $result->output);
    }

    public function testExtensionSansSaBibliotheque(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-fpm-alpine\nRUN docker-php-ext-install intl\n"]);
        $result = $this->docker()->build('.');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('icu-uc', $result->output);
    }

    public function testDependancesVirtuellesRetireesTropTot(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-fpm-alpine\nRUN apk add --no-cache --virtual .build-deps icu-dev && docker-php-ext-install intl && apk del .build-deps\n"]);
        $result = $this->docker()->build('.', null, ['intl']);
        $this->assertTrue($result->success, $result->output);
        [, $output] = $this->cli('run --rm intl php -m');
        $this->assertStringContainsString("Unable to load dynamic library 'intl'", $output);
        $this->assertStringNotContainsString("\nintl\n", $output);
    }

    public function testGarderLaBibliothequeEtJeterLesOutils(): void
    {
        // Le motif recommandé par l'image php : outils de compilation en paquet virtuel, bibliothèque gardée.
        $this->files(['Dockerfile' => "FROM php:8.4-fpm-alpine\nRUN apk add --no-cache --virtual .build-deps icu-dev \\\n    && docker-php-ext-install intl \\\n    && apk add --no-cache icu-libs \\\n    && apk del .build-deps\n"]);
        $result = $this->docker()->build('.', null, ['intl']);
        $this->assertTrue($result->success, $result->output);
        $this->assertContains('icu-libs', $result->image->packages);
        $this->assertNotContains('icu-dev', $result->image->packages);
        [, $sortie] = $this->cli('run --rm intl php -m');
        $this->assertStringNotContainsString('Unable to load dynamic library', $sortie, 'icu-libs est resté : l\'extension intl se charge.');
        $this->assertStringContainsString("\nintl\n", $sortie);
        $this->assertLessThan(200_000_000, $result->image->size(), 'Une image Alpine reste légère.');
    }

    public function testDockerignoreEtFichierIntrouvable(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.4-cli\nCOPY . /app\n",
            '.dockerignore' => "vendor\n.env\n",
            '.env' => "SECRET=1\n",
            'vendor/lib.php' => '<?php',
            'src/App.php' => '<?php',
        ]);
        $result = $this->docker()->build('.', null, ['app']);
        $this->assertTrue($result->success, $result->output);
        $files = $result->image->filesystem();
        $this->assertArrayHasKey('/app/src/App.php', $files);
        $this->assertArrayNotHasKey('/app/.env', $files);
        $this->assertArrayNotHasKey('/app/vendor/lib.php', $files);

        $this->files(['Dockerfile' => "FROM php:8.4-cli\nCOPY .env /app/\n"]);
        $missing = $this->docker()->build('.');
        $this->assertFalse($missing->success);
        $this->assertStringContainsString('"/.env": not found', $missing->output);
    }

    public function testMultiEtapesAvecComposer(): void
    {
        $this->files([
            'Dockerfile' => <<<'DOCKERFILE'
                FROM composer:2 AS vendor
                WORKDIR /app
                COPY composer.json ./
                RUN composer install --no-dev --optimize-autoloader
                FROM php:8.4-apache
                COPY --from=vendor /app/vendor /var/www/html/vendor
                COPY public/ /var/www/html/
                DOCKERFILE,
            'composer.json' => json_encode(['require' => ['php' => '>=8.2'], 'autoload' => ['psr-4' => ['App\\' => 'src/']]]),
            'public/index.php' => '<?php echo "ok";',
        ]);
        $result = $this->docker()->build('.', null, ['app']);
        $this->assertTrue($result->success, $result->output);
        $this->assertArrayHasKey('/var/www/html/vendor/autoload.php', $result->image->filesystem());
        $this->assertStringContainsString('[vendor 4/4] RUN composer install', $result->output);
        $this->assertFalse($result->image->hasBinary('composer'), 'Composer reste dans l\'étape de construction.');
    }

    public function testComposerExigeLaVersionDePhp(): void
    {
        $this->files([
            'Dockerfile' => "FROM php:8.2-cli\nCOPY --from=composer:2 /usr/bin/composer /usr/bin/composer\nWORKDIR /app\nCOPY composer.json ./\nRUN composer install\n",
            'composer.json' => json_encode(['require' => ['php' => '>=8.4', 'ext-intl' => '*']]),
        ]);
        $result = $this->docker()->build('.');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('requires php >=8.4 but your php version (8.2.29) does not satisfy that requirement', $result->output);
        $this->assertStringContainsString('requires PHP extension ext-intl', $result->output);
    }

    public function testAvertissementsDesBuildChecks(): void
    {
        $this->files(['Dockerfile' => "FROM php:8.4-cli as base\nENV DB_PASSWORD secret\nCMD php -a\n"]);
        $result = $this->docker()->build('.');
        $this->assertTrue($result->success, $result->output);
        $this->assertTrue($result->hasWarning('FromAsCasing'));
        $this->assertTrue($result->hasWarning('LegacyKeyValueFormat'));
        $this->assertTrue($result->hasWarning('SecretsUsedInArgOrEnv'));
        $this->assertTrue($result->hasWarning('JSONArgsRecommended'));
    }

    public function testImageInconnue(): void
    {
        $this->files(['Dockerfile' => "FROM php:9.9-apache\n"]);
        $result = $this->docker()->build('.');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('docker.io/library/php:9.9-apache: not found', $result->output);
    }
}
