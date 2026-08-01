<?php

namespace System\Tests\Modules\Utils\Models\Translation;

use System\Modules\Utils\Models\Db;
use System\Modules\Utils\Models\Translation\Commands;

class CommandsTest extends SqliteCase
{
    private Commands $commands;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/sp_i18n_cmd_' . bin2hex(random_bytes(6));
        mkdir($this->workDir);

        $this->commands = new Commands($this->store, $this->locales, $this->config(), $this->collect());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    private function reset(): void
    {
        $this->lines = [];
    }

    private function seed(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');
        $this->store->setTranslation('Log in', 'lv_en', 'Log in');
        $this->store->setTranslation('Sign out', 'lv_lv', 'Sign out*');
    }

    /*
    | status
    */

    public function testStatusReportsNothingRegisteredYet()
    {
        $this->assertEquals(0, $this->commands->status());
        $this->assertStringContainsString('Nothing registered yet', $this->outputText());
    }

    public function testStatusCountsTranslatedAndMissing()
    {
        $this->seed();

        $this->assertEquals(0, $this->commands->status());

        $output = $this->outputText();
        $this->assertStringContainsString('keys:   2', $output);
        $this->assertStringContainsString('lv_lv', $output);
        $this->assertStringContainsString('en_LV', $output);
    }

    /**
     * The auto-registered placeholder is the source text with a marker on it, which is
     * exactly what "nobody has translated this" looks like.
     */
    public function testCheckFailsWhileAnythingIsUntranslated()
    {
        $this->seed();

        $this->assertEquals(1, $this->commands->status(true));
    }

    public function testCheckPassesOnceEverythingIsTranslated()
    {
        foreach ($this->locales->all() as $locale) {
            $this->store->setTranslation('Log in', $locale->key(), 'x');
        }

        $this->assertEquals(0, $this->commands->status(true));
    }

    public function testStatusNamesLanguagesThatHaveRowsButNoConfiguration()
    {
        $this->store->setTranslation('Log in', 'de_de', 'Anmelden');

        $this->commands->status();

        $this->assertStringContainsString('de_de', $this->outputText());
    }

    /*
    | missing
    */

    public function testMissingListsThePlaceholdersAndTheGaps()
    {
        $this->seed();

        $this->commands->missing('lv_lv');
        $output = $this->outputText();

        $this->assertStringContainsString('# lv_lv (1)', $output);
        $this->assertStringContainsString('Sign out', $output);
        $this->assertStringNotContainsString('Pieslēgties', $output);
    }

    public function testMissingCoversEveryLanguageByDefault()
    {
        $this->seed();

        $this->commands->missing();
        $output = $this->outputText();

        foreach ($this->locales->all() as $locale) {
            $this->assertStringContainsString('# ' . $locale->key(), $output);
        }
    }

    /*
    | set / keys
    */

    public function testSetWritesATranslation()
    {
        $this->assertEquals(0, $this->commands->set('lv_lv', 'Log in', 'Pieslēgties'));
        $this->assertEquals('Pieslēgties', $this->value('Log in', 'lv_lv'));
    }

    public function testKeysListsEverything()
    {
        $this->seed();
        $this->reset();

        $this->commands->keys();

        $this->assertEquals(['Log in', 'Sign out'], $this->lines);
    }

    /*
    | export / import
    */

    public function testExportAndImportCsvRoundTrip()
    {
        $this->seed();
        $file = $this->workDir . '/lv.csv';

        $this->assertEquals(0, $this->commands->export('lv_lv', 'csv', $file));
        $this->assertStringContainsString('Pieslēgties', (string) file_get_contents($file));

        $this->assertEquals(0, $this->commands->import('lv_ru', $file, 'csv'));
        $this->assertEquals('Pieslēgties', $this->value('Log in', 'lv_ru'));
    }

    public function testExportAndImportJsonRoundTrip()
    {
        $this->seed();
        $file = $this->workDir . '/lv.json';

        $this->assertEquals(0, $this->commands->export('lv_lv', 'json', $file));
        $this->assertEquals('Pieslēgties', json_decode((string) file_get_contents($file), true)['Log in']);

        $this->assertEquals(0, $this->commands->import('lv_ru', $file, 'auto'));
        $this->assertEquals('Pieslēgties', $this->value('Log in', 'lv_ru'));
    }

    public function testImportLeavesExistingTranslationsAloneByDefault()
    {
        $this->seed();
        $this->store->setTranslation('Log in', 'lv_ru', 'Войти');

        $file = $this->workDir . '/lv.csv';
        $this->commands->export('lv_lv', 'csv', $file);
        $this->reset();

        $this->commands->import('lv_ru', $file, 'csv');

        $this->assertEquals('Войти', $this->value('Log in', 'lv_ru'));
        $this->assertStringContainsString('1 left alone', $this->outputText());
    }

    public function testImportOverwritesWhenAsked()
    {
        $this->seed();
        $this->store->setTranslation('Log in', 'lv_ru', 'Войти');

        $file = $this->workDir . '/lv.csv';
        $this->commands->export('lv_lv', 'csv', $file);

        $this->commands->import('lv_ru', $file, 'csv', true);

        $this->assertEquals('Pieslēgties', $this->value('Log in', 'lv_ru'));
    }

