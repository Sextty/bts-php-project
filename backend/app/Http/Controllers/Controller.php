<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 12's minimal skeleton omits this trait by default; added back so
    // CreditApplication controllers can use $this->authorize() against CreditApplicationPolicy.
    use AuthorizesRequests;
}
