<?php

namespace System\Tests\Modules\Utils\Models\Translation;

use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Translation\Locales;
use System\Modules\Utils\Models\Translation\TranslationError;

class LocalesTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'url_format' => '{{country}}-{{language}}',
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv', 'en', 'ru']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['et', 'en']],
            ],
        ], $overrides);
    }

    public function testEveryPairingIsExpanded()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertCount(5, $locales->all());
        $this->assertEquals('lv_lv', $locales->default()->key());
    }

    public function testTheFirstLanguageOfEachCountryIsItsDefault()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertTrue($locales->find('lv', 'lv')->isDefault);
        $this->assertFalse($locales->find('lv', 'en')->isDefault);
        $this->assertTrue($locales->find('ee', 'et')->isDefault);
    }

    public function testLookupByPrefixAndByKey()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('lv_ru', $locales->byPrefix('lv-ru')->key());
        $this->assertEquals('lv-ru', $locales->byKey('lv_ru')->urlPrefix);
        $this->assertNull($locales->byPrefix('de-de'));
        $this->assertNull($locales->byKey('de_de'));
    }

    /**
     * The ICU locale is derived rather than configured, so english on the latvian site
     * formats numbers and dates the latvian way while reading in english.
     */
    public function testTheIcuLocaleIsDerivedFromLanguageAndCountry()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('en_LV', $locales->find('lv', 'en')->icuLocale);
        $this->assertEquals('et_EE', $locales->find('ee', 'et')->icuLocale);
    }

    public function testACountryCanOverrideItsIcuLocale()
    {
        $locales = Locales::fromConfig($this->config([
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv'], 'locale' => 'lv_LV@currency=EUR'],
            ],
        ]));

        $this->assertEquals('lv_LV@currency=EUR', $locales->default()->icuLocale);
    }

    public function testHreflangIsTheLanguageAndTheRegion()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('en-LV', $locales->find('lv', 'en')->hreflang());
    }

    public function testFallbackChainPrefersTheOwnCountryBeforeTheGlobalDefault()
    {
        $locales = Locales::fromConfig($this->config());

        $chain = array_map(
            fn($locale): string => $locale->key(),
            $locales->fallbackChain($locales->byKey('ee_en'))
        );

        $this->assertEquals(['ee_en', 'ee_et', 'lv_lv'], $chain);
    }

    public function testFallbackChainOfTheDefaultIsJustItself()
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertCount(1, $locales->fallbackChain($locales->byKey('lv_lv')));
    }

    /**
     * Two countries resolving to one prefix makes the url ambiguous, and whichever came
     * second would simply never be reachable.
     */
    public function testDuplicatePrefixesAreRejected()
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig($this->config([
            'url_format' => '{{language}}',
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['en']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['en']],
            ],
        ]));
    }

    public function testAnEmptyConfigurationIsRejected()
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig(['available' => []]);
    }

    public function testACountryWithoutLanguagesIsRejected()
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig($this->config([
            'available' => [['name' => 'Latvia', 'code' => 'lv', 'languages' => []]],
        ]));
    }
}
