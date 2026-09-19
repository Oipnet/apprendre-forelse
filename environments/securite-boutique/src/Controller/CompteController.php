<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Entity\Client;
use App\Repository\AdresseRepository;
use App\Repository\ClientRepository;
use App\Security\HachageDuPrestataire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class CompteController extends AbstractController
{
    /**
     * Inscription.
     *
     * Failles :
     *  - F5.4 : hydratation en masse de l'entité Client. Poster roles[]=ROLE_ADMIN
     *    crée un compte administrateur. C'est ainsi qu'est né le compte fantôme.
     *  - F14.5 : message d'unicité explicite (« adresse déjà utilisée ») → oracle
     *    d'énumération des comptes.
     */
    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request, ClientRepository $clients, HachageDuPrestataire $hachage, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email', '');
            if (null !== $clients->parEmail($email)) {
                // F14.5 : révèle qu'un compte existe déjà pour cet e-mail.
                $this->addFlash('error', 'Cette adresse est déjà utilisée.');

                return $this->redirectToRoute('app_inscription');
            }

            $client = new Client();
            // F5.4 : hydratation en masse depuis la requête (roles compris).
            foreach ($request->request->all() as $champ => $valeur) {
                $setter = 'set'.ucfirst($champ);
                if (method_exists($client, $setter)) {
                    $client->$setter($valeur);
                }
            }
            $client->setMotDePasse($hachage->hacher((string) $request->request->get('motDePasse', '')));

            $em->persist($client);
            $em->flush();
            $this->addFlash('success', 'Compte créé, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('compte/inscription.html.twig');
    }

    #[Route('/connexion', name: 'app_connexion', methods: ['GET'])]
    public function connexion(AuthenticationUtils $utils): Response
    {
        return $this->render('compte/connexion.html.twig', [
            'dernier_identifiant' => $utils->getLastUsername(),
            'erreur' => $utils->getLastAuthenticationError(),
        ]);
    }

    /**
     * FAILLE (chapitre 9, F9.3) : la déconnexion se contente de vider le panier
     * et d'oublier le jeton en mémoire ; la session n'est PAS invalidée, et le
     * cookie « souvenir » n'est pas effacé (il reconnecte à la requête suivante).
     * Correction : passer par le logout du pare-feu, invalidate(), effacer le cookie.
     */
    #[Route('/deconnexion', name: 'app_deconnexion', methods: ['GET'])]
    public function deconnexion(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        $request->getSession()->remove('panier');
        $tokenStorage->setToken(null);

        return $this->redirectToRoute('app_accueil');
    }

    #[Route('/compte', name: 'app_compte', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function tableauDeBord(): Response
    {
        return $this->render('compte/index.html.twig');
    }

    /**
     * FAILLE (chapitre 5, F5.4) : la même hydratation en masse sur le profil.
     * roles[]=ROLE_ADMIN élève le compte connecté.
     */
    #[Route('/compte/profil', name: 'app_compte_profil', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function profil(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        if ($request->isMethod('POST')) {
            foreach ($request->request->all() as $champ => $valeur) {
                $setter = 'set'.ucfirst($champ);
                if (method_exists($client, $setter)) {
                    $client->$setter($valeur);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('app_compte_profil');
        }

        return $this->render('compte/profil.html.twig', ['client' => $client]);
    }

    #[Route('/compte/adresses', name: 'app_compte_adresses', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function adresses(Request $request, AdresseRepository $adresses, EntityManagerInterface $em): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        if ($request->isMethod('POST')) {
            // F4.1 : ajout d'adresse sans jeton CSRF.
            $adresse = (new Adresse())
                ->setClient($client)
                ->setLibelle((string) $request->request->get('libelle', 'Adresse'))
                ->setRue((string) $request->request->get('rue', ''))
                ->setCodePostal((string) $request->request->get('codePostal', ''))
                ->setVille((string) $request->request->get('ville', ''));
            $em->persist($adresse);
            $em->flush();

            return $this->redirectToRoute('app_compte_adresses');
        }

        return $this->render('compte/adresses.html.twig', [
            'adresses' => $adresses->findBy(['client' => $client]),
        ]);
    }

    /**
     * FAILLE (chapitre 4, F4.3 — Boss) : suppression en GET, sans jeton. Un simple
     * <img src="/compte/adresse/12/supprimer"> dans un e-mail suffit à déclencher
     * l'action sur le client connecté. Correction : methods POST + jeton CSRF.
     */
    #[Route('/compte/adresse/{id}/supprimer', name: 'app_compte_adresse_supprimer', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function supprimerAdresse(int $id, AdresseRepository $adresses, EntityManagerInterface $em): Response
    {
        $adresse = $adresses->find($id);
        if (null !== $adresse) {
            $em->remove($adresse);
            $em->flush();
        }

        return $this->redirectToRoute('app_compte_adresses');
    }

    /**
     * Mot de passe oublié.
     *
     * FAILLE (chapitre 10, F10.1) : le jeton est md5(email.jour), stocké en clair,
     * sans expiration, réutilisable et devinable sans recevoir l'e-mail.
     * Correction : reset-password-bundle (jeton aléatoire haché, expirant, à usage unique).
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
                // F14.5 : message différent selon que le compte existe ou non.
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
            // F10.4 : le jeton N'est PAS vidé après usage → réutilisable.
            $em->flush();
            $this->addFlash('success', 'Mot de passe changé.');

            return $this->redirectToRoute('app_connexion');
        }

        return $this->render('compte/reinitialiser.html.twig', ['jeton' => $jeton]);
    }
    /**
     * FAILLE (chapitre 7, F7.5 — Boss) : porte dérobée laissée par le prestataire
     * pour ses tests. Hors access_control, elle connecte en admin si le jeton vaut
     * une constante en dur.  /connexion-rapide?jeton=damien2024
     * Correction : la supprimer (bonne réponse), et vérifier qu'aucune autre route
     * ne fait pareil.
     */
    #[Route('/connexion-rapide', name: 'app_connexion_rapide', methods: ['GET'])]
    public function connexionRapide(Request $request, ClientRepository $clients, TokenStorageInterface $tokenStorage): Response
    {
        if ('damien2024' !== $request->query->get('jeton')) {
            throw $this->createNotFoundException();
        }
        $marc = $clients->parEmail('marc@brasserie-lacombe.fr');
        if (null !== $marc) {
            $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($marc, 'main', $marc->getRoles()));
        }

        return $this->redirectToRoute('app_admin');
    }
}
