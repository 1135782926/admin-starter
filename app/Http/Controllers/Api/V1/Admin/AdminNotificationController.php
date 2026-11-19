<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\AdminNotificationsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNotificationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'required', 'integer', 'min:1']]);
        $admin = $request->user();
        $page = $admin->notifications()->orderByDesc('id')->paginate(15);

        return ApiResponse::success([
            'records' => AdminNotificationResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
            'unread_count' => $admin->unreadNotifications()->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $admin = $request->user();
        $updated = $admin->unreadNotifications()->reorder()->whereKey($notification)->update(['read_at' => now()]);

        if ($updated) {
            AdminNotificationsUpdated::dispatch($admin->id);
        } else {
            $admin->notifications()->findOrFail($notification, ['id']);
        }

        return ApiResponse::success();
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $admin = $request->user();
        if ($admin->unreadNotifications()->reorder()->update(['read_at' => now()])) {
            AdminNotificationsUpdated::dispatch($admin->id);
        }

        return ApiResponse::success();
    }

    public function authorizeChannel(Request $request): JsonResponse
    {
        abort_unless(config('broadcasting.default') === 'reverb', 503, '实时通知服务未启用。');

        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A[0-9]+\.[0-9]+\z/'],
            'channel_name' => ['required', 'string', 'max:64', 'regex:/\Aprivate-admin\.[1-9][0-9]*\z/'],
        ]);

        return response()->json(Broadcast::auth($request))->header('Cache-Control', 'no-store');
    }
}
