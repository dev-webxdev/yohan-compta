<?php

namespace App\Http\Middleware;

use App\Services\DatabaseAccessLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAuthenticated
{
    public function __construct(private readonly DatabaseAccessLock $databaseLock)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('auth.authenticated') !== true) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('login'));
        }

        $routeName = (string) $request->route()?->getName();
        $isRestore = in_array($routeName, [
            'settings.database.restore',
            'settings.database.backups.restore',
        ], true);

        $response = $isRestore ? $next($request) : $this->databaseLock->shared(fn (): Response => $next($request));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
