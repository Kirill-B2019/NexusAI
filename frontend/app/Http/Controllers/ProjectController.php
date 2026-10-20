<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Models\Project;
use App\Services\NexusApiClient;
use App\Services\NexusExpert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    public function chat(Request $request, NexusApiClient $nexus): View
    {
        $experts = [];
        if ($request->user()->can('manage-projects') && filled(config('services.nexus_ai.url'))) {
            try {
                $experts = array_values(array_filter(
                    $nexus->listExperts(),
                    static fn (NexusExpert $expert): bool => $expert->enabled,
                ));
            } catch (NexusApiException $exception) {
                Log::warning('Could not load NEXUS AI experts for the chat page.', [
                    'status' => $exception->statusCode,
                    'configuration_error' => $exception->configurationError,
                ]);
            }
        }

        return view('pages.chat', [
            'projects' => Project::query()
                ->visibleTo($request->user())
                ->orderBy('name')
                ->get(['id', 'name']),
            'apiConfigured' => filled(config('services.nexus_ai.url')),
            'experts' => $experts,
        ]);
    }
}
