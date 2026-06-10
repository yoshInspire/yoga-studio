<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AcceptOfferRequest;
use Illuminate\Http\RedirectResponse;

class OfferAcceptanceController extends Controller
{
    public function store(AcceptOfferRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasAcceptedOffer()) {
            $user->update(['offer_accepted_at' => now()]);
        }

        return redirect()
            ->route('account')
            ->with('lk_section', 'oferta')
            ->with('status', 'Согласие с договором-офертой сохранено.');
    }
}
