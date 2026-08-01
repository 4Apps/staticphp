<?php

namespace System\Tests\Modules\Utils\Models\Translation;

use PDO;
use PHPUnit\Framework\TestCase;
use System\Modules\Utils\Models\Db;
use System\Modules\Utils\Models\Translation\Catalog;
use System\Modules\Utils\Models\Translation\Locales;
use System\Modules\Utils\Models\Translation\Store;

/**
 * A real database for the translation tests.
 *
 * SQLite for the same reason the migrations tests use it: it is a real driver with real
 * constraint enforcement in a temp file, so the unique constraint that stops duplicate
 * translation rows is actually exercised rather than assumed. The postgres and mysql
 * spellings of the same statements are covered by scripts/i18n_integration.php.
 */
abstract class SqliteCase extends TestCase
{
    protected string $dbFile;
    protected string $connection;
    protected Store $store;
    protected Catalog $catalog;
    protected Locales $locales;
    protected array $lines = [];

    /**
     * The shipped sqlite schema, so the tests fail if it and the code disagree.
     */
    protected const SCHEMA = __DIR__ . '/../../../../../Modules/Utils/Files/I18n/install.sqlite.sql';

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $this->dbFile = sys_get_temp_dir() . "/sp_i18n_{$suffix}.sqlite";
        $this->connection = "i18n_test_{$suffix}";

        $pdo = Db::init($this->connection, [
            'string' => "sqlite:{$this->dbFile}",
            'username' => '',
            'password' => '',
            'wrap_column' => '"',
        ]);

        $pdo->exec((string) file_get_contents(self::SCHEMA));

        $this->store = new Store($this->connection, '', [], true);
        $this->catalog = new Catalog($this->store, 'none');
        $this->locales = Locales::fromConfig($this->config());
    }

    protected function tearDown(): void
    {
        Db::close($this->connection);

        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /**
     * @return array Contents of config['i18n'] the tests run against
     */
    protected function config(): array
    {
        return [
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv', 'en', 'ru']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['et', 'en']],
            ],
            'url_format' => '{{country}}-{{language}}',
            'redirect' => true,
            'negotiate' => true,
            'auto_register' => true,
            'missing_suffix' => '*',
            'fallback' => true,
            'strict' => true,
            'set_locale' => false,
            'cache' => 'none',
            'cache_prefix' => 'language_',
            'cache_ttl' => null,
            'db_config' => $this->connection,
            'db_scheme' => '',
            'tables' => [],
        ];
    }

    protected function rowCount(string $table): int
    {
        return (int) Db::query("SELECT COUNT(*) AS total FROM {$table}", [], $this->connection)
            ->fetch(PDO::FETCH_ASSOC)['total'];
    }

    protected function value(string $key, string $languageKey): ?string
    {
        $row = Db::query(
            'SELECT t.value AS translation FROM i18n_translations t'
            . ' JOIN i18n_keys k ON k.id = t.key_id'
            . ' WHERE k.key_hash = ? AND t.language = ?',
            [hash('sha256', $key), $languageKey],
            $this->connection
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row['translation'];
    }

    protected function collect(): callable
    {
        return function (string $line = ''): void {
            $this->lines[] = $line;
        };
    }

    protected function outputText(): string
    {
        return implode("\n", $this->lines);
    }
}
