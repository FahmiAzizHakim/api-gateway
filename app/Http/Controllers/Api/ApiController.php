<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Shared response shaping for the gateway's JSON endpoints.
 *
 * The Service layer already answers in one shape -- ['status', 'message',
 * 'data'] -- so translating it lives here once instead of in every action.
 */
abstract class ApiController extends Controller
{
    /**
     * A service result as an HTTP response. A 'failed' status is a rejected
     * write, not a server fault, so it answers 422.
     */
    protected function respond(array $result, int $okStatus = 200): JsonResponse
    {
        $ok = ($result['status'] ?? 'failed') === 'success';

        $body = ['message' => $result['message'] ?? null];

        if (array_key_exists('data', $result)) {
            $body['data'] = $result['data'];
        }

        return response()->json($body, $ok ? $okStatus : 422);
    }

    protected function notFound(string $subject): JsonResponse
    {
        return response()->json(['message' => $subject . ' not found'], 404);
    }

    protected function items($rows): JsonResponse
    {
        return response()->json(['data' => $rows]);
    }
}
