<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$gate = file_get_contents($root . '/modelo/v2/M06IncidenceWriteCutover.php');
$failures = 0;
$passes = 0;
$check = static function (bool $ok, string $name) use (&$failures, &$passes): void {
    if ($ok) {
        $passes++;
        echo "PASS {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}\n";
    }
};

$branchMarker = <<<'ROUTE'
} else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'incidences') {
ROUTE;
$endMarker = <<<'ROUTE'
} else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'administrative-evaluations')
ROUTE;
$branchStart = strpos($index, $branchMarker);
$branchEnd = strpos($index, $endMarker, $branchStart ?: 0);
$branch = $branchStart !== false && $branchEnd !== false ? substr($index, $branchStart, $branchEnd - $branchStart) : '';
$gatePos = strpos($branch, 'M06IncidenceWriteCutover::sendDisabledResponse()');
$controllerIncludePos = strpos($branch, 'AdministrativeIncidenceV2Controller.php');
$handlers = [
    'create' => 'handleCreateIncident()',
    'revision' => 'handleCreateIncidentRevision(',
    'resolve' => 'handleResolveIncident(',
    'authorize' => 'handleAuthorizeIncident(',
    'reject' => 'handleRejectIncident(',
    'revoke' => 'handleRevokeIncident(',
];
$allHandlersBehindGate = $gatePos !== false && $controllerIncludePos !== false && $gatePos < $controllerIncludePos;
foreach ($handlers as $name => $handler) {
    $handlerPos = strpos($branch, $handler);
    $allHandlersBehindGate = $allHandlersBehindGate && $handlerPos !== false && $gatePos < $handlerPos;
}
$check($allHandlersBehindGate, 'the router rejects before loading or dispatching any of the six mutating handlers');
$readCollection = <<<'ROUTE'
if ($server_method === 'GET') {
                $incCtrl->handleReadApplicableIncidences();
ROUTE;
$singleRead = '$incCtrl->handleReadSingleIncident(';
$check(str_contains($branch, $readCollection)
    && str_contains($branch, $singleRead), 'incidence GET dispatch remains present');
$check(str_contains($gate, "'code' => self::ERROR_CODE")
    && str_contains($gate, "http_response_code(503)")
    && str_contains($gate, "M06_INCIDENCE_WRITES_DISABLED"), 'closed response is HTTP 503 with stable M06 code');
$check(!str_contains($gate, 'getenv(') && !str_contains($gate, '$_ENV') && !str_contains($gate, '$_SERVER'), 'cutover uses no new environment flag');

$routes = [
    '/api/v2/incidences',
    '/api/v2/incidences/00000000-0000-4000-8000-000000000001/revisions',
    '/api/v2/incidences/00000000-0000-4000-8000-000000000001/resolve',
    '/api/v2/incidences/00000000-0000-4000-8000-000000000001/authorize',
    '/api/v2/incidences/00000000-0000-4000-8000-000000000001/reject',
    '/api/v2/incidences/00000000-0000-4000-8000-000000000001/revoke',
];
$phpCgi = PHP_BINDIR . '/php-cgi';
$check(is_executable($phpCgi), 'PHP CGI SAPI is available for isolated route verification');
if (is_executable($phpCgi)) {
    foreach ($routes as $route) {
        $bodyPayload = '{"isolated_cutover_test":true}';
        $env = [
            'REDIRECT_STATUS' => '200',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/portal-api-v.1.0.1' . $route,
            'SCRIPT_FILENAME' => $root . '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($bodyPayload),
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$phpCgi], $descriptors, $pipes, $root, $env);
        if (!is_resource($process)) {
            $check(false, 'isolated PHP CGI request starts for ' . $route);
            continue;
        }
        fwrite($pipes[0], $bodyPayload);
        fclose($pipes[0]);
        $response = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $separator = strpos((string) $response, "\r\n\r\n");
        if ($separator === false) $separator = strpos((string) $response, "\n\n");
        $headers = $separator === false ? (string) $response : substr((string) $response, 0, $separator);
        $body = $separator === false ? '' : substr((string) $response, $separator + (str_contains($headers, "\r\n") ? 4 : 2));
        $status = preg_match('/^Status:\s*(\d{3})/mi', $headers, $matches) ? (int) $matches[1] : 0;
        $json = json_decode($body, true);
        $check($exitCode === 0 && $status === 503
            && ($json['error']['code'] ?? null) === 'M06_INCIDENCE_WRITES_DISABLED'
            && trim((string) $stderr) === '', 'isolated CGI POST ' . $route . ' returns HTTP 503 before DB/controller mutation');
    }
}

$addendumPortal = file_get_contents('/home/silvinol/Proyectos/Portal3/docs/v2/CHECADOR_V2_M06_PORTAL3_SQL_FULL_LIFECYCLE_ADDENDUM_V1.0.md');
$addendumApi = file_get_contents($root . '/docs/v2/CHECADOR_V2_M06_PORTAL3_SQL_FULL_LIFECYCLE_ADDENDUM_V1.0.md');
$check(is_string($addendumPortal) && $addendumApi === $addendumPortal, 'Portal3 and API M06 addenda are byte-identical');
$evaluationMarker = <<<'ROUTE'
} else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'administrative-evaluations')
ROUTE;
$adjustmentsMarker = <<<'ROUTE'
} else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'administrative-adjustments')
ROUTE;
$evaluationStart = strpos($index, $evaluationMarker);
$adjustmentsStart = strpos($index, $adjustmentsMarker, $evaluationStart ?: 0);
$evaluationBranch = $evaluationStart !== false && $adjustmentsStart !== false
    ? substr($index, $evaluationStart, $adjustmentsStart - $evaluationStart) : '';
$evaluationRead = '$evalCtrl->handleReadAdministrativeEvaluation()';
$evaluationPost = '$evalCtrl->handleExecuteAdministrativeEvaluation()';
$check(str_contains($evaluationBranch, $evaluationRead)
    && str_contains($evaluationBranch, $evaluationPost), 'administrative evaluation GET and POST dispatch remain outside the M06 write gate');

printf("\nM06_API_INCIDENCE_CUTOVER: %d PASS / %d FAIL\n", $passes, $failures);
exit($failures === 0 ? 0 : 1);
