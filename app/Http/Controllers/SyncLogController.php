<?php

namespace App\Http\Controllers;

use App\Models\SyncLog;
use Illuminate\Http\Request;

class SyncLogController extends Controller
{
    /**
     * Display a listing of the sync logs.
     */
    public function index(Request $request)
    {
        $query = SyncLog::latest();

        if ($request->filled('type')) {
            $type = strtolower($request->type);
            $query->whereRaw('LOWER(entity_type) = ?', [$type]);
        }

        if ($request->filled('status') && in_array($request->status, ['success', 'failed'])) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('entity_id', 'like', "%{$search}%")
                  ->orWhere('payload', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(20)->withQueryString();
        
        return view('sync-logs.index', compact('logs'));
    }

    /**
     * Return JSON payload and metadata for a specific sync log.
     */
    public function payload(SyncLog $syncLog)
    {
        return response()->json([
            'success' => true,
            'log' => [
                'id' => $syncLog->id,
                'entity_type' => $syncLog->entity_type,
                'entity_id' => $syncLog->entity_id,
                'direction' => $syncLog->direction,
                'status' => $syncLog->status,
                'created_at' => $syncLog->created_at?->format('M d, Y h:i:s A'),
                'error_message' => $syncLog->error_message,
                'payload' => $syncLog->payload_array ?? $syncLog->payload,
                'formatted_json' => $syncLog->formatted_payload,
            ]
        ]);
    }
}
