<?php

declare(strict_types=1);
require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DeviceLinkV2Service.php';
require_once __DIR__ . '/DeviceLinkRateLimit.php';

final class DeviceLinkV2Controller
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { header('Allow: POST'); $this->response(405,'METHOD_NOT_ALLOWED'); }
        try {
            if (!DeviceLinkRateLimit::allow((string)getenv('CHECADOR_DEVICE_LINK_RATE_DIR'), (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
                header('Retry-After: 60'); $this->response(429,'RATE_LIMITED');
            }
        } catch (Throwable) { $this->response(503,'LINK_UNAVAILABLE'); }
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) $this->response(415,'UNSUPPORTED_MEDIA_TYPE');
        $raw = file_get_contents('php://input', false, null, 0, 1025);
        if (!is_string($raw) || strlen($raw) > 1024) $this->response(400,'BAD_REQUEST');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || count($payload) !== 1 || !DeviceLinkV2Service::validToken($payload['link_token'] ?? null)) $this->response(400,'BAD_REQUEST');
        try {
            $db = (new BD())->conectarBD();
            $result = (new DeviceLinkV2Service())->consume($db, $payload['link_token']);
            $db->close();
            if ($result === null) $this->response(401,'LINK_DENIED');
            http_response_code(200);
            echo json_encode(['code'=>200,'status'=>'OK','data'=>$result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        } catch (Throwable) { $this->response(503,'LINK_UNAVAILABLE'); }
    }
    private function response(int $code, string $status): never
    {
        http_response_code($code); echo json_encode(['code'=>$code,'status'=>$status]); exit;
    }
}
