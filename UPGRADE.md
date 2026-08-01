# Upgrading to 2.0

2.0 turns the framework into a composer package. Everything below is a breaking change;
there is no compatibility shim, because 1.x was never released to Packagist as anything
other than the 2019 `v1.1.0` tag and every known installation vendors its own copy of
`System/`.

Read the whole list before starting. Steps 1 and 2 are mechanical, step 3 is where a site
actually has to make decisions.

---

## 1. Depend on the package instead of vendoring the framework

Delete the vendored `System/` directory and require the package:

```bash
git rm -r System            # or src/System
composer require 4apps/staticphp-core:^2.0
```

The application tree is untouched by this step.

## 2. Rewrite the namespaces

1.x shipped the framework half migrated: `Core\` was still a bare root while
`Presentation` and `Utils` had already moved under `System\Modules\`. Both land under
`StaticPHP\` in 2.0.

| 1.x                              | 2.0                             |
| -------------------------------- | ------------------------------- |
| `Core\Models\Router`             | `StaticPHP\Core\Models\Router`  |
| `System\Modules\Utils\Models\Db` | `StaticPHP\Utils\Models\Db`     |
| `System\Modules\Presentation\…`  | `StaticPHP\Presentation\…`      |

The two prefixes are disjoint, so one pass handles a tree that mixes them:

```bash
scripts/upgrade_v2_namespaces.bash Application
```

It rewrites `.php`, `.html` and `.twig` files, handles both the `Core\` and the escaped
`Core\\` spelling, and is idempotent - running it twice changes nothing. Run it on a clean
working tree and read the diff; it is a text substitution, not a parser.

Application namespaces are unchanged. A module is still its own root - `Pasta\Controllers\Quality`
stays exactly as it is, and so does `Models\AppConfig` at the application root.

## 3. Replace the bootstrap files

### `Public/index.php`

Composer is now the entry point rather than something the framework includes at the end of
its own bootstrap.

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';   // adjust depth to your layout

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

`PUBLIC_PATH` is now **required**. The framework used to walk up from its own file to find
the application; installed at `vendor/4apps/staticphp-core/src` that would find the
framework's own demo app, so it throws instead of guessing.

### `Tests/autoload.php`

Both test bootstraps carried a hand-rolled copy of the autoloader. Replace the whole file:

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';   // adjust depth to your layout

define('PUBLIC_PATH', dirname(__DIR__) . '/Public');

require StaticPHP\Core\Bootstrap::AUTOLOAD;
```

`Bootstrap::AUTOLOAD` brings up the path constants and the application autoloader without
initialising the router or building a view engine.

### Path constants

| 1.x                | 2.0                                                                  |
| ------------------ | -------------------------------------------------------------------- |
| `SYS_PATH`         | `SP_PATH` - the framework's own directory, wherever composer put it   |
| `SYS_MODULES_PATH` | `SP_PATH` - the framework has no `Modules/` level any more            |
| `VENDOR_PATH`      | unchanged, but now resolved from composer rather than probed          |

`SYS_PATH` and `SYS_MODULES_PATH` are gone rather than redefined, so a file still using
them fails loudly instead of silently resolving somewhere wrong. In practice both were
only ever referenced from the test bootstrap you are replacing above.

## 4. `$project` now names a registered module path

The third segment in `Load::` calls and in the autoload lists used to be a directory name
appended to `BASE_PATH`, which assumed every loadable tree sat beside the application. It
now names an entry in `$config['module_paths']`:

```php
$config['module_paths'] = [
    'site2' => BASE_PATH . '/site2/Modules',
];
```

The value is the directory that *holds* modules, not its parent. `staticphp` is a reserved
name resolving to the framework's own modules - it needs no entry and cannot be
overridden:

```php
// 1.x
$config['autoload_helpers'] = ['Functions', 'System/Utils/Helpers'];
Config::load(['Db'], 'Utils', 'System');

// 2.0
$config['autoload_helpers'] = ['Functions', 'staticphp/Utils/Helpers'];
Config::load(['Db'], 'Utils', 'staticphp');
```

An unknown name now throws `InvalidArgumentException` rather than silently building a path
that does not exist.

## 5. Twig is optional

`4apps/staticphp-core` suggests `twig/twig` instead of requiring it. If your application
renders templates, require it explicitly:

```bash
composer require twig/twig:^3.0
```

If it does not - an api, a worker - leave it out and the eight files that twig and the
symfony polyfills load eagerly on every request go away with it. `$config['disable_twig']`
still exists and still skips building the environment when the library *is* installed.

`registerTwig()` on the shipped classes now returns quietly when there is no view engine,
so it is safe to call either way.

### `Load::view()` without an engine

The no-engine path is now a real view layer rather than a stub, because for an api-only
install it is the *only* view layer:

- It extracts `$data` into the template. Previously it required the file without passing
  anything, so a plain php view could only reach `$config`.
- `$return = true` returns the rendered string. Previously it returned `false`.
- `$config` and `$env` are provided the same way twig provides them, filtered through the
  same credential stripping.

The include happens in a scope of its own, so a data key called `files` or `path` cannot
overwrite the renderer's own variables.

---

## What did not change

- Application namespaces, module layout and routing.
- `Load::view()`, controllers, `Router::error()`, the config array.
- The `staticphp` cli and its `migrate` / `i18n` commands.

## Things worth knowing

- The application autoloader now probes two roots (`APP_MODULES_PATH`, `APP_PATH`) instead
  of five. The tail of the old chain let an application shadow a framework class by
  filename; nothing used it, and composer resolves `StaticPHP\` before the callback runs.
- It is registered *after* composer's, so framework and vendor classes never reach it.
- If you want the application classmap optimised in production, `composer dump-autoload -o`
  works as usual - but it cannot express one repository serving several applications, so
  leave it off if you use that layout.
