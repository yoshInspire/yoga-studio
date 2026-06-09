<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            'trainers' => User::query()->publishedOnSite()->get(),
        ]);
    }
}
