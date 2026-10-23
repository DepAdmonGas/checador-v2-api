<?php
http_response_code(404);

global $partes_ruta;
if (isset($partes_ruta[1], $partes_ruta[2]) && $partes_ruta[1] === 'api' && $partes_ruta[2] === 'v2') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "code" => 404,
        "status" => "NOT_FOUND",
        "mensaje" => "Ruta no encontrada."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<html lang="es">
  <head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <title>Error 404</title>
  <meta name="description" content="">
  <meta name="viewport" content="width=device-width initial-scale=1.0">
  <style>
  body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
  font-size: 1.3rem;
  font-weight: 400;
  line-height: 1.5;
  color: #212529;
  text-align: left;
  background-color: #fff;
  }
  </style>
  </head>
  <body>

    <div style="text-align: center;margin-top: 15rem;">
    <strong style="font-size: 1.5rem;">404.</strong> Se encontro un error.<br/>
    La URL solicitada no se encontró, vuelva a intentarlo.
    </div>

  </body>
  </html>

