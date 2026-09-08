<?php

namespace App\Http\Controllers\Api\Admin;

/**
 * The admin routes shop-service owns, relayed unchanged.
 *
 * See routes/api.php for which routes those are, and
 * ForwardedAdminController for how little happens here.
 */
class ShopAdminController extends ForwardedAdminController
{
    protected $service = 'shop';
}
