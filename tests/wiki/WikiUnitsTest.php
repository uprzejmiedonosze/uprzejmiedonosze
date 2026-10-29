<?php

namespace UprzejmieDonosze\Tests\Wiki;

use UprzejmieDonosze\Tests\WikiTestCase;

class WikiUnitsTest extends WikiTestCase
{
    public function testEveryUnitHasOneUniqueSlug(): void
    {
        $units = \wiki\units();
        $this->assertGreaterThan(700, count($units));
        foreach ($units as $slug => $entry) {
            $this->assertSame($slug, $entry['slug']);
            $this->assertTrue(\wiki\isValidPath("sluzby/$slug"), $slug);
            $this->assertArrayHasKey($entry['type'], \wiki\UNIT_TYPES);
        }
    }

    public function testSlugsComeFromUnitNames(): void
    {
        $units = \wiki\units();
        $this->assertArrayHasKey('straz-miejska-w-legnicy', $units);
        // Policja: klucz to gmina (Kobierzyce), slug z nazwy komisariatu
        $this->assertArrayHasKey('kp-wroclaw-krzyki', $units);
        $this->assertContains('kobierzyce', $units['kp-wroclaw-krzyki']['keys']);
        // Oddziały SM Warszawy: pierwsza linia adresu nie mówi czyje — doklejona druga
        $this->assertArrayHasKey('i-oddzial-terenowy-strazy-miejskiej-m-st-warszawy', $units);
    }

    public function testKeysWithSameAddressShareOnePage(): void
    {
        $entry = \wiki\unitBySlug('kp-w-gluszycy');
        $this->assertNotNull($entry);
        $this->assertEqualsCanonicalizing(['głuszyca', 'jugowice'], $entry['keys']);
        $this->assertCount(2, $entry['emails']);
    }

    public function testServedMunicipalitiesAndLookupByChildKey(): void
    {
        $entry = \wiki\unitByKey('sm', 'bielany');
        $this->assertNotNull($entry, 'Bielany is served by OT5 (parent in sm.json)');
        $this->assertSame('v-oddzial-terenowy-strazy-miejskiej-m-st-warszawy', $entry['slug']);
        $this->assertContains('Bielany', $entry['served'], 'served areas are named after their keys, not the inherited city');
        $this->assertNotContains('Warszawa', $entry['served']);
        $this->assertEmpty(array_filter($entry['served'], fn($area) => str_contains($area, '_')), 'internal keys like warszawa_ot5 are not areas');

        $krzyki = \wiki\unitBySlug('kp-wroclaw-krzyki');
        $this->assertContains('Kobierzyce', $krzyki['served'], "the unit's own keys are areas it serves too");

        $myszkow = array_filter(array_keys(\wiki\units()), fn($slug) => str_starts_with($slug, 'straz-miejska-w-myszkowie'));
        $this->assertSame(['straz-miejska-w-myszkowie'], array_values($myszkow), 'duplicate "Myszków," key is an alias now');
        $this->assertNull(\wiki\unitByKey('sm', 'nie-ma-takiej'));
    }

    public function testUnitEmbed(): void
    {
        $result = \wiki\renderer()->render("[[jednostka:sm/legnica]]\n\n[[jednostka:police/Kobierzyce]]\n\n[[jednostka:sm/nie-ma]]\n");
        $this->assertStringContainsString('href="/wiki/sluzby/straz-miejska-w-legnicy"', $result->html);
        $this->assertStringContainsString('href="/wiki/sluzby/kp-wroclaw-krzyki"', $result->html);
        $this->assertSame(['Nieznany blok [[jednostka:sm/nie-ma]].'], $result->errors);
    }

    public function testNotesOnlyForExistingUnits(): void
    {
        $user = new \user\User();
        $user->data->email = 'wiki-units@example.com';
        $user->data->name = 'Ala';
        $user->data->address = 'Wrocław';
        $user->appsCount = 1;

        \wiki\save($user, 'sluzby/straz-miejska-w-legnicy', 'Straż Miejska w Legnicy', 'Telefon dyżurny: 986', '', null);
        $this->assertNotNull(\wiki\getPage('sluzby/straz-miejska-w-legnicy'));

        $this->expectException(\ValidationException::class);
        \wiki\save($user, 'sluzby/wymyslona-jednostka', 'X', 'x', '', null);
    }
}
