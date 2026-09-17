<?php

namespace App\Controller;

use App\Contact\ContactInbox;
use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
use App\Entity\User;
use App\Form\ContactFormType;
use App\Seo\SeoWriter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Page de contact, et page Écoles et entreprises avec sa demande de devis : les deux arrivent dans la même boîte. */
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactInbox $inbox,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.contact')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, SeoWriter $seo): Response
    {
        $message = $this->newMessage();
        $subject = ContactSubject::tryFrom($request->query->getString('objet'));
        if (null !== $subject) {
            $message->setSubject($subject);
        }
        $form = $this->createForm(ContactFormType::class, $message);
        if ($sent = $this->handle($form, $request)) {
            return $sent;
        }
        $seo->contact();

        return $this->render('contact/index.html.twig', ['form' => $form, 'open' => $this->inbox->isOpen()], $this->status($form));
    }

    #[Route('/ecoles-et-entreprises', name: 'app_organizations', methods: ['GET', 'POST'])]
    public function organizations(Request $request, SeoWriter $seo): Response
    {
        $form = $this->createForm(ContactFormType::class, $this->newMessage()->setSubject(ContactSubject::Organization), ['organization' => true]);
        if ($sent = $this->handle($form, $request, '#demande')) {
            return $sent;
        }
        $seo->organizations();

        return $this->render('contact/organizations.html.twig', ['form' => $form, 'open' => $this->inbox->isOpen()], $this->status($form));
    }

    /** @param FormInterface<ContactMessage> $form */
    private function handle(FormInterface $form, Request $request, string $fragment = ''): ?Response
    {
        if (!$this->inbox->isOpen()) {
            return null;
        }
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return null;
        }
        $done = fn () => $this->redirect($request->getPathInfo().$fragment, Response::HTTP_SEE_OTHER);
        // Un robot remplit le champ piège : on fait comme si tout allait bien, sans rien garder.
        if ('' !== (string) $form->get(ContactFormType::TRAP)->getData()) {
            $this->addFlash('success', 'Message envoyé, merci. Nous vous répondons par email.');

            return $done();
        }
        if (!$form->isValid()) {
            return null;
        }
        if (!$this->limiter->create($request->getClientIp() ?? 'inconnu')->consume()->isAccepted()) {
            $form->addError(new FormError('Beaucoup de messages en peu de temps depuis votre connexion : réessayez dans une heure, ou écrivez-nous directement par email.'));

            return null;
        }
        $this->inbox->receive($form->getData());
        $this->addFlash('success', 'Message envoyé, merci. Nous vous répondons par email.');

        return $done();
    }

    private function newMessage(): ContactMessage
    {
        $message = new ContactMessage($this->clock->now());
        $user = $this->getUser();
        if ($user instanceof User) {
            $message->setUser($user)->setName($user->getDisplayName())->setEmail($user->getEmail());
        }

        return $message;
    }

    /** @param FormInterface<ContactMessage> $form */
    private function status(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
