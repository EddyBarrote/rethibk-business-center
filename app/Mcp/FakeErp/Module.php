<?php

namespace App\Mcp\FakeErp;

interface Module
{
    /**
     * @return list<FakeErpTool>
     */
    public function tools(): array;
}
