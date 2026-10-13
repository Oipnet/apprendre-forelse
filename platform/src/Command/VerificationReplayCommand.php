<?php

namespace App\Command;

use App\Entity\VerificationStatus;
use App\Verification\SubmissionReplayer;
use App\Verification\VerificationRequest;
use App\Verification\VerificationResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rejoue une seule réussite : la demande (JSON) sur l'entrée standard, le verdict (JSON) en dernière ligne de la sortie.
 *
 * Lancée par le service « verificateur » (VerificationSandbox), sous un utilisateur sans droits et dans un dossier
 * jetable : c'est elle qui fait tourner le code de l'apprenant. Pas à lancer à la main ailleurs.
 */
#[AsCommand(
    name: 'app:verification:rejouer',
    description: 'Rejoue une réussite lue sur l\'entrée standard (lancée par le service verificateur).',
    hidden: true,
)]
final readonly class VerificationReplayCommand
{
    public function __construct(
        private SubmissionReplayer $replayer,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $data = json_decode((string) stream_get_contents(\STDIN), true);
        $request = \is_array($data) ? VerificationRequest::fromArray($data) : null;
        if (null === $request) {
            $output->writeln(json_encode((new VerificationResult(0, VerificationStatus::Unverifiable, 'Demande illisible.'))->toArray(), \JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }
        $output->writeln(json_encode($this->replayer->replay($request)->toArray(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));

        return Command::SUCCESS;
    }
}
