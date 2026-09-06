<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CurrentOrganization
{
    public static function from(Request $request): Organization
    {
        $organization = $request->attributes->get('organization');

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException('No workspace is available for this account.');
        }

        return $organization;
    }
}
