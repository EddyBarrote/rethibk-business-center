<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;

interface Renderer
{
    /**
     * Writes the file to $path.
     */
    public function render(DocumentSpec $spec, Brand $brand, string $path): void;
}
