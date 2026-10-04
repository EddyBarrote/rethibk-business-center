<?php

use App\Providers\AgentServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\McpServiceProvider;

return [
    AppServiceProvider::class,
    AgentServiceProvider::class,
    HorizonServiceProvider::class,
    McpServiceProvider::class,
];
