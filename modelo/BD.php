<?php

class BD
{

    function conectarBD()
    {

        $server = "localhost";
        $user = "root";
        $pass = "kimera";
        $bd = "bd_admongas";

        $con = mysqli_connect($server, $user, $pass, $bd);

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
