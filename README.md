[![packagist](https://img.shields.io/packagist/v/4apps/staticphp.svg)](https://packagist.org/packages/4apps/staticphp) [![license](https://img.shields.io/packagist/l/4apps/staticphp.svg)](LICENSE)

![logo](resources/logo_50.png)

# StaticPHP

Simple, modular php framework.

### Requirements

-   PHP 8.4+
-   Twig 3.0+ (optional, see [Packages](#packages))

### Packages

StaticPHP ships as two composer packages, the way `laravel/laravel` and
`laravel/framework` are split:

| Package                | Type      | Contents                                                       |
| ---------------------- | --------- | -------------------------------------------------------------- |
| `4apps/staticphp-core` | `library` | The framework. `composer require` this from an application.      |
| `4apps/staticphp`      | `project` | This skeleton. `composer create-project` this to start a site.   |

An existing site does not vendor a copy of the framework any more:

```bash
composer require 4apps/staticphp-core
```

Everything the framework provides lives under the `StaticPHP\` namespace and is resolved
by composer's PSR-4 autoloader. Application code keeps its own conventions - a module name
is its own namespace root (`Pasta\Controllers\Quality`), resolved at runtime against the
application that served the request. See [Applications and modules](#applications-and-modules).

Twig is a `suggest` of the core package rather than a requirement, so an api-only
deployment can leave it out entirely. See [Views](#views).

Upgrading from 1.x: see [UPGRADE.md](UPGRADE.md). Earlier history is in
[CHANGELOG.md](CHANGELOG.md).

### Installation

There are two ways to start using StaticPHP framework:

1. Docker <- Suggested one
2. Composer

**1. Using Docker**

1. `docker compose build develop`
2. `docker compose up -d --remove-orphans develop`
3. `./staticphp app add Application` - the container starts with no application; see
   [Presets](#presets). The php server picks it up on its own once it exists.
4. Open in vscode using [Remote containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)

**2. Using composer**

Run `composer create-project 4apps/staticphp ./` for stable version and `composer create-project 4apps/staticphp ./ dev-develop` for latest development version from github. Composer will install all the dependecies for you.

Afterwards composer runs `scripts/post_create_project.php`, which asks which
[preset](#presets) to start from, lays the application down as `src/Application`, and creates
the `.env` files from their `.example` counterparts. Existing files are left alone, so
`composer setup` can be run again later without losing anything.

Note that `src/` is empty in a checkout of the skeleton itself: an application is generated
from `presets/`, not tracked, so there is only ever one copy of it to maintain and
`git pull` can never overwrite yours. `composer setup` creates one. In a project generated
by `composer create-project` the application is tracked as usual.

_[How to install composer?](https://getcomposer.org/doc/00-intro.md)_

**Npm for SCSS and Javascript**

Install node and npm
run `npm install`

_[How to install node?](https://nodejs.org/en/download/package-manager/)_

_\* Remember to set correct permissions for the Cache directory. For example: `chown www-data:www-data ./src/Application/Cache/` or `chmod 777 ./src/Application/Cache/`_

The quickest way to run the project is php's built in server:

    php -S 0.0.0.0:8081 -t src/Application/Public

Open **127.0.0.1:8081** and the StaticPHP first page should show up. `npm start` does the
same thing.

Running under the cli server turns debugging on by default; configure that in
`src/Application/Config/Config.php` through `$config['environment']` and `$config['debug']`.

_\* Take a look at the home controller in `src/Application/Modules/Defaults/Controllers/Welcome.php` and the views in `src/Application/Modules/Defaults/Views/` for basic framework usage._

### Presets

An application starts from a preset - a pair of directory trees under `presets/` copied on
top of each other, `_base` then the flavour:

| Preset  | What you get                                                                        |
| ------- | ----------------------------------------------------------------------------------- |
| `twig`  | Server rendered views, the `Defaults` module, the menu components                     |
| `react` | A react SPA plus a json api: `Spa` renders the shell, `Api` answers it, csrf wired up  |

`composer create-project` asks which one to start with, and takes `twig` when it cannot ask -
so a scripted or CI install never blocks. `SP_PRESET=react` answers it up front.

Afterwards, add more with the cli:

    ./staticphp app add Shop --preset=react

Presets are plain directories. A preset's `preset.json` names the npm packages it needs,
which are merged into the root `package.json`; everything else is just files. Adding one of
your own means adding a directory - there is no registry to update.

The react preset's shell is a twig view, so php still owns the page: it injects the csrf
token and the api base into `<div id="react-root" data-…>`, which means the first POST needs
no round trip to fetch a token. State changing requests are checked against
`StaticPHP\Utils\Models\Csrf` and the front end sends `X-CSRF-Token`. Every client side
route falls through to the same shell, so deep links and reloads work.

### Upgrading

A project owns its files outright once `create-project` has run, so picking up a later
release is a merge rather than a download:

    ./staticphp upgrade                 # newest release
    ./staticphp upgrade --dry-run       # just show the plan
    ./staticphp upgrade --to=v2.1.0

It compares three trees - what you have, the release you are on, and the release you are
going to - and only asks about files that changed on **both** sides. Everything else is
decided without you: untouched files take the new version, files only you changed are left
alone. In practice that is a couple of questions rather than sixty.

Applications go with it. `presets/` moving would otherwise never reach an
already-scaffolded `src/Application`, so one run does the skeleton and every application
the manifest knows about, onto the same tag. `./staticphp app upgrade Shop` does one on its
own.

For a collision you get `[d] diff  [m] merge  [t] take theirs  [k] keep mine  [s] skip`.
`m` is a real three-way merge and can leave conflict markers, which are listed again at the
end and set a non-zero exit code. `s` defers - the file is offered again next time rather
than quietly absorbed. `--yes` applies the silent set and defers every collision, which is
what you want in a script.

Two things it will not do. It refuses on a dirty worktree, because `git checkout .` is the
undo and there is no backup logic behind it. And it never touches `README.md`, `.version`,
`.env`, `src/` (that is what `app upgrade` is for) or the lock files - `upgrade.json` holds
that list, and `composer update` / `npm install` stay yours to run.

`.staticphp/manifest.json` records which release each tree came from. Commit it. If it goes
missing, or the project predates all of this, the upgrade matches your files against the
published tags and tells you what it found before touching anything - so an older project
is a normal upgrade with one extra confirmation.

### Assets

All assets live in `src/Application/Public/assets/`. StaticPHP uses npm to handle css (SCSS)
and js, but you can also do it manually.

### Cron

`./staticphp` is the cli entry point - it takes the url a browser would request:

    ./staticphp /defaults/console/refresh
    ./staticphp /defaults/console/refresh --query "since=yesterday"

From cron, give it the absolute path: `/srv/sites/example.com/staticphp /defaults/console/refresh`.

### Applications and modules

A front controller declares where its application is, and everything else derives from
that:

```php
require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

`APP_PATH`, `APP_MODULES_PATH`, `BASE_PATH` and `VENDOR_PATH` are each derived from
`PUBLIC_PATH` and each can be defined ahead of time instead. `SP_PATH` is the framework's
own directory, worked out from its own location, so it is right whether the framework was
installed by composer or is a source checkout.

Because the application root is decided per request, one repository can serve several
applications:

    src/site1/Public/index.php    src/site1/Modules/Pasta/...
    src/site2/Public/index.php    src/site2/Modules/Pasta/...

Create them with the cli - see [Presets](#presets) for what each one starts from:

    ./staticphp app add Site2 --preset=twig
    ./staticphp app list

Nothing has to be registered afterwards. The toolchain finds applications by globbing
`src/*`: rspack builds one compiler per application into that application's own
`assets/dist`, `npm run typecheck` runs each `src/<App>/tsconfig.json`, and `composer test`
runs each `src/<App>/phpunit.xml`. The per-application tsconfig is what makes `base/*`
resolve to that application's own `assets/src/base/ts` - a single config listing every
application resolves the alias once, globally, so every application would be typechecked
against the first one's copy while the bundler used the right one.

Composer and npm dependencies stay shared in the root `composer.json` and `package.json`;
each application imports only what it uses.

Point each vhost at the matching `index.php` and each application sees only its own
modules. `Pasta\Controllers\Quality` means a different class in each, which is why module
namespaces are resolved by the framework's own autoloader rather than by PSR-4 - composer's
map is static and global and cannot express it.

To reach another registered tree, name it in `$config['module_paths']`:

```php
$config['module_paths'] = [
    'site2' => BASE_PATH . '/site2/Modules',
];
```

The value is the directory holding the modules. `staticphp` is a reserved name that always
resolves to the framework's own modules, so its shipped configs and helpers are addressable
with no configuration at all:

```php
$config['autoload_helpers'] = ['Bootstrap', 'staticphp/Utils/Helpers'];
$config['autoload_configs'] = ['App', 'staticphp/Utils/Db'];
```

### Views

`4apps/staticphp-core` suggests `twig/twig` rather than requiring it. That matters for
api-only deployments: composer's `files` autoload is eager, and twig with its symfony
polyfills loads eight files on every request whether or not a template is ever rendered.
Leaving it out of the application's `composer.json` is what actually removes that cost -
lazy class loading alone would not.

Without twig installed the view engine is simply not built, and `Load::view()` falls back
to plain php templates under the modules directory. `$config['disable_twig'] = true` skips
building the environment for installs that do have the library.

This skeleton requires twig, so `composer create-project` still gives a working app with
views out of the box.

### Tests and code style

Everything that gates a merge lives in one script, used identically from the shell, the
pre-commit hook and CI:

    ./scripts/code_tests.bash          # everything
    ./scripts/code_tests.bash php      # php only: lint, phpcs, phpstan, the Application suite
    ./scripts/code_tests.bash js       # js only: tsc, eslint, prettier

It needs the composer dev dependencies (`composer install`) and, for the js half,
`npm ci`.

Static analysis is phpstan at the level set in `phpstan.neon`, run over `src`, `scripts` and
the `staticphp` cli entry point (`composer stan` on its own). There is no baseline file here:
the skeleton is small enough to keep clean, and a generated application inherits this config
as its starting point. Core carries a baseline because it was analysed for the first time
against an existing tree.

The framework's own suite lives in
[staticphp-core](https://github.com/gintsmurans/staticphp-core) and runs there. This
repository's suite covers the skeleton: that the front controller, the config and the demo
module still work against whichever version of the package is installed.

**Working on the framework and the skeleton together.** Point composer at a local checkout
instead of Packagist:

    git clone git@github.com:gintsmurans/staticphp-core.git ../staticphp-core
    composer config repositories.core path ../staticphp-core
    composer require 4apps/staticphp-core:dev-develop

Composer symlinks it into `vendor/`, so edits in the core checkout are live here. Undo both
halves before committing - `composer require` rewrote the constraint as well as adding the
repository:

    composer config --unset repositories.core
    composer require 4apps/staticphp-core:^2.0

Neither belongs in the committed `composer.json`: the path would not exist for anyone who
clones only this repository, and `dev-develop` is not a release.

### Migrations

Schema changes are plain `.sql` files in `src/Application/Migrations/`, applied in
filename order and tracked by sha256 in a `migrations` table. Forward only: there are no
`down` scripts, because a rollback script is written when the schema is theoretical and
run when it is not.

    ./staticphp migrate new "add users table"   # create an empty migration
    ./staticphp migrate status                  # what is applied, pending or blocked
    ./staticphp migrate apply                   # run everything pending
    ./staticphp migrate apply --dry-run         # list what would run
    ./staticphp migrate status --check          # exit 1 if anything is pending (for CI)

`migrate` is intercepted in `./staticphp` before the routing bootstrap and handed to
`StaticPHP\Utils\Models\Migrations\Cli`, so it never reaches the router. That is
deliberate: routing config has no notion of a cli-only route, so a migrations controller
would also answer over http.

Three states stop the tool and need a decision:

| State     | Meaning                                    | Way out                        |
| --------- | ------------------------------------------ | ------------------------------ |
| `DRIFT`   | The file changed after it was applied      | Revert it, or `repair <name>`  |
| `MISSING` | It was applied but the file is gone        | Restore it, or `forget <name>` |
| `FAILED`  | It started and never finished              | See below                      |

Adopting a database that already has the schema: `./staticphp migrate baseline` records
migrations as applied **without running them**.

Postgres, MySQL and SQLite are supported, but they are not equal. Postgres and SQLite have
transactional DDL, so a failing migration rolls back completely and its tracking row
commits with it. **MySQL commits DDL implicitly**, so a migration that fails half way
leaves the earlier statements in place and cannot be undone. There, the tracking row is
written *before* the migration runs and confirmed after, so a half-applied file shows up as
`FAILED` and blocks every later migration rather than looking untouched and being silently
re-run. Keep MySQL migrations to a single statement where you can.

A file whose first line is `-- migrations:no-transaction` runs outside a transaction — for
`CREATE INDEX CONCURRENTLY` on Postgres, or a SQLite table rebuild, which needs
`PRAGMA foreign_keys = OFF` and is silently ignored inside one. Postgres refuses such a
file if it holds more than one statement, since it would wrap a multi-statement send in an
implicit transaction anyway.

Settings live in `$config['migrations']` (directory, tracking table, which connection);
`--dir`, `--table` and `--connection` override them per run.

`scripts/migrations_integration.php` exercises the engine against a live Postgres and
MySQL; the phpunit suite covers it against SQLite and needs no server.

### Translations (i18n)

Source text is the key. Templates read as English, an unseen string registers itself the
first time a page asks for it, and an untranslated string shows up as `Log in*` rather than
as a dotted path nobody can read.

    ./staticphp i18n install          # write the schema into src/Application/Migrations
    ./staticphp migrate apply         # create the tables
    ./staticphp i18n status           # languages, and how much of each is translated
    ./staticphp i18n missing lv_lv    # what is left
    ./staticphp i18n set lv_lv "Log in" "Pieslēgties"
    ./staticphp i18n export lv_lv --out=lv.csv
    ./staticphp i18n import lv_lv lv.csv
    ./staticphp i18n scan             # compare the source tree against the database
    ./staticphp i18n status --check   # exit 1 if anything is untranslated (for CI)

Call `i18n::init()` from a bootstrap hook. With no arguments the country and language come
from the url prefix (`/lv-en/...`); a request that carries none is redirected to the
language its `Accept-Language` header asks for, or to the first configured one.

In templates:

    {{ 'Log in'|translate }}
    {{ _('Hello %name%', {'%name%': user.name}) }}
    {{ _f('{n, plural, zero{# failu} one{# fails} other{# faili}}', {'n': count}) }}
    {{ i18n_number(1234.5, 2) }}   {{ i18n_currency(99, 'EUR') }}   {{ i18n_date(date) }}
    <link rel="alternate" hreflang="{{ a.hreflang }}" href="{{ a.url }}"> {# for a in i18n.alternates #}

**Plurals go through ICU MessageFormat**, so the categories come from the target language:
Latvian has three and Russian four, and 21 is singular in both. A two-form `ngettext` gets
that wrong, which is why there isn't one.

**Nothing is marked html-safe.** Twig escapes the translation and every value substituted
into it. A translation that really does carry markup needs an explicit `|raw`.

Numbers, dates and currency are formatted by ICU using a locale derived as
`<language>_<COUNTRY>` — so an English page on the Latvian site reads in English and formats
the Latvian way. Set `locale` on a country in `$config['i18n']['available']` to override it.

Strings are loaded per language and cached; a per-language row in `i18n_cached` is what
tells every application server the copy is still good, so a translator saving one string
invalidates all of them without any of them being told. If the database is unreachable the
page renders source strings and logs, rather than failing.

Postgres, MySQL and SQLite are supported. Keys are addressed by a sha256 of the source
text, because source text is a whole sentence and MySQL cannot index one.

Upgrading a database that has the pre-2.0 i18n tables: `./staticphp i18n install --upgrade`
writes a migration that dedupes `i18n_translations`, adds the unique constraint that stops
the duplicates coming back, and backfills the key hashes. **It deletes rows — read it and
take a dump first.** Postgres only; the old schema never shipped for anything else.

`scripts/i18n_integration.php` exercises all of this against a live Postgres and MySQL,
including the upgrade path.

### Error pages

There are two of them, and which one a browser gets is decided by `$config['debug']` and
by nothing else.

**The status page** is what the public sees. A status code, the standard explanation for
it, and nothing internal — no exception message, no path, no trace. 5xx also carries a
short reference id, which is written into the log line and the error email for the same
request, so "something went wrong, reference `7f3a1c`" can actually be looked up. If a
reverse proxy already set `X-Request-Id`, that is used instead.

**The debug page** is what a developer sees. The exception and everything it was caused
by, each with the source around the throw and a stack trace whose frames expand into their
own source. Under it, the request: headers, query, body, cookies, session, `$_SERVER` and
the runtime. Anything that looks like a credential is replaced with `***` first, in the
page and in the log alike.

Both are one self contained file — inline css, no fonts, images or scripts fetched from
anywhere, light and dark. The page that renders a broken application must not depend on
the application: an error page that needs the asset pipeline or the template engine cannot
render the failure of the asset pipeline or the template engine. For the same reason they
are plain php templates rather than twig ones.

Replace either per application:

```php
$config['error_pages'] = [
    'status' => APP_PATH . '/Views/Errors/status.php',
    'debug'  => null,
];
```

`StaticPHP\Core\Exceptions\ErrorPage` documents what a template is handed. Every value
goes through the `$esc` callable it receives — messages and request data routinely contain
markup.

An `ErrorMessage` description is a note to a developer, not a sentence for the public:
Router's 404 carries `Method "wire" of class "Payments" could not be found`. Descriptions
are therefore withheld with debug off, in every output format. Pass `publicDescription:
true` when the description really is meant to be published:

```php
throw new ErrorMessage(
    message: 'Not Found',
    description: 'That invoice has been archived.',
    httpStatusCode: 404,
    publicDescription: true
);
```

### Basic Nginx configuration

The router reads `REQUEST_URI` and works out the rest, so nginx only has to send anything
that is not a real file to the front controller. There is no rewrite to construct and no
`PATH_INFO` to set up.

    server {
        listen       443 ssl;
        server_name  example.com;

        root  /srv/sites/example.com/src/Application/Public;
        index index.php;

        location / {
            try_files $uri $uri/ /index.php;
        }

        # Templates append ?{{ config.asset_version }} to every asset url, so a build
        # changes the url and these can be cached indefinitely
        location ^~ /assets/ {
            expires max;
            access_log off;
        }

        location ~* \.(eot|ttf|woff|woff2)$ {
            add_header Access-Control-Allow-Origin *;
        }

        location ~ \.php$ {
            fastcgi_pass  127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include /etc/nginx/fastcgi_params;
        }

        # .env, .git and friends are never static files
        location ~ /\. {
            return 404;
        }
    }

Do not set `fastcgi_intercept_errors on` or point `error_page` at static html. The framework
renders its own error pages, including the reference id that ties a 5xx to its log line -
intercepting them replaces that with a blank nginx page. See [Error pages](#error-pages).

`src/Application/Public/.htaccess` covers the same ground for apache and ships configured.

### Releases

Two numbers, one input. `.version` holds `major.minor` and is the only part edited by hand;
the patch is the commit count, so it never needs a commit of its own and cannot conflict on
a merge.

    ./scripts/version.bash        # what the current commit would release as, e.g. 2.0.325
    ./scripts/release_tag.bash    # create that tag locally, after checking it is sane
    ./scripts/build_info.bash     # write .build_info.json for the running application

Tagging and build info are deliberately separate: the tag is what Packagist publishes, while
`.build_info.json` is what the application reports about itself and is written on every
build, tagged or not. Both read `.version`, so they agree.

`release_tag.bash` only creates the tag. Pushing it is what makes the release public, so
that stays a separate, deliberate `git push origin <tag>`.

## TODO and ideas

-   Cache class usage guide
-   Cache class: postgres, mysql and sql lite support (who knows, somebody may want this:)
-   Write usage guide
-   Rewrite all sessions classes into one by adding an option to choose from session backend to use, possibly allowing to use multiple backends (e.g. memcached -> sql).
-   Look for a way to extend a view from same directory as the view extending it. E.g. {% extends "layout.html" %} instead of {% extends "Defaults/Views/layout.html" %}
