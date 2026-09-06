<?php

namespace App\Http\Controllers;

use App\Models\SimulationRun;
use App\Services\Ai\OpenAiOperatorCopilot;
use App\Services\Demo\DemoWorkspace;
use Illuminate\Http\Request;

class OperatorCopilotController extends Controller
{
    public function store(Request $request, SimulationRun $run, OpenAiOperatorCopilot $copilot, DemoWorkspace $workspace): array
    {
        $workspace->owns($run);
        abort_unless(filled(config('services.openai.key')), 503, 'Set OPENAI_API_KEY before using AMAN Copilot.');
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:12'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:1200'],
        ]);

        return $copilot->respond($run, $data['question'], $data['history'] ?? []);
    }
}
