<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AcceptOfferRequest;
use App\Services\AdminActivityNotifier;
use Illuminate\Http\RedirectResponse;

class OfferAcceptanceController extends Controller
{
    public function __construct(
        protected AdminActivityNotifier $adminActivity,
    ) {}

    public function store(AcceptOfferRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasAcceptedOffer()) {
            $user->update(['offer_accepted_at' => now()]);
            $this->adminActivity->clientAcceptedOffer($user);
        }

        return redirect()
            ->route('account')
            ->with('lk_section', 'oferta')
            ->with('status', 'Согласие с договором-офертой сохранено.');
    }
}
