<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use Illuminate\View\View;

class DirectionController extends Controller
{
    public function index(): View
    {
        return view('pages.directions', [
            'directions' => Direction::query()->published()->ordered()->get(),
        ]);
    }

    public function show(Direction $direction): View
    {
        abort_unless($direction->is_published, 404);

        return view('pages.direction-show', [
            'direction' => $direction,
            'directions' => Direction::query()->published()->ordered()->get(),
        ]);
    }
}