    public function testImportRefusesAMissingFile()
    {
        $this->assertEquals(2, $this->commands->import('lv_lv', $this->workDir . '/nope.csv'));
    }

    public function testExportRefusesAnUnknownFormat()
    {
        $this->assertEquals(2, $this->commands->export('lv_lv', 'xml'));
    }

    /*
    | scan / prune
    */

    public function testScanSeparatesNewKeysFromUnusedOnes()
    {
        $this->seed();
        file_put_contents($this->workDir . '/page.php', '<?php _(\'Log in\'); _(\'Register\');');

        $this->commands->scan([$this->workDir]);
        $output = $this->outputText();

        $this->assertStringContainsString('in source, not registered (1)', $output);
        $this->assertStringContainsString('Register', $output);
        $this->assertStringContainsString('registered, not found in source (1)', $output);
        $this->assertStringContainsString('Sign out', $output);
    }

    public function testScanCanRegisterWhatItFound()
    {
        file_put_contents($this->workDir . '/page.php', '<?php _(\'Register\');');

        $this->commands->scan([$this->workDir], true);

        $this->assertEquals('Register*', $this->value('Register', 'lv_lv'));
    }

    public function testPruneAsksBeforeDeleting()
    {
        $this->seed();

        $this->commands->prune([$this->workDir], false, fn(): string => "n\n");

        $this->assertEquals(2, $this->rowCount('i18n_keys'));
        $this->assertStringContainsString('Nothing deleted', $this->outputText());
    }

    public function testPruneDeletesKeysAndTheirTranslations()
    {
        $this->seed();
        file_put_contents($this->workDir . '/page.php', '<?php _(\'Log in\');');

        $this->commands->prune([$this->workDir], true, fn(): string => '');

        $this->assertEquals(1, $this->rowCount('i18n_keys'));
        $this->assertEquals(2, $this->rowCount('i18n_translations'));
        $this->assertNull($this->value('Sign out', 'lv_lv'));
    }

    public function testPruneWithNothingToDoSaysSo()
    {
        $this->seed();
        file_put_contents($this->workDir . '/page.php', '<?php _(\'Log in\'); _(\'Sign out\');');

        $this->commands->prune([$this->workDir], true, fn(): string => '');

        $this->assertStringContainsString('Nothing to prune', $this->outputText());
        $this->assertEquals(2, $this->rowCount('i18n_keys'));
    }

    /*
    | clear
    */

    public function testClearMarksALanguageStale()
    {
        $this->store->markFresh('lv_lv');
        $this->assertTrue($this->store->isFresh('lv_lv'));

        $this->commands->clear('lv_lv');

        $this->assertFalse($this->store->isFresh('lv_lv'));
    }

    public function testClearWithoutALanguageMarksEverything()
    {
        $this->store->markFresh('lv_lv');
        $this->store->markFresh('lv_en');

        $this->commands->clear();

        $this->assertFalse($this->store->isFresh('lv_lv'));
        $this->assertFalse($this->store->isFresh('lv_en'));
    }

    /*
    | install
    */

    public function testInstallCopiesTheSchemaForThisDriver()
    {
        $result = $this->commands->install(false, $this->workDir, dirname(self::SCHEMA), 1785000000);

        $this->assertEquals(0, $result);

        $written = glob($this->workDir . '/*-i18n-install.sql');
        $this->assertCount(1, $written);
        $this->assertStringContainsString('CREATE TABLE i18n_keys', (string) file_get_contents($written[0]));
        $this->assertStringContainsString('migrate apply', $this->outputText());
    }

    /**
     * The old schema only ever shipped for postgres, so there is nothing to upgrade from
     * anywhere else - and saying that is better than emitting something that does not apply.
     */
    public function testInstallHasNoUpgradePathForSqlite()
    {
        $this->assertEquals(2, $this->commands->install(true, $this->workDir, dirname(self::SCHEMA), 1785000000));
        $this->assertStringContainsString('only ever shipped for postgres', $this->outputText());
    }

    public function testInstallRefusesToOverwrite()
    {
        $this->commands->install(false, $this->workDir, dirname(self::SCHEMA), 1785000000);
        $this->reset();

        $this->assertEquals(1, $this->commands->install(false, $this->workDir, dirname(self::SCHEMA), 1785000000));
        $this->assertStringContainsString('already exists', $this->outputText());
    }

    /*
    | Missing schema
    */

    public function testEveryCommandExplainsAMissingSchema()
    {
        Db::query('DROP TABLE i18n_translations', [], $this->connection);
        Db::query('DROP TABLE i18n_keys', [], $this->connection);

        $store = new \System\Modules\Utils\Models\Translation\Store($this->connection, '', [], false);
        $commands = new Commands($store, $this->locales, $this->config(), $this->collect());

        $this->assertEquals(1, $commands->status());
        $this->assertStringContainsString('staticphp i18n install', $this->outputText());
    }
}
