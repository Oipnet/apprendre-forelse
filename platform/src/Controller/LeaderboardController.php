<?php

namespace App\Controller;

use App\Entity\User;
use App\Leaderboard\Leaderboard;
use App\Leaderboard\LeaderboardPeriod;
use App\Leaderboard\XpScale;
use App\Share\ShareLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/** Le classement public, et le choix d'y paraître (depuis le classement lui-même ou « Mon compte »). */
final class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly Leaderboard $leaderboard,
    ) {
    }

    #[Route(['fr' => '/classement', 'en' => '/en/leaderboard'], name: 'app_leaderboard', methods: ['GET'])]
    public function index(Request $request, XpScale $scale, TranslatorInterface $translator): Response
    {
        $period = LeaderboardPeriod::tryFrom($request->query->getString('periode')) ?? LeaderboardPeriod::Month;
        $filters = $this->leaderboard->trackFilters();
        $track = $request->query->getString('parcours');
        $user = $this->getUser();

        return $this->render('leaderboard/index.html.twig', [
            'board' => $this->leaderboard->of($period, $user instanceof User ? $user : null, isset($filters[$track]) ? $track : null),
            'periods' => LeaderboardPeriod::cases(),
            'trackFilters' => $filters,
            'scale' => $scale->rules(),
            'share' => ShareLinks::of($this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), $translator->trans('index.partage', [], 'classement'), 'classement'),
        ]);
    }

    #[Route(['fr' => '/compte/classement', 'en' => '/en/account/leaderboard'], name: 'app_leaderboard_opt_in', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function optIn(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('leaderboard', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->getUser();
        \assert($user instanceof User);
        $optIn = $request->request->getBoolean('participer');
        $user->setLeaderboardOptIn($optIn);
        $entityManager->flush();
        $this->addFlash('success', $optIn
            ? t('flash.visible', ['pseudo' => $user->getDisplayName()], 'classement')
            : t('flash.invisible', [], 'classement'));

        return 'classement' === $request->request->getString('retour')
            ? $this->redirectToRoute('app_leaderboard', status: Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_account', ['_fragment' => 'classement'], Response::HTTP_SEE_OTHER);
    }
}
