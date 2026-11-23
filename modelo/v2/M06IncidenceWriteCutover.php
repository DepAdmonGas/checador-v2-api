<?php
/**
 * M06 single-writer cutover: API administrative incidence writes are retired.
 * GET reads and administrative evaluations remain routed separately.
 */
final class M06IncidenceWriteCutover
{
    public const ERROR_CODE = 'M06_INCIDENCE_WRITES_DISABLED';

    public static function sendDisabledResponse(): void
    {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'status' => 'error',
            'code' => 503,
            'error' => [
                'code' => self::ERROR_CODE,
                'message' => 'Las escrituras administrativas M06 están deshabilitadas en API V2.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
