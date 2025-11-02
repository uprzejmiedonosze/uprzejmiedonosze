<?php

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {

    /**
     * Test sprawdza czy stała SEXSTRINGS posiada identyczny zestaw kluczy
     * we wszystkich swoich kategoriach ('f', 'm', '?')
     */
    public function testSexstringsHasIdenticalKeysInAllCategories(): void {
        // Sprawdź czy stała SEXSTRINGS istnieje
        $this->assertTrue(defined('SEXSTRINGS'), 'Stała SEXSTRINGS nie jest zdefiniowana');

        $sexstrings = SEXSTRINGS;

        // Sprawdź czy wszystkie wymagane kategorie istnieją
        $expectedCategories = ['f', 'm', '?'];
        foreach ($expectedCategories as $category) {
            $this->assertArrayHasKey($category, $sexstrings, "Kategoria '$category' nie istnieje w SEXSTRINGS");
            $this->assertIsArray($sexstrings[$category], "Kategoria '$category' nie jest tablicą");
        }

        // Pobierz klucze z każdej kategorii
        $keysF = array_keys($sexstrings['f']);
        $keysM = array_keys($sexstrings['m']);
        $keysUnknown = array_keys($sexstrings['?']);

        // Posortuj klucze dla lepszego porównania
        sort($keysF);
        sort($keysM);
        sort($keysUnknown);

        // Sprawdź czy wszystkie kategorie mają identyczne klucze
        $this->assertEquals(
            $keysF,
            $keysM,
            "Klucze w kategorii 'f' różnią się od kluczy w kategorii 'm'.\n" .
                "Klucze tylko w 'f': " . implode(', ', array_diff($keysF, $keysM)) . "\n" .
                "Klucze tylko w 'm': " . implode(', ', array_diff($keysM, $keysF))
        );

        $this->assertEquals(
            $keysF,
            $keysUnknown,
            "Klucze w kategorii 'f' różnią się od kluczy w kategorii '?'.\n" .
                "Klucze tylko w 'f': " . implode(', ', array_diff($keysF, $keysUnknown)) . "\n" .
                "Klucze tylko w '?': " . implode(', ', array_diff($keysUnknown, $keysF))
        );

        $this->assertEquals(
            $keysM,
            $keysUnknown,
            "Klucze w kategorii 'm' różnią się od kluczy w kategorii '?'.\n" .
                "Klucze tylko w 'm': " . implode(', ', array_diff($keysM, $keysUnknown)) . "\n" .
                "Klucze tylko w '?': " . implode(', ', array_diff($keysUnknown, $keysM))
        );
    }
}
