<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The staff inbox for in-app notifications (new applications, new report messages). Same shape
 * as the customer inbox; the private-staff / private-admin WebSocket channels are only the live
 * push, this endpoint is the authoritative list.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AppNotification::forNotifiable($request->user())
            ->latest()
            ->limit(min((int) $request->query('limit', 50), 100));

        return AppNotificationResource::collection($query->get());
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $id = $request->query('id');

        $query = AppNotification::forNotifiable($request->user());

        if ($id !== null) {
            $query->where('id', (int) $id);
        } else {
            $query->unread();
        }

        $query->get()->each->markAsRead();

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}