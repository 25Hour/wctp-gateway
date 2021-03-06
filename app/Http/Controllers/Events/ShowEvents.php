<?php

namespace App\Http\Controllers\Events;

use App\EventLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ShowEvents extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function __invoke(Request $request)
    {
        $events = EventLog::orderBy('created_at', 'desc')->paginate(10);

        return view('events.show')->with('events', $events );
    }
}
