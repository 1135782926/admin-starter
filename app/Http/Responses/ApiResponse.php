<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'code' => 200,
            'msg' => '操作成功',
            'data' => $data,
        ], $status);
    }

    /** @param array<string, mixed>|null $errors */
    public static function error(string $message, int $status, ?array $errors = null): JsonResponse
    {
        $payload = [
            'code' => $status,
            'msg' => $message,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
