<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/../modelo/v2/shift/JourneyReadAuthorizationService.php';
require_once __DIR__ . '/../modelo/v2/journey/PersistedJourneyReadV2Controller.php';
require_once __DIR__ . '/../modelo/v2/IncidenceCollectionReadV2Controller.php';

ob_start();
$passes = 0;
$fails = 0;
$assert = static function (bool $condition, string $label) use (&$passes, &$fails): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $condition ? $passes++ : $fails++;
};

$journeySource = file_get_contents(__DIR__ . '/../modelo/v2/journey/PersistedJourneyReadV2Controller.php');
$incidenceSource = file_get_contents(__DIR__ . '/../modelo/v2/IncidenceCollectionReadV2Controller.php');
$assert(is_string($journeySource)
    && str_contains($journeySource, "\$auth['code'] ?? 401")
    && str_contains($journeySource, "\$auth['status'] ?? 'UNAUTHORIZED'"),
    'C01 maps HTTP status from the helper code and preserves its semantic error code');
$assert(is_string($journeySource) && str_contains($journeySource, "require_once dirname(__DIR__, 2) . '/BD.php';"),
    'C01 controller loads the shared API BD.php connection class');
$assert(is_string($incidenceSource)
    && str_contains($incidenceSource, "\$auth['code'] ?? 401")
    && str_contains($incidenceSource, "\$auth['status'] ?? 'UNAUTHORIZED'")
    && str_contains($incidenceSource, "\$authz['code'] ?? 403")
    && str_contains($incidenceSource, "\$authz['status'] ?? 'FUNCTION_PERMISSION_DENIED'"),
    'C02 maps HTTP status from helper codes for authentication and authorization failures');
$assert(is_string($incidenceSource) && str_contains($incidenceSource, "require_once __DIR__ . '/../BD.php';"),
    'C02 controller loads the shared API BD.php connection class');

$missingJwt = Portal3JwtAuthHelper::validateToken(null, '');
$journeyDenied = JourneyReadAuthorizationService::authorize(null, ['user_id' => 7, 'id_puesto' => 14]);
$incidenceDenied = Portal3JwtAuthHelper::authorizeIncidences(null, [], 'invalid-permission');
$assert(($missingJwt['code'] ?? null) === 401 && ($missingJwt['status'] ?? null) === 'UNAUTHORIZED',
    'JWT helper distinguishes HTTP 401 code from UNAUTHORIZED semantic status');
$assert(($journeyDenied['code'] ?? null) === 403 && ($journeyDenied['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED',
    'C01 authorization service distinguishes HTTP 403 code from permission status');
$assert(($incidenceDenied['code'] ?? null) === 403 && ($incidenceDenied['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED',
    'C02 authorization helper distinguishes HTTP 403 code from permission status');

$assertHttpError = static function (object $controller, string $expectedCode, int $expectedHttp) use ($assert): void {
    $method = new ReflectionMethod($controller, 'error');
    $method->setAccessible(true);
    http_response_code(200);
    ob_start();
    $method->invoke($controller, $expectedHttp, $expectedCode);
    $body = (string) ob_get_clean();
    $json = json_decode($body, true);
    $assert(http_response_code() === $expectedHttp
        && ($json['status'] ?? null) === 'error'
        && ($json['error']['code'] ?? null) === $expectedCode,
        get_class($controller) . " emits HTTP {$expectedHttp} with the unchanged error envelope");
};

$assertHttpError((new ReflectionClass(PersistedJourneyReadV2Controller::class))->newInstanceWithoutConstructor(), 'UNAUTHORIZED', 401);
$assertHttpError((new ReflectionClass(PersistedJourneyReadV2Controller::class))->newInstanceWithoutConstructor(), 'FUNCTION_PERMISSION_DENIED', 403);
$assertHttpError((new ReflectionClass(IncidenceCollectionReadV2Controller::class))->newInstanceWithoutConstructor(), 'UNAUTHORIZED', 401);
$assertHttpError((new ReflectionClass(IncidenceCollectionReadV2Controller::class))->newInstanceWithoutConstructor(), 'FUNCTION_PERMISSION_DENIED', 403);

$assertHttpSuccess = static function (object $controller) use ($assert): void {
    $method = new ReflectionMethod($controller, 'success');
    $method->setAccessible(true);
    http_response_code(500);
    ob_start();
    $method->invoke($controller, ['probe' => true]);
    $body = (string) ob_get_clean();
    $json = json_decode($body, true);
    $assert(http_response_code() === 200
        && ($json['status'] ?? null) === 'success'
        && ($json['data']['probe'] ?? null) === true,
        get_class($controller) . ' emits HTTP 200 with the unchanged success envelope');
};
$assertHttpSuccess((new ReflectionClass(PersistedJourneyReadV2Controller::class))->newInstanceWithoutConstructor());
$assertHttpSuccess((new ReflectionClass(IncidenceCollectionReadV2Controller::class))->newInstanceWithoutConstructor());

echo "RESULT: {$passes} PASS / {$fails} FAIL" . PHP_EOL;
ob_end_flush();
exit($fails === 0 ? 0 : 1);
