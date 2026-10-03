<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company has to finish its setup (every detail, and the logo) before the workspace opens.
 * Only the screens that carry out the setup stay reachable meanwhile.
 */
class EnsureCompanySetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        if ($company === null || $company->isProfileComplete()) {
            return $next($request);
        }

        if ($request->is('api/app/company', 'api/app/company/logo', 'api/app/company/locale')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => __('Finish your company setup to continue.'),
            'data' => ['missing_fields' => $company->missingProfileFields()],
            'code' => 'COMPANY_SETUP_REQUIRED',
        ], 403);
    }
}
