<?php

namespace App\Fixture;

use App\Entity\Avis;
use App\Entity\Biere;
use App\Entity\Adresse;
use App\Entity\Client;
use App\Entity\Commande;
use App\Entity\Etiquette;
use App\Entity\LigneCommande;
use App\Security\HachageDuPrestataire;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Charge un jeu de données crédible pour la Brasserie Lacombe, avec (par défaut)
 * les traces de l'attaque de Houblon Noir dans la nuit du 13 au 14 mars 2026.
 *
 * Déterministe : tout est daté par rapport à self::AUJOURD_HUI, aucun rand().
 */
#[Autoconfigure(public: true)]
final class ChargeurDeBoutique
{
    public const string AUJOURD_HUI = '2026-03-15';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HachageDuPrestataire $hachage,
    ) {
    }

    public function charger(bool $traces = true): void
    {
        $this->vider();

        $bieres = $this->chargerBieres();
        $clients = $this->chargerClients($traces);
        $this->chargerCommandes($bieres, $clients, $traces);
        $this->chargerAvis($bieres, $traces);
        if ($traces) {
            $this->chargerEtiquetteMalveillante($clients['support']);
        }

        $this->em->flush();
    }

    private function vider(): void
    {
        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        // SQLite : pas de TRUNCATE. On vide dans l'ordre des cles etrangeres.
        foreach ([
            'ligne_commande', 'avis', 'adresse', 'commande',
            'tentative_connexion', 'biere', 'etiquette', 'client',
        ] as $table) {
            $conn->executeStatement('DELETE FROM '.$table);
        }
    }

    /** @return array<string, Biere> indexé par slug */
    private function chargerBieres(): array
    {
        // [nom, style, degré, prix, stock, actif, descriptionHtml?]
        $donnees = [
            ['Blonde de Die', 'Blonde', 5.2, '3.90', 120, true, '<strong>Notre classique.</strong> Légère et florale.'],
            ['Triple du Vercors', 'Triple', 8.5, '8.80', 80, true, '<ul><li>Robe ambrée</li><li>Notes de miel</li></ul>'],
            ['IPA du Glandasse', 'IPA', 6.5, '4.80', 90, true, '<em>Amère et résineuse</em>, houblons américains.'],
            ['Brune de Saint-Roman', 'Brune', 6.0, '4.50', 60, true, null],
            ['Blanche du Claps', 'Blanche', 4.5, '4.20', 70, true, '<strong>Aux épices douces.</strong>'],
            ['Ambrée de la Sure', 'Ambrée', 6.2, '4.60', 55, true, null],
            ['Stout des Trois Becs', 'Stout', 7.0, '5.10', 40, true, '<em>Café et chocolat noir.</em>'],
            ['Session IPA du Vercors', 'IPA', 4.2, '4.30', 100, true, null],
            ['Blonde de Printemps', 'Blonde', 5.0, '3.90', 85, true, null],
            ['Triple Réserve', 'Triple', 9.0, '6.90', 30, true, '<strong>Édition limitée.</strong>'],
            ['IPA Américaine', 'IPA', 6.8, '4.90', 75, true, null],
            ['Pale Ale de la Drôme', 'Ambrée', 5.5, '4.40', 65, true, null],
            ['Brune Impériale', 'Brune', 8.0, '5.80', 25, true, null],
            ['Blanche aux Agrumes', 'Blanche', 4.8, '4.30', 90, true, null],
            ['Ambrée de Garde', 'Ambrée', 6.5, '4.70', 50, true, null],
            ['Saison de la Roanne', 'Blonde', 6.0, '4.80', 45, true, null],
            ['Double IPA du Glandasse', 'IPA', 8.2, '5.90', 35, true, null],
            ['Bière de Noël 2026', 'Ambrée', 7.5, '5.40', 60, true, '<strong>De saison</strong>, épices d\'hiver.'],
            // Les deux inactives, révélées par l'injection SQL du chapitre 2 :
            ['Cuvée de Noël 2025', 'Ambrée', 7.5, '5.40', 0, false, null],
            ['Triple du Vercors — brassin 47', 'Triple', 8.5, '5.60', 0, false, null],
        ];

        $bieres = [];
        foreach ($donnees as [$nom, $style, $degre, $prix, $stock, $actif, $html]) {
            $slug = $this->slug($nom);
            $biere = (new Biere())
                ->setSlug($slug)->setNom($nom)->setStyle($style)->setDegre($degre)
                ->setPrix($prix)->setStock($stock)->setActif($actif)
                ->setDescription("Une $style de caractère, brassée à Die.")
                ->setDescriptionHtml($html)
                ->setCreeLe(new \DateTimeImmutable('2024-01-01'));
            $this->em->persist($biere);
            $bieres[$slug] = $biere;
        }

        return $bieres;
    }

    /** @return array<string, Client> */
    private function chargerClients(bool $traces): array
    {
        $clients = [];

        // 30 clients ordinaires, e-mails en @example.com, 3 partagent « motdepasse ».
        $prenoms = ['claire.fournier', 'paul.martin', 'lea.bernard', 'hugo.thomas', 'emma.robert',
            'nathan.richard', 'chloe.petit', 'lucas.durand', 'manon.leroy', 'jules.moreau',
            'ines.simon', 'tom.laurent', 'sarah.michel', 'noah.garcia', 'jade.david',
            'louis.bertrand', 'lina.roux', 'gabriel.vincent', 'alice.fournier', 'raphael.girard',
            'zoe.andre', 'ethan.mercier', 'camille.blanc', 'adam.guerin', 'rose.boyer',
            'sacha.faure', 'lola.rousseau', 'liam.blanchard', 'anna.giraud', 'noe.lemaire'];

        foreach ($prenoms as $i => $identifiant) {
            // Trois premiers partagent le même mot de passe (hachages MD5 identiques).
            $motDePasse = $i < 3 ? 'motdepasse' : 'client'.($i + 1);
            $client = (new Client())
                ->setEmail($identifiant.'@example.com')
                ->setNom(ucwords(str_replace('.', ' ', $identifiant)))
                ->setMotDePasse($this->hachage->hacher($motDePasse))
                ->setTelephone('04 75 00 00 '.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT))
                ->setAdresse(($i + 1).' rue des Remparts')
                ->setCodePostal('26150')->setVille('Die')
                ->setCreeLe(new \DateTimeImmutable('2025-06-01'));
            $this->em->persist($client);
            $clients[$identifiant] = $client;
        }

        // Adresses de livraison (entité Adresse). Claire en a deux.
        foreach ([
            ['claire.fournier', 'Domicile', '1 rue des Remparts', '26150', 'Die'],
            ['claire.fournier', 'Bureau', '12 avenue de la Clairette', '26150', 'Die'],
            ['paul.martin', 'Domicile', '3 rue des Remparts', '26150', 'Die'],
        ] as [$id, $libelle, $rue, $cp, $ville]) {
            $adresse = (new Adresse())
                ->setClient($clients[$id])
                ->setLibelle($libelle)->setRue($rue)->setCodePostal($cp)->setVille($ville);
            $this->em->persist($adresse);
        }

        $marc = (new Client())
            ->setEmail('marc@brasserie-lacombe.fr')->setNom('Marc Lacombe')
            ->setMotDePasse($this->hachage->hacher('houblon2023'))
            ->setRoles(['ROLE_ADMIN'])->setCreeLe(new \DateTimeImmutable('2023-02-10'))
            ->setTelephone('04 75 22 33 44')->setAdresse('Brasserie, ZA les Chaux')
            ->setCodePostal('26150')->setVille('Die');
        $this->em->persist($marc);
        $clients['marc'] = $marc;

        $sofia = (new Client())
            ->setEmail('sofia@brasserie-lacombe.fr')->setNom('Sofia Benali')
            ->setMotDePasse($this->hachage->hacher('houblon2023'))
            ->setRoles(['ROLE_VENDEUR'])->setCreeLe(new \DateTimeImmutable('2023-03-15'))
            ->setCodePostal('26150')->setVille('Die');
        $this->em->persist($sofia);
        $clients['sofia'] = $sofia;

        // Le compte fantôme, créé par l'attaquant le 14 mars à 03:12:41.
        $support = (new Client())
            ->setEmail('support@brasserie-lacombe.fr')->setNom('Support Technique')
            ->setMotDePasse(md5('houblonnoir-'.uniqid())) // hachage qui ne correspond à rien
            ->setRoles($traces ? ['ROLE_ADMIN'] : [])
            ->setAdresse('-')
            ->setCreeLe(new \DateTimeImmutable($traces ? '2026-03-14 03:12:41' : '2026-03-14 03:12:41'));
        if ($traces) {
            $this->em->persist($support);
        }
        $clients['support'] = $support;

        return $clients;
    }

    /** @param array<string, Biere> $bieres @param array<string, Client> $clients */
    private function chargerCommandes(array $bieres, array $clients, bool $traces): void
    {
        $liste = array_values($bieres);
        $clientsOrdinaires = array_values(array_filter(
            $clients,
            fn (Client $c) => str_ends_with($c->getEmail(), '@example.com')
        ));

        $numero = 350;
        // ~60 commandes saines réparties sur 14 mois.
        for ($i = 0; $i < 62; ++$i) {
            $annee = $i < 20 ? 2025 : 2026;
            $client = $clientsOrdinaires[$i % \count($clientsOrdinaires)];
            $commande = (new Commande())
                ->setReference(sprintf('CMD-%d-%04d', $annee, $numero++))
                ->setClient($client)
                ->setStatut(['validee', 'expediee', 'validee', 'annulee'][$i % 4])
                ->setAdresseLivraison($client->getAdresse().', '.$client->getVille())
                ->setCreeLe(new \DateTimeImmutable('2025-02-01 +'.($i * 6).' days'));
            $total = 0.0;
            $nbLignes = 1 + ($i % 3);
            for ($j = 0; $j < $nbLignes; ++$j) {
                $biere = $liste[($i + $j) % 18]; // uniquement des bières actives
                $quantite = 1 + ($j % 4);
                $commande->ajouterLigne((new LigneCommande())
                    ->setBiere($biere)->setQuantite($quantite)->setPrixUnitaire($biere->getPrix()));
                $total += (float) $biere->getPrix() * $quantite;
            }
            $commande->setTotal(number_format($total, 2, '.', '')); // cohérent : total = somme des lignes
            $this->em->persist($commande);
        }

        if (!$traces) {
            return;
        }

        // Les deux commandes frauduleuses à 0 € (le total et le prix de ligne).
        $claire = $clients['claire.fournier'];
        $triple = $bieres['triple-du-vercors'];
        $ipa = $bieres['ipa-du-glandasse'];
        $blonde = $bieres['blonde-de-die'];

        $c412 = (new Commande())
            ->setReference('CMD-2026-0412')->setClient($claire)->setStatut('validee')
            ->setAdresseLivraison('1 rue des Remparts, Die')
            ->setCreeLe(new \DateTimeImmutable('2026-03-14 03:41:00'))
            ->setTotal('0.00'); // F5.1 : total forcé à 0
        $c412->ajouterLigne((new LigneCommande())->setBiere($triple)->setQuantite(2)->setPrixUnitaire($triple->getPrix()));
        $c412->ajouterLigne((new LigneCommande())->setBiere($ipa)->setQuantite(1)->setPrixUnitaire($ipa->getPrix()));
        $this->em->persist($c412);

        $c413 = (new Commande())
            ->setReference('CMD-2026-0413')->setClient($claire)->setStatut('validee')
            ->setAdresseLivraison('1 rue des Remparts, Die')
            ->setCreeLe(new \DateTimeImmutable('2026-03-14 03:44:00'))
            ->setTotal('0.00');
        // F5.2 : prix de ligne forcé à 0
        $c413->ajouterLigne((new LigneCommande())->setBiere($blonde)->setQuantite(6)->setPrixUnitaire($blonde->getPrix()));
        $this->em->persist($c413);
    }

    /** @param array<string, Biere> $bieres */
    private function chargerAvis(array $bieres, bool $traces): void
    {
        $liste = array_values($bieres);
        $noms = ['Julien', 'Camille', 'Fatou', 'Marc D.', 'Sophie', 'Karim', 'Élodie', 'Théo'];
        for ($i = 0; $i < 45; ++$i) {
            $biere = $liste[$i % 18];
            $avis = (new Avis())
                ->setBiere($biere)
                ->setAuteurNom($noms[$i % \count($noms)])
                ->setNote(2 + ($i % 4))
                ->setTitre('Très bonne bière')
                ->setCorps($i % 5 === 0 ? 'Parfaite. <strong>À recommander.</strong>' : 'Belle découverte, je recommande.')
                ->setSiteAuteur($i % 7 === 0 ? 'https://blog-biere.example' : null)
                ->setPublieLe(new \DateTimeImmutable('2025-09-01 +'.($i * 3).' days'));
            $this->em->persist($avis);
        }

        if (!$traces) {
            return;
        }

        // Avis 46 : la charge de défiguration, sur la Triple du Vercors.
        $this->em->persist((new Avis())
            ->setBiere($bieres['triple-du-vercors'])
            ->setAuteurNom('Houblon Noir')
            ->setNote(5)
            ->setTitre('Excellente <bière>')
            ->setCorps('Excellente bière.<img src=x onerror="document.querySelector(\'header\').innerHTML=\'&lt;div class=hn&gt;HOUBLON NOIR ÉTAIT LÀ&lt;/div&gt;\'">')
            ->setSiteAuteur('javascript:alert(document.cookie)')
            ->setPublieLe(new \DateTimeImmutable('2026-03-14 03:58:00')));

        // Avis 47 : la charge la plus simple, dans le nom de l'auteur.
        $this->em->persist((new Avis())
            ->setBiere($bieres['blonde-de-die'])
            ->setAuteurNom('"><script>alert(1)</script>')
            ->setNote(5)
            ->setTitre('Top')
            ->setCorps('Rien à dire.')
            ->setPublieLe(new \DateTimeImmutable('2026-03-14 03:59:00')));
    }

    private function chargerEtiquetteMalveillante(Client $support): void
    {
        $this->em->persist((new Etiquette())
            ->setNomOriginal('etiquette-2026.php.jpg')
            ->setChemin('etiquette-2026.php.jpg')
            ->setTypeMime('image/jpeg')
            ->setDeposePar($support)
            ->setDeposeLe(new \DateTimeImmutable('2026-03-14 03:27:00')));
    }

    private function slug(string $nom): string
    {
        $s = strtolower($nom);
        $s = str_replace(['à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ö', 'û', 'ü', 'ç', '—', '\''], ['a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u', 'c', '-', ' '], $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-');
    }
}
