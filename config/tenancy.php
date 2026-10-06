<?php

return [

    /*
    | Tenants are served from {slug}.{central_domain}, or from their own
    | domain when tenants.domain is set. Locally, "localhost" lets
    | http://micomoc.localhost:8000 resolve without DNS changes.
    */

    'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

    /*
    | The super admin console. Never a tenant: the slugs below are reserved.
    */

    'admin_domain' => env('TENANCY_ADMIN_DOMAIN') ?: 'admin.'.env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

    'reserved_slugs' => ['admin', 'www', 'api', 'app', 'mail', 'static'],

];
