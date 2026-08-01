<?php

namespace System\Tests\Modules\Utils\Models\Translation;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Translation\Locales;
use System\Modules\Utils\Models\Translation\Negotiator;

class NegotiatorTest extends TestCase
{
    private function locales(): Locales
    {
        return Locales::fromConfig([
            'url_format' => '{{country}}-{{language}}',
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv', 'en', 'ru']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['et', 'en']],
            ],
        ]);
    }

    public function testTheHighestQualityLanguageWins()
    {
        $locale = Negotiator::best('en;q=0.4,ru;q=0.9,lv;q=0.1', $this->locales());

        $this->assertEquals('lv_ru', $locale->key());
    }

    public function testEqualQualitiesKeepHeaderOrder()
    {
        $locale = Negotiator::best('ru,lv', $this->locales());

        $this->assertEquals('lv_ru', $locale->key());
    }

    public function testAnUnqualifiedTagOutranksAQualifiedOne()
    {
        $locale = Negotiator::best('ru;q=0.5,lv', $this->locales());

        $this->assertEquals('lv_lv', $locale->key());
    }

    /**
     * A browser asking for et-EE should land on the estonian site rather than on whichever
     * country happens to be configured first.
     */
    public function testAnExactRegionMatchIsPreferred()
    {
        $locale = Negotiator::best('et-EE', $this->locales());

        $this->assertEquals('ee_et', $locale->key());
    }

    public function testAnUnknownRegionStillMatchesTheLanguage()
    {
        $locale = Negotiator::best('en-GB', $this->locales());

        $this->assertEquals('en', $locale->language);
    }

    public function testZeroQualityIsNotAPreference()
    {
        $locale = Negotiator::best('ru;q=0,en;q=0.2', $this->locales());

        $this->assertEquals('en', $locale->language);
    }

    public function testAWildcardIsNotAMatch()
    {
        $this->assertNull(Negotiator::best('*', $this->locales()));
    }

    public function testNothingConfiguredMatchesNothing()
    {
        $this->assertNull(Negotiator::best('de-DE,fr;q=0.8', $this->locales()));
        $this->assertNull(Negotiator::best('', $this->locales()));
        $this->assertNull(Negotiator::best(null, $this->locales()));
    }

    public function testGarbageDoesNotThrow()
    {
        $this->assertNull(Negotiator::best(';;;,q=,,-,1234', $this->locales()));
    }
}
