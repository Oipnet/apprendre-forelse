<?php

namespace App\Controller;

use App\Repository\ClientRepository;
use App\Security\HachageDuPrestataire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class MotDePasseOublieController extends AbstractController
{
    /**
     * FAILLE (chapitre 10, F10.1) : jeton md5(email.jour), en clair, sans expiration,
     * réutilisable et devinable. F14.5 : message différent selon l'existence du compte.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function motDePasseOublie(Request $request, ClientRepository $clients, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email', '');
            $client = $clients->parEmail($email);
            if (null !== $client) {
                $jeton = md5($email.date('Y-m-d'));
                $client->setJetonReinitialisation($jeton);
                $em->flush();

                $mailer->send((new Email())
                    ->from('support@brasserie-lacombe.fr')
                    ->to($email)
                    ->subject('Réinitialisation de votre mot de passe')
                    ->text('Lien : http://127.0.0.1:8080/mot-de-passe/'.$jeton));
                $this->addFlash('success', 'Un e-mail vous a été envoyé.');
            } else {
                $this->addFlash('error', 'Aucun compte avec cet e-mail.');
            }

            return $this->redirectToRoute('app_mot_de_passe_oublie');
        }

        return $this->render('compte/mot_de_passe_oublie.html.twig');
    }

    #[Route('/mot-de-passe/{jeton}', name: 'app_mot_de_passe_reinitialiser', methods: ['GET', 'POST'])]
    public function reinitialiser(string $jeton, Request $request, ClientRepository $clients, HachageDuPrestataire $hachage, EntityManagerInterface $em): Response
    {
        $client = $clients->findOneBy(['jetonReinitialisation' => $jeton]);
        if (null === $client) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $client->setMotDePasse($hachage->hacher((string) $request->request->get('motDePasse', '')));
            $em->flush(); // F10.4 : le jeton n'est pas vidé → réutilisable.
            $this->addFlash('success', 'Mot de passe changé.');

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('compte/reinitialiser.html.twig', ['jeton' => $jeton]);
    }
}
