<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        return view('pages.projects', [
            'projects' => Project::query()
                ->visibleTo($request->user())
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function chat(Request $request): View
    {
        return view('pages.chat', [
            'projects' => Project::query()
                ->visibleTo($request->user())
                ->orderBy('name')
                ->get(['id', 'name']),
            'apiConfigured' => filled(config('services.nexus_ai.url')),
        ]);
    }
}
