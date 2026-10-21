<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Control central de errores de la API. Ninguna respuesta revela detalles internos (SQL, tablas,
 * clases, rutas de archivos, motor de base de datos ni framework), ni siquiera con APP_DEBUG=true.
 *
 * - Validación (422): se devuelven los mensajes de los campos, que son textos propios.
 * - Errores HTTP conocidos (401, 403, 404, 429...): un mensaje genérico y entendible por código.
 * - Cualquier otro error (500): mensaje genérico + una referencia corta. El detalle completo queda
 *   solo en el log del servidor con esa misma referencia, para poder rastrearlo.
 */
class ErroresSeguros
{
    private const MENSAJES = [
        400 => 'La solicitud no es válida. Revisa los datos e inténtalo de nuevo.',
        401 => 'Tu sesión no es válida o venció. Vuelve a iniciar sesión.',
        403 => 'No tienes permiso para realizar esta acción.',
        404 => 'No encontramos lo que buscas.',
        405 => 'Esta acción no está permitida.',
        408 => 'La solicitud tardó demasiado. Inténtalo de nuevo.',
        409 => 'La información cambió mientras trabajabas. Actualiza e inténtalo de nuevo.',
        413 => 'El archivo es demasiado grande.',
        419 => 'Tu sesión expiró. Actualiza la página e inténtalo de nuevo.',
        422 => 'Revisa los datos ingresados.',
        429 => 'Hiciste demasiadas solicitudes seguidas. Espera un momento e inténtalo de nuevo.',
        503 => 'El servicio está en mantenimiento. Inténtalo de nuevo en unos minutos.',
    ];

    /** Referencia asignada a cada excepción al registrarla en el log, para repetirla en la respuesta. */
    private static array $referencias = [];

    public static function registrar(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Laravel registra (report) antes de responder (render): el log lleva la misma referencia que ve el usuario.
        $exceptions->report(function (Throwable $e) {
            $referencia = self::referencia($e);
            Log::error("[{$referencia}] ".$e::class.': '.$e->getMessage(), ['exception' => $e]);

            return false; // ya quedó en el log
        });

        $exceptions->render(fn (Throwable $e, Request $request) => self::responder($e));
    }

    private static function referencia(Throwable $e): string
    {
        if (count(self::$referencias) > 100) {
            self::$referencias = [];
        }

        return self::$referencias[spl_object_id($e)] ??= 'E-'.Str::upper(Str::random(8));
    }

    public static function responder(Throwable $e): ?JsonResponse
    {
        if ($e instanceof ValidationException) {
            return null; // mensajes de campo propios (422)
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, 'no_autenticado');
        }

        if ($e instanceof AuthorizationException) {
            return self::json(403, 'sin_permiso');
        }

        if ($e instanceof HttpExceptionInterface) {
            $estado = $e->getStatusCode();
            if ($estado < 500) {
                return self::json($estado, 'error_solicitud', headers: $e->getHeaders());
            }
        }

        $referencia = self::referencia($e);
        $estado = $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 ? 503 : 500;
        $mensaje = $estado === 503
            ? self::MENSAJES[503]
            : 'Ocurrió un problema inesperado y no pudimos completar la operación. Inténtalo de nuevo en unos minutos. '
              ."Si el problema continúa, comunica al administrador la referencia {$referencia}.";

        return self::json($estado, 'error_interno', $mensaje, ['referencia' => $referencia]);
    }

    private static function json(int $estado, string $codigo, ?string $mensaje = null, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['codigo' => $codigo, 'message' => $mensaje ?? self::MENSAJES[$estado] ?? 'No se pudo procesar la solicitud.'] + $extra,
            $estado,
            array_intersect_key($headers, array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Allow'])),
        );
    }
}
