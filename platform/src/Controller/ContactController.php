<?php

namespace App\Controller;

use App\Contact\ContactInbox;
use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
use App\Entity\User;
use App\Form\ContactFormType;
use App\Repository\CertificateRepository;
use App\Security\QuotaExceeded;
use App\Seo\Seo;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/** Page de contact, et page Écoles et entreprises avec sa demande de devis : les deux arrivent dans la même boîte. */
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactInbox $inbox,
        private readonly ClockInterface $clock,
        private readonly CertificateRepository $certificates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    #[Seo('contact.index.seo.titre', 'contact.index.seo.description', domain: 'compte')]
    public function contact(Request $request): Response
    {
        $message = $this->newMessage();
        $subject = ContactSubject::tryFrom($request->query->getString('objet'));
        if (null !== $subject) {
            $message->setSubject($subject);
        }
        if (ContactSubject::Certificate === $subject) {
            $this->attachCertificate($message, $request->query->getString('certificat'));
        }
        $form = $this->createForm(ContactFormType::class, $message);
        if ($sent = $this->handle($form, $request)) {
            return $sent;
        }
        return $this->render('contact/index.html.twig', ['form' => $form, 'open' => $this->inbox->isOpen()], $this->status($form));
    }

    #[Route('/ecoles-et-entreprises', name: 'app_organizations', methods: ['GET', 'POST'])]
    #[Seo('Former une classe ou une équipe au développement', 'Écoles, organismes de formation, entreprises : vos apprenants codent dans le navigateur, sans rien installer, et vous suivez leur progression exercice par exercice. Sur devis.', breadcrumb: 'Écoles et entreprises')]
    public function organizations(Request $request): Response
    {
        $form = $this->createForm(ContactFormType::class, $this->newMessage()->setSubject(ContactSubject::Organization), ['organization' => true]);
        if ($sent = $this->handle($form, $request, '#demande')) {
            return $sent;
        }
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
            $this->addFlash('success', t('flash.message_envoye', [], 'compte'));

            return $done();
        }
        if (!$form->isValid()) {
            return null;
        }
        try {
            $this->inbox->receive($form->getData(), $request->getClientIp() ?? 'inconnu');
        } catch (QuotaExceeded $e) {
            $form->addError(new FormError($e->trans($this->translator)));

            return null;
        }
        $this->addFlash('success', t('flash.message_envoye', [], 'compte'));

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

    /**
     * Contester un certificat depuis sa page : le message y est rattaché et commence par son code. Seul le titulaire,
     * connecté, rattache son certificat.
     */
    private function attachCertificate(ContactMessage $message, string $code): void
    {
        $certificate = '' !== $code ? $this->certificates->findByCode($code) : null;
        $user = $this->getUser();
        if (null === $certificate || !$user instanceof User || $certificate->getUser()->getId() !== $user->getId()) {
            return;
        }
        $message->setCertificate($certificate);
        if (null === $message->getMessage()) {
            $message->setMessage($this->translator->trans('contact.index.contestation', ['code' => $certificate->getCode(), 'parcours' => $certificate->getTrackTitle(), 'motif' => $certificate->getRevocationReason() ?? '—'], 'compte')."\n\n");
        }
    }

    /** @param FormInterface<ContactMessage> $form */
    private function status(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
