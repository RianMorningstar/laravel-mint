<?php

namespace LaravelMint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-Mint-Api-Key') ?? $request->query('api_key');

        if (! $apiKey) {
            return response()->json([
                'error' => 'API key required',
                'message' => 'Please provide an API key via X-Mint-Api-Key header or api_key query parameter',
            ], 401);
        }

        $validKeys = config('mint.api.keys', []);

        // If no keys configured, check for default key
        if (empty($validKeys)) {
            $validKeys = [config('mint.api.default_key', 'mint_'.md5(config('app.key')))];
        }

        if (! in_array($apiKey, $validKeys)) {
            return response()->json([
                'error' => 'Invalid API key',
                'message' => 'The provided API key is not valid',
            ], 401);
        }

        // Add API key info to request
        $request->attributes->set('mint_api_key', $apiKey);

        return $next($request);
    }
}
