<?php

use App\Providers\AgentServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\KnowledgeServiceProvider;
use App\Providers\McpServiceProvider;

return [
    AppServiceProvider::class,
    AgentServiceProvider::class,
    HorizonServiceProvider::class,
    KnowledgeServiceProvider::class,
    McpServiceProvider::class,
];
