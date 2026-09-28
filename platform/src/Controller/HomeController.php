<?php

namespace App\Controller;

use App\Content\HomePage;
use App\Entity\User;
use App\Seo\Page\HomeSeo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Page d'accueil : la promesse, la démonstration, puis les parcours installés (packs de contenu). */
final class HomeController extends AbstractController
{
    public function __construct(
        /** Bêta fermée : la page propose la liste d'attente plutôt que l'inscription. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(HomePage $page, HomeSeo $seo): Response
    {
        $user = $this->getUser();
        $home = $page->of($user instanceof User ? $user : null);
        $seo->write($home['faq']);

        return $this->render('home.html.twig', [...$home, 'inviteOnly' => $this->inviteOnly]);
    }
}
