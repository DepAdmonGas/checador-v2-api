<?php

class BD
{

    function conectarBD()
    {

        $server = getenv('DB_HOST');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        $bd = getenv('DB_NAME');
        $portRaw = getenv('DB_PORT');
        $port = ($portRaw === false || trim($portRaw) === '') ? 3306 : filter_var($portRaw, FILTER_VALIDATE_INT);

        if ($server === false || $server === '') {
            throw new RuntimeException("Database configuration error: DB_HOST is required and not set.");
        }
        if ($user === false || $user === '') {
            throw new RuntimeException("Database configuration error: DB_USER is required and not set.");
        }
        if ($pass === false) {
            throw new RuntimeException("Database configuration error: DB_PASS is required and not set.");
        }
        if ($bd === false || $bd === '') {
            throw new RuntimeException("Database configuration error: DB_NAME is required and not set.");
        }
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException("Database configuration error: DB_PORT must be a valid TCP port.");
        }

        $con = mysqli_connect($server, $user, $pass, $bd, $port);

        if (!$con) {
            echo "Error: No se pudo conectar a MySQL." . PHP_EOL;
            exit;
        }

        if (!mysqli_set_charset($con, "utf8")) {
            exit();
        }

        return $con;
    }

    function desconectarBD($conexion)
    {

        $close = mysqli_close($conexion)
            or die("Ha sucedido un error inexperado en la desconexion de la base de datos");
        return $close;
    }
}
