<?php

namespace Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Security\Models\AccessLog;

class AccessLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $logs = AccessLog::query()
            ->with('user:id,username,first_name,last_name')
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')))
            ->when($request->filled('platform'), fn ($query) => $query->where('platform', $request->string('platform')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($q) => $q
                ->where('login', 'like', "%{$request->string('search')}%")
                ->orWhere('ip_address', 'like', "%{$request->string('search')}%")))
            ->when($request->date('date_from'), fn ($query, $date) => $query->where('created_at', '>=', $date->startOfDay()))
            ->when($request->date('date_to'), fn ($query, $date) => $query->where('created_at', '<=', $date->endOfDay()))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return $this->paginated($logs, fn (AccessLog $log): array => [
            'id' => $log->id,
            'event' => $log->event->value,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'username' => $log->user->username,
                'full_name' => $log->user->full_name,
            ] : null,
            'login' => $log->login,
            'platform' => $log->platform?->value,
            'device_id' => $log->device_id,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'detail' => $log->detail,
            'created_at' => $log->created_at->toISOString(),
        ]);
    }
}
