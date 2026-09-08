<?php

namespace App\Http\Controllers\Api\Admin;

/**
 * The admin routes website-service owns, relayed unchanged.
 *
 * See routes/api.php for which routes those are, and
 * ForwardedAdminController for how little happens here.
 */
class WebsiteAdminController extends ForwardedAdminController
{
    protected $service = 'website';
}
