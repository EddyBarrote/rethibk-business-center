<?php

return [

    /*
    | Tenants are served from {slug}.{central_domain}, or from their own
    | domain when tenants.domain is set. Locally, "localhost" lets
    | http://micomoc.localhost:8000 resolve without DNS changes.
    */

    'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

];
