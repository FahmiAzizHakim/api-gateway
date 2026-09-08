<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

class ApiExceptionHandler
{
    public static function render(Throwable $e): JsonResponse
    {
        // Validation
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }

        // JWT
        if ($e instanceof TokenExpiredException) {
            return response()->json([
                'message' => 'Token expired',
            ], 401);
        }

        if ($e instanceof TokenInvalidException) {
            return response()->json([
                'message' => 'Token invalid',
            ], 401);
        }

        if ($e instanceof JWTException) {
            return response()->json([
                'message' => 'Token not provided',
            ], 401);
        }

        // Auth
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        // Forbidden
        if ($e instanceof AccessDeniedHttpException) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        // Not found
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'message' => 'Url not found',
            ], 404);
        }

        // Method not allowed
        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'message' => 'Method not allowed',
            ], 405);
        }

        // Other HTTP exceptions
        if ($e instanceof HttpExceptionInterface) {
            return response()->json([
                'message' => $e->getMessage() ?: 'HTTP error',
            ], $e->getStatusCode());
        }

        // Fallback (500)
        return response()->json([
            'message' => 'Internal server error',
        ], 500);
    }
}
