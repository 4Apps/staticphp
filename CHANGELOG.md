# Changelog

Kept for the record. Entries up to 1.2.0 were written as a "History" section in README.md
and are reproduced verbatim, checkmarks and all - they describe a framework that vendored
its own `System/` directory, before 2.0 split it into `4apps/staticphp-core`.

For what changed in 2.0 and what a 1.x site has to do about it, see [UPGRADE.md](UPGRADE.md).


####v1.2.0

-   Tests most probably doesn't work anymore
-   Update todo example app
-   √ Bring Exceptions to its own folder
-   √ Use full namespaces also for Core\Models. For example `use \Core\Models\Router;` now is `use \StaticPHP\Core\Router;`
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

-   √ Added various small helper functions, take a look in System/Core/Helpers/Other.php
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
