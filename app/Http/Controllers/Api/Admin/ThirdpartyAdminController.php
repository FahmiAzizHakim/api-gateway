<?php

namespace App\Http\Controllers\Api\Admin;

/**
 * The admin routes thirdparty-service owns, relayed unchanged.
 *
 * See routes/api.php for which routes those are, and
 * ForwardedAdminController for how little happens here.
 */
class ThirdpartyAdminController extends ForwardedAdminController
{
    protected $service = 'thirdparty';
}
