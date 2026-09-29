<?php

namespace App\Tests\Validator;

use App\Validator\StrongPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** La règle d'un nouveau mot de passe ; Have I Been Pwned est simulé, jamais appelé. */
final class StrongPasswordTest extends TestCase
{
    /** @var list<string> préfixes d'empreinte demandés au faux service */
    private array $requests = [];

    /** @param list<string> $leaked mots de passe que le faux service dit compromis */
    private function validator(array $leaked = [], int $status = 200, bool $enabled = true): ValidatorInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($leaked, $status): MockResponse {
            $prefix = substr($url, -5);
            $this->requests[] = $prefix;
            $lines = [];
            foreach ($leaked as $password) {
                $hash = strtoupper(sha1($password));
                if (str_starts_with($hash, $prefix)) {
                    $lines[] = substr($hash, 5).':4242';
                }
            }

            return new MockResponse(implode("\r\n", $lines), ['http_code' => $status]);
        });
        $factory = new class($http, $enabled) extends ConstraintValidatorFactory {
            public function __construct(private readonly MockHttpClient $http, private readonly bool $enabled)
            {
                parent::__construct();
            }

            public function getInstance(Constraint $constraint): ConstraintValidatorInterface
            {
                return NotCompromisedPasswordValidator::class === $constraint->validatedBy()
                    ? new NotCompromisedPasswordValidator($this->http, 'UTF-8', $this->enabled)
                    : parent::getInstance($constraint);
            }
        };

        return Validation::createValidatorBuilder()->setConstraintValidatorFactory($factory)->getValidator();
    }

    /** @return list<string> */
    private function messages(ValidatorInterface $validator, string $password): array
    {
        return array_map(static fn ($violation) => (string) $violation->getMessage(), iterator_to_array($validator->validate($password, new StrongPassword())));
    }

    /** @return iterable<string, array{string, string}> */
    public static function faibles(): iterable
    {
        yield 'vide' => ['', 'Choisissez un mot de passe.'];
        yield 'trop court' => ['Ab3!', 'Au moins 8 caractères.'];
        yield 'mot du dictionnaire' => ['motdepasse', 'trop facile à deviner'];
        yield 'suite de chiffres' => ['12345678', 'trop facile à deviner'];
        yield 'rangée du clavier' => ['azertyuiop', 'trop facile à deviner'];
        yield 'composition « règlementaire »' => ['MonChat2024!', 'trop facile à deviner'];
    }

    #[DataProvider('faibles')]
    public function testUnMotDePasseFaibleEstRefuseAvecUnSeulMessage(string $password, string $expected): void
    {
        $messages = $this->messages($this->validator(), $password);

        $this->assertCount(1, $messages, 'Un seul message à la fois.');
        $this->assertStringContainsString($expected, $messages[0]);
        $this->assertSame([], $this->requests, 'Pas d\'appel à Have I Been Pwned pour un mot de passe déjà refusé.');
    }

    public function testUnePhraseDePasseEstAcceptee(): void
    {
        $this->assertSame([], $this->messages($this->validator(), 'la-mouette-rit-au-port'));
        $this->assertSame([strtoupper(substr(sha1('la-mouette-rit-au-port'), 0, 5))], $this->requests, 'Seuls 5 caractères de l\'empreinte partent.');
    }

    public function testUnMotDePasseCompromisEstRefuse(): void
    {
        $messages = $this->messages($this->validator(leaked: ['la-mouette-rit-au-port']), 'la-mouette-rit-au-port');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('fuite de données connue', $messages[0]);
    }

    public function testSiHaveIBeenPwnedNeRepondPasLeMotDePassePasse(): void
    {
        $this->assertSame([], $this->messages($this->validator(leaked: ['la-mouette-rit-au-port'], status: 503), 'la-mouette-rit-au-port'));
    }

    public function testDesactiveeLaVerificationDesFuitesNAppelleRien(): void
    {
        $this->assertSame([], $this->messages($this->validator(leaked: ['la-mouette-rit-au-port'], enabled: false), 'la-mouette-rit-au-port'));
        $this->assertSame([], $this->requests);
    }
}
