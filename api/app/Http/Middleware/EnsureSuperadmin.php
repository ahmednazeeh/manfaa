<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the superadmin-only subset of admin routes (admin user management).
 * Runs behind auth:admin — inactive admins never get this far, their
 * session dies at user resolution (PlatformServiceProvider).
 */
class EnsureSuperadmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');

        if (! $user instanceof AdminUser || $user->role !== 'superadmin') {
            abort(403, 'Superadmin access required.');
        }

        return $next($request);
    }
}
