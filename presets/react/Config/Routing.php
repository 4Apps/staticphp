<?php

/*
|--------------------------------------------------------------------------
| Routing
|
| Each next item overrides last one
| Format: 'regular expression'[without starting slash] => 'new URL'
| Leave '' for default controller
|--------------------------------------------------------------------------
*/

$config['routing'] = [

    // Default Controller and Method names
    '' => 'Spa/Index/index',

    // Every client side route resolves to the same shell, so that a deep link and a
    // reload land on the react app rather than on a 404. Keep it below the api, which is
    // matched by its own segments and must not be swallowed here.
    '^(?!api/)(.*)$' => 'Spa/Index/index',
];
