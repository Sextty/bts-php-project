<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * One builder for the standard success envelope {success: true, data: ...} that every API
 * controller returns — replaces ~30 hand-built response()->json([...]) calls so the shape
 * cannot drift between controllers.
 */
final class ApiResponse
{
    public static function ok(mixed $data = null): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    public static function created(mixed $data = null): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null]);
    }
}
