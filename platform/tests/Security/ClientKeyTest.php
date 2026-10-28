<?php

namespace App\Tests\Security;

use App\Security\ClientKey;
use PHPUnit\Framework\TestCase;

final class ClientKeyTest extends TestCase
{
    public function testUneMachineIpv6EstCompteeParSonBloc64(): void
    {
        $this->assertSame('2001:db8:abcd:12::/64', ClientKey::of('2001:db8:abcd:12:1::5'));
        $this->assertSame(ClientKey::of('2001:db8:abcd:12:1::5'), ClientKey::of('2001:db8:abcd:12:ffff::9'), 'Changer d\'adresse dans le bloc ne remet pas le compteur à zéro.');
        $this->assertNotSame(ClientKey::of('2001:db8:abcd:12::1'), ClientKey::of('2001:db8:abcd:13::1'));
    }

    public function testIpv4TelleQuelle(): void
    {
        $this->assertSame('203.0.113.7', ClientKey::of('203.0.113.7'));
        $this->assertSame('203.0.113.7', ClientKey::of('::ffff:203.0.113.7'), 'Une IPv4 vue en IPv6 reste une IPv4.');
        $this->assertSame('inconnu', ClientKey::of(null));
    }
}
