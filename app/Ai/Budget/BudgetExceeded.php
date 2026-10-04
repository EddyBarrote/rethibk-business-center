<?php

namespace App\Ai\Budget;

use RuntimeException;

class BudgetExceeded extends RuntimeException
{
    public function __construct(public readonly string $scope, string $message)
    {
        parent::__construct($message);
    }
}
