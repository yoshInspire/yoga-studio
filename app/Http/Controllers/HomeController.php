<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use App\Models\User;
use App\Support\PurchaseCatalog;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            'trainers' => User::query()->publishedOnSite()->get(),
            'directions' => Direction::query()->published()->ordered()->get(),
            'trialPrice' => PurchaseCatalog::price('group_trial'),
        ]);
    }
}
