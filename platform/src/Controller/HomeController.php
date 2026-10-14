<?php

namespace App\Controller;

use App\Content\HomePage;
use App\Entity\User;
use App\Seo\Page\HomeSeo;
use App\Theme\Theme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
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

    /** L'accueil anglais n'existe que si le thème a ses textes en anglais (« locales » de theme.yaml). */
    #[Route(['fr' => '/', 'en' => '/en'], name: 'app_home', methods: ['GET'])]
    public function index(Request $request, HomePage $page, HomeSeo $seo, Theme $theme): Response
    {
        if (!$theme->speaks($request->getLocale())) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        $home = $page->of($user instanceof User ? $user : null);
        $seo->write($home['faq']);

        return $this->render('home.html.twig', [...$home, 'inviteOnly' => $this->inviteOnly]);
    }
}
