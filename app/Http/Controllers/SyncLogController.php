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

        if ($request->filled('type') && in_array($request->type, ['Order', 'Customer', 'product'])) {
            $query->where('entity_type', $request->type);
        }

        if ($request->filled('status') && in_array($request->status, ['success', 'failed'])) {
            $query->where('status', $request->status);
        }

        $logs = $query->paginate(20)->withQueryString();
        
        return view('sync-logs.index', compact('logs'));
    }
}
