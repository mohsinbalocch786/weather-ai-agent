<?php

namespace App\Services\Weather\Exceptions;

use RuntimeException;

class CityNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $city)
    {
        parent::__construct("City [{$city}] could not be found.");
    }
}
