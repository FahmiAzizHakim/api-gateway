<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The websites this installation serves
    |--------------------------------------------------------------------------
    |
    | The website records themselves live in website-service. The gateway only
    | needs their ids: users, access groups and admin menus are all scoped to
    | one website, and a JWT carries that id to the services.
    |
    | Adding a fourth site means adding its id here and seeding it in
    | website-service -- the two only have to agree on the number.
    |
    */

    'ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('SITE_IDS', '1,2,3'))
    ))),

];
