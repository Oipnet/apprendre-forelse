<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

final class BuildTest extends SimulatorTestCase
{
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
