<?php

use App\Mcp\Servers\FakeErpServer;
use Laravel\Mcp\Facades\Mcp;

// Fake Rethink ERP for development and tests (section 8.2).
// Started by the rethink_erp client with `php artisan mcp:start fake-erp`.
Mcp::local('fake-erp', FakeErpServer::class);
