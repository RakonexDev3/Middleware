<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.middleware.api_key') ?: env('MIDDLEWARE_API_KEY');

        if (!$expectedToken) {
            return response()->json([
                'success' => false,
                'error' => 'API Key authentication is not configured in the middleware environment.'
            ], 500);
        }

        // Retrieve token from Bearer Authorization header, X-API-Key header, or token query parameter
        $token = $request->bearerToken() ?: $request->header('X-API-Key') ?: $request->query('token');

        if ($token !== $expectedToken) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Invalid API token.'
            ], 401);
        }

        return $next($request);
    }
}
