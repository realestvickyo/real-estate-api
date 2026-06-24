<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadKanbanController extends Controller
{
    public function update(Request $request, Lead $lead): JsonResponse
    {
        // 1. DEFENSIVE GUARDRAIL: Prevent any modification if the deal is closed
        if ($lead->kanban_stage === 'closed') {
            return response()->json([
                'error' => 'Permission Denied',
                'message' => 'This deal is settled and locked. It cannot be reverted or modified.'
            ], 403);
        }

        // 2. Validate the incoming stage
        $validated = $request->validate([
            'kanban_stage' => 'required|string|in:new,contacted,showing,offer,escrow,closed,lost'
        ]);

        // 3. Update the stage
        $lead->update(['kanban_stage' => $validated['kanban_stage']]);

        return response()->json([
            'message' => 'Stage updated successfully',
            'data'    => $lead
        ]);
    }
}