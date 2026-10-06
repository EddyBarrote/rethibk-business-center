<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições: one place for the company, people and access, communication,
 * AI agents and the person's own account (docs/DECISOES.md, "Definições
 * num só lugar"). The page lists the sections the person may open; each
 * section checks its own permission on the server.
 */
class SettingsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Settings/Index');
    }
}
