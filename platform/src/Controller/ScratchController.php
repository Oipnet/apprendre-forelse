<?php

namespace App\Controller;

use App\Api\ExercisePayloadFactory;
use App\Api\PlaygroundConfigFactory;
use App\Content\LessonRenderer;
use App\Scratch\ScratchFramework;
use App\Scratch\ScratchProjects;
use App\Seo\Page\ScratchSeo;
use App\Seo\Seo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le bac à sable : un projet Symfony ou Laravel vierge, dans le navigateur, sans compte. On y essaie du code et on en
 * partage le lien : le code voyage dans l'adresse (après le #), le serveur ne le voit ni ne le garde.
 */
final class ScratchController extends AbstractController
{
    /** Les questions de la page d'accueil, affichées et publiées en données structurées (FAQPage). */
    private const array FAQ = ['tester', 'compte', 'partager', 'confidentialite', 'versions'];

    public function __construct(private readonly ScratchSeo $seo)
    {
    }

    #[Route(['fr' => '/bac-a-sable', 'en' => '/en/playground'], name: 'app_scratch', methods: ['GET'])]
    #[Seo('bac_a_sable.index.titre', 'bac_a_sable.index.description', breadcrumb: 'bac_a_sable.index.fil_ariane', domain: 'seo')]
    public function index(TranslatorInterface $translator): Response
    {
        $faq = array_map(static fn (string $key) => [
            'question' => $translator->trans('bac_a_sable.faq.'.$key.'.question', [], 'apprentissage'),
            'answer' => $translator->trans('bac_a_sable.faq.'.$key.'.reponse', [], 'apprentissage'),
        ], self::FAQ);
        $this->seo->index($faq);

        return $this->render('scratch/index.html.twig', ['frameworks' => ScratchFramework::cases(), 'faq' => $faq]);
    }

    #[Route(['fr' => '/bac-a-sable/symfony', 'en' => '/en/playground/symfony'], name: 'app_scratch_symfony', methods: ['GET'])]
    #[Seo('bac_a_sable.symfony.titre', 'bac_a_sable.symfony.description', breadcrumb: 'bac_a_sable.symfony.fil_ariane', domain: 'seo')]
    public function symfony(Request $request, ScratchProjects $projects, PlaygroundConfigFactory $configs, LessonRenderer $markdown): Response
    {
        return $this->play(ScratchFramework::Symfony, $request, $projects, $configs, $markdown);
    }

    #[Route(['fr' => '/bac-a-sable/laravel', 'en' => '/en/playground/laravel'], name: 'app_scratch_laravel', methods: ['GET'])]
    #[Seo('bac_a_sable.laravel.titre', 'bac_a_sable.laravel.description', breadcrumb: 'bac_a_sable.laravel.fil_ariane', domain: 'seo')]
    public function laravel(Request $request, ScratchProjects $projects, PlaygroundConfigFactory $configs, LessonRenderer $markdown): Response
    {
        return $this->play(ScratchFramework::Laravel, $request, $projects, $configs, $markdown);
    }

    /** Le projet de départ, au format d'un exercice (contrat : playground/src/app/types.ts, ExercisePayload). */
    #[Route(['fr' => '/api/bac-a-sable/{framework}', 'en' => '/en/api/bac-a-sable/{framework}'], name: 'api_scratch', methods: ['GET'], format: 'json')]
    public function project(ScratchFramework $framework, Request $request, ScratchProjects $projects, ExercisePayloadFactory $payloads): JsonResponse
    {
        return $this->json($payloads->create($projects->exercise($framework, $request->getLocale()), $request));
    }

    private function play(ScratchFramework $framework, Request $request, ScratchProjects $projects, PlaygroundConfigFactory $configs, LessonRenderer $markdown): Response
    {
        $exercise = $projects->exercise($framework, $request->getLocale());
        $this->seo->project($framework);

        return $this->render('exercise/play.html.twig', [
            'exercise' => $exercise,
            'instructions' => $markdown->toHtmlUnderTitle($exercise->instructions),
            'framework' => $framework,
            'config' => $configs->scratch($framework),
            'intro_template' => 'scratch/_intro.html.twig',
        ]);
    }
}
