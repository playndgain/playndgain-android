<?php

namespace App\Networks\Exceptions;

use RuntimeException;

class UnknownDriverException extends RuntimeException
{
    public function __construct(string $driver)
    {
        parent::__construct("No driver registered for [{$driver}].");
    }
}
