<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOfferAccepted
{
    /**
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isClient() && ! $user->hasAcceptedOffer()) {
            return redirect()
                ->route('account')
                ->with('lk_section', 'oferta')
                ->withErrors(['offer' => 'Примите договор-оферту, чтобы продолжить.']);
        }

        return $next($request);
    }
}
