<?php

/*
|--------------------------------------------------------------------------
| Request context
|--------------------------------------------------------------------------
|
| The gateway owns the users table, so once a request is authenticated these
| read straight off the signed-in user. Until login is finished they fall back
| to the same headers the services accept, so the endpoints are callable from
| curl/Postman in the meantime.
|
| The services have the same two helpers, reading the JWT claims instead --
| which is what lets a service scope its rows without a users table of its own.
*/

if (!function_exists('acting_website_id')) {
    /**
     * The website this request acts on.
     */
    function acting_website_id()
    {
        $user = auth('api')->user();

        if ($user) {
            return $user->website_id;
        }

        $request = request();

        return $request->attributes->get('website_id')
            ?? $request->header('X-Website-Id')
            ?? $request->input('website_id');
    }
}

if (!function_exists('admin_website_id')) {
    /**
     * Alias kept so every service keeps scoping through one name.
     */
    function admin_website_id()
    {
        return acting_website_id();
    }
}

if (!function_exists('acting_user_email')) {
    /**
     * Who to stamp on created_by / updated_by.
     */
    function acting_user_email()
    {
        $user = auth('api')->user();

        if ($user) {
            return $user->email;
        }

        $request = request();

        return $request->attributes->get('user_email')
            ?? $request->header('X-User-Email');
    }
}
