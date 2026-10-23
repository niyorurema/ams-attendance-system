<?php

use App\Services\LdapAuthService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class LdapAuthServiceTest extends CIUnitTestCase
{
    public function testPasseAuServeurSecondaireLorsqueLePremierEchoue(): void
    {
        $service = new class extends LdapAuthService {
            public array $hosts = ['primaire', 'secondaire'];
            public array $essais = [];
            public function __construct() {}
            public function getLdapHosts(int $tenantId): array { return $this->hosts; }
            protected function getConfiguration(): array { return ['port' => 389, 'bind_dn' => 'service', 'bind_password' => 'secret', 'user_base_dn' => 'DC=test', 'user_filter' => '(sAMAccountName={username})', 'email' => 'mail', 'nom' => 'sn', 'prenom' => 'givenName', 'matricule' => 'employeeID']; }
            protected function getConfigurationForTenant(int $tenantId): array { return $this->getConfiguration(); }
            protected function ouvrirConnexion(string $host, int $port): mixed { $this->essais[] = $host; if ($host === 'primaire') { throw new RuntimeException('Timeout'); } return $host; }
            protected function configurerConnexion(mixed $connexion): void {}
            protected function effectuerBind(mixed $connexion, string $dn, string $password): bool { return true; }
            protected function rechercherUtilisateur(mixed $connexion, string $baseDn, string $filtre): ?array { return ['dn' => 'CN=Jean', 'mail' => ['jean@obr.gov.bi'], 'sn' => ['Dupont'], 'givenName' => ['Jean'], 'employeeID' => ['42']]; }
            protected function fermerConnexion(mixed $connexion): void {}
            protected function journaliser(int $tenantId, string $email, string $statut, string $message): void {}
        };
        $utilisateur = $service->authenticate(1, 'jdupont', 'mot-de-passe');
        $this->assertSame(['primaire', 'secondaire'], $service->essais);
        $this->assertSame('jean@obr.gov.bi', $utilisateur['email']);
    }

    public function testMappeLesAttributsEtRespecteLaCasseDesCles(): void
    {
        $service = new class extends LdapAuthService { public function __construct() {} };
        $this->assertSame('A123', $service->getLdapAttr(['employeeID' => ['A123']], 'employeeid'));
        $this->assertSame('Dupont', $service->getLdapAttr(['sn' => ['Dupont']], 'sn'));
    }

    public function testLeFiltreDeRechercheEchappeLesCaracteresLDAP(): void
    {
        $service = new class extends LdapAuthService {
            public function __construct() {}
            public function filtre(string $nom): string { return $this->filtreTousTokens('displayName', [$nom]); }
            public function filtreRecherche(string $nom, string $prenom, string $matricule): string
            {
                return $this->filtreRechercheEmploye($nom, $prenom, $matricule, 'employeeID');
            }
            public function filtreEmailObr(): string { return $this->filtreComptesEmailObr(); }
        };

        $filtre = $service->filtre('Nom*)(objectClass=*)');

        $this->assertStringContainsString('\\2a', $filtre);
        $this->assertStringContainsString('\\28objectClass=', $filtre);
        $this->assertStringContainsString('\\29', $filtre);
        $this->assertStringNotContainsString('(objectClass=*)', $filtre);

        $recherche = $service->filtreRecherche('Nom', 'Prénom', 'A123');
        $this->assertStringContainsString('(objectClass=user)', $recherche);
        $this->assertStringContainsString('(employeeID=A123)', $recherche);
        $this->assertStringContainsString('(displayName=*prenom*)', $recherche);
        $this->assertSame('(&(objectClass=user)(mail=*@obr.gov.bi))', $service->filtreEmailObr());
    }

    public function testUneCorrespondanceParTokensIgnoreLesAccentsEtTolereLesNomsComposes(): void
    {
        $service = new class extends LdapAuthService {
            public function __construct() {}
            public function correspond(array $entree, string $nom, string $prenom): bool
            {
                return $this->correspondanceNomParTokens($entree, $nom, $prenom);
            }
        };

        $this->assertTrue($service->correspond(['displayName' => ['Jean Marie Dupont']], 'Dupont', 'Jean'));
        $this->assertTrue($service->correspond(['displayName' => ['Jean Dupont']], 'Dupont', 'Jean'));
        $this->assertFalse($service->correspond(['displayName' => ['Jean Dupont-Durand']], 'Martin', 'Jean'));
    }
}
