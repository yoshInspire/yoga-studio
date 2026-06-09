<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use Illuminate\View\View;

class DirectionController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.directions', [
            'directions' => Direction::query()->published()->ordered()->get(),
        ]);
    }
}
