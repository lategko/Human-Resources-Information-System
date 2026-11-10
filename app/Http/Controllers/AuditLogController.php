<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = AuditLog::with('user')->latest();
        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action')->toString().'%');
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date('date'));
        }

        return view('audit.index', ['logs' => $query->paginate(30)->withQueryString()]);
    }
}
