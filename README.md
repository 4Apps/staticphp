[![apidocs](http://img.shields.io/badge/api-master--dev-brightgreen.svg)](http://staticphp.gm.lv/docs/) [![packagist](http://img.shields.io/badge/packagist-master--dev-brightgreen.svg)](https://packagist.org/packages/4apps/staticphp)

![logo](resources/logo_50.png)

# StaticPHP

Simple, modular php framework. **README.md needs to be updated.**

### Requirements

-   PHP 8.4+
-   Twig 3.0+

### Installation

There are two ways to start using StaticPHP framework:

1. Docker <- Suggested one
2. Composer

**1. Using Docker**

1. `docker compose build develop`
2. `docker compose up -d --remove-orphans develop`
3. Open in vscode using [Remote containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)

**2. Using composer**

Run `composer create-project 4apps/staticphp ./` for stable version and `composer create-project 4apps/staticphp ./ master-dev` for latest development version from github. Composer will install all the dependecies for you.

_[How to install composer?](https://getcomposer.org/doc/00-intro.md)_

**Npm for SCSS and Javascript**

Install node and npm
run `npm install`

_[How to install node?](https://nodejs.org/en/download/package-manager/)_

_\* Remember to set correct permissions for Cache directory. For example: `chown www-data:www-data ./src/Application/Cache/` or `chmod 777 ./src/Application/Cache/`_

Most quickest way to run your project is to use php's in-built server. To do that, cd into the _./somedir/src/Application/Public_ and run `php -S 0.0.0.0:8081`. Now open your **server_ip:8081** (or **127.0.0.1:8081**) and StaticPHP first page should show up.

**or**

run `npm start`

By default, running StaticPHP with php's cli server, turns debugging on, but you can configure that in _./somedir/Applications/Config/Config.php_ by setting $config['environment'] or $config['debug'] variables.

_\* Take a look at home controller in ./somedir/src/Application/Modules/Controllers/Welcome.php and views in ./somedir/src/Application/Modules/Views/ for basic framework usage._

### Assets

All assets are stored in _./somedir/src/Application/Public/assets/_ directory. StaticPHP uses npm to handle css (using SCSS) and js assets, but you can also do it manually.

### Cron

Running controller from command line can be done this way: `php -f /srv/sites/gm.lv/sla/src/Application/Public/index.php -- --query /defaults/console/refresh`

### Api

[Api documentation](http://staticphp.gm.lv/docs/)\*

_\* Work in progress_

### Example app

[A simple todo application](http://staticphp-example.gm.lv/) based on sessions. To view the source, checkout the "example" branch.

### Tests and code style

Everything that gates a merge lives in one script, used identically from the shell, the
pre-commit hook and CI:

    ./scripts/code_tests.bash          # everything
    ./scripts/code_tests.bash php      # php only: lint, phpcs, both phpunit suites
    ./scripts/code_tests.bash js       # js only: tsc, eslint, prettier

It needs the composer dev dependencies (`composer install`) and, for the js half,
`npm ci`.

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
`System\Modules\Utils\Models\Migrations\Cli`, so it never reaches the router. That is
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

### Basic Nginx configuration

    server {
        listen       80;
        listen       443 ssl;
        server_name  staticphp.gm.lv;

        root  /www/sites/gm.lv/staticphp/src/Application/Public;
        index index.php index.html index.htm;

        # Error responses
        error_page 403 /errors/E403.html;
        error_page 404 405 =404 /errors/E404.html;
        error_page 500 501 502 503 504 =500 /errors/E500.html;

        # Handle error responses
        location ~ /errors/(E[0-9]*.html) {
            alias /www/sites/gm.lv/staticphp/System/Modules/Core/Views/Errors/$1;
        }

        # Base location
        location / {
            if (!-e $request_filename)
            {
                rewrite  ^(.*)$  /index.php?/$1  last;
            }
        }

        # Allow font origin (for webfonts and similar)
        location ~* \.(eot|ttf|woff|svg)$ {
            add_header Access-Control-Allow-Origin *;
        }

        # Set assets expiration headers to max
        location ~ ^/assets/ {
            expires max;
        }

        # Handle php files
        location ~ \.php(/|$) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            if (!-f $document_root$fastcgi_script_name) {
                return 404;
            }

            fastcgi_pass  127.0.0.1:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO       $fastcgi_path_info;
            fastcgi_param PATH_TRANSLATED $document_root$fastcgi_path_info;
            include /etc/nginx/fastcgi_params;

            # To intercept errors from fastcgi and show our error pages instead, otherwise nginx will send to browser whatever response there was from fastcgi
            fastcgi_intercept_errors on;
        }

        # Show 404 for hidden files
        location ~ /\. {
            return 404;
        }
    }

## TODO and ideas

-   Cache class usage guide
-   Cache class: postgres, mysql and sql lite support (who knows, somebody may want this:)
-   Write usage guide
-   Rewrite all sessions classes into one by adding an option to choose from session backend to use, possibly allowing to use multiple backends (e.g. memcached -> sql).
-   Look for a way to extend a view from same directory as the view extending it. E.g. {% extends "layout.html" %} instead of {% extends "Defaults/Views/layout.html" %}

## History

####v1.2.0

-   Tests most probably doesn't work anymore
-   Update todo example app
-   √ Bring Exceptions to its own folder
-   √ Use full namespaces also for Core\Models. For example `use \Core\Models\Router;` now is `use \System\Modules\Core\Router;`
-   √ Update url structure to work without ending slash. Now in templates you do this: `{{ controller_url }}/test-page` instead of - `{{ controller_url }}test-page`.
-   √ Generate menus and submenus/sidebar using classes
-   √ Table presentation class
-   √ Update server to PHP 8.1
-   √ Update page layout to bootstrap 5

####v1.2.0

-   √ Moved docker to main repository. Development is now meant to be used remotely in docker.
-   √ Moved Application and System folders into src.
-   √ Updated everything - PHP 8.1, bootstrap, npm libraries.
-   √ Removed redundant npm libraries.
-   √ Removed jQuery
-   √ Added bump2version.
-   √ Added build docker service which creates staticPHP archive.
-   √ Moved assets under its own src directory - easier to grep through if its under another parent path than dist folder.

####v1.1.0

-   √ Updated Twig

####v1.0.0

-   √ Removed Help/Example page descriptions - thats basically what apigen is for.
-   √ Rewrite Db class so that in init method $name parameter comes before $config.
-   √ Use composer only for php stuff.
-   √ Setup SCSS using npm scripts.
-   √ Setup webpack for js, also using npm scripts.
-   √ Expanded core clases from universal to more specific roles.
-   √ Added first PHPUnit tests.
-   √ Various Bugfixes.
-   √ Allow controllers, helpers and models to also be loaded from Application folder, same as Config already was.
-   √ Replaced apigen config file with new format.

####v0.9.5

-   √ Cache class: Redis, Memcached, APC and files.
-   √ i18n class with twig integration (i18n::twigRegister(Load::$config['view_engine'])).

####v0.9.4

-   √ Fixed possibility of nonexisting variable causing notices.
-   √ Updated nginx configuration example.
-   √ Added .editorconfig file.
-   √ Updated composer dependencies.
-   √ Few micro performance updates.
-   √ Option to disable twig template engine.

####v0.9.3

-   √ Fixed issue preventing to load page from subdirectory.

####v0.9.2

-   √ Fixed issue with $controller_url not being set when default route from Routes.php config file is loaded.
-   √ Core controller render method didn't have $data argument, fixed.
-   √ siteUrl twig filter now accepts all parameters that Router::siteUrl() does.
-   √ Added debug method to Router class.
-   √ Made all Router's helper methods public.
-   √ Renamed some variables of the Router class, so it makes more sense also added some new ones.
-   √ More Router fixes for correct controller handling.

####v0.9.1

-   √ Documentation config update

####v0.9

-   √ Added various small helper functions, take a look in System/Modules/Core/Helpers/Other.php
-   √ Rewritten framework for more modular structure
    -   Links like - /module/my-controller/my-method are now turned into Application/Modules/Module/MyController.php::myMethod($params)
-   √ PSR-0 or PSR-4 autoloading schema
    -   All folder names and file names are now named using StudlyCaps
    -   We are not using "Vendor" in front of autoloading classes to avoid long includes (e.g. "use" parameters), which could be useful if more than one application is run with same instance, but for now we are skipping this.
-   √ Added core controller
    -   If used, controller now have access to self::$controller_url and self::$method_url, very useful for migrating controllers to other urls and for controller copying.
    -   self::render('path_to_view.html') will automatically look into module's Views directory
    -   self::write($params) will echo json encoded string if $params is an array
-   √ Json reponse has been used very often so far, maybe we should make some kind of output filtering method that outputs content based on output type?
    -   If an array is returned from a controller method, its turned into a json encoded string and is sent back to the browser
-   √ Put helpers under namespaces?
    -   No, functions should be in global scope
-   √ Decide to go with Reflection Api or not.
    -   Yes for Reflection Api
-   √ Css and js minifying - git hooks, also css and js versioning.
    -   Added minify.py under Scripts, this also makes javascript source maps
    -   Added all the stuff related to this in default views
    -   Added git pre-commit hook that can check whether css, js file was modified and based on that execute minify.py
    -   Added git post-receive hook that can check whether css or js file was modified and base on that increase css or js version by calling a url with wget
-   √ Script to clear Twig cache. Also a git hook?
    -   Added a git post-receive script that can check whether any html file was modified, and if was, can clear twig cache

####v0.8

-   √ Should database run all queries in beginTransaction .. commit .. rollback mode?
    -   Not for now, by default we are running connections in persistent mode, which can cause issues with transactions.
-   √ Update one of the project currently using StaticPHP to get the idea of whether we are not missing any required variable to be available globally in view files.
-   √ Choose documentation parser.
    -   apigen for now.
-   √ Check whether form validation helper still works and how it applies to Twig.
    -   Works now and can be registered with twig by running \models\fv::twig_register();
-   √ Pages helper should register it self with Twig once loadded and if Twig is available.
    -   Nop, pagination html can be passed in the view as variable.
-   √ Change all include to require, so that we don't expose StaticPHP to any security issues by doing something that can't be done.
-   √ Update StaticPHP start page.
-   √ Add filesystem helpers to core \load class.
-   √ Logger interface through core\load class.
-   √ Go through core router class and make sure there are no redundant methods.
-   √ Rename all class methods in camelCase format to comply with php-fip standards. Also possibly filenames.
-   √ Check whether url prefixes are working.
-   √ Check before_controller hook.
