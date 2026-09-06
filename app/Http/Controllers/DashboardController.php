<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('emails');
    }

    public function compose(): Response
    {
        return Inertia::render('Compose/Index');
    }

    public function docs(): Response
    {
        return Inertia::render('Docs/Index');
    }
}
