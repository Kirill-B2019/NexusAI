<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\AttemptToAuthenticate;

class AttemptToAuthenticateWithoutRemember extends AttemptToAuthenticate
{
    public function handle($request, $next)
    {
        $request->merge(['remember' => false]);

        return parent::handle($request, $next);
    }
}
