<?php
class CodeResponse {

    function Code($codigo){

        date_default_timezone_set('America/Mexico_City');
        $fecha_del_dia = date("Y-m-d");
        $hora_del_dia = date("H:i:s");
        $hoy = date("Y-m-d H:i:s");

        if ($codigo == 200)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 200,
            "status" => "OK",
            )          
            ); 
        }
        else if ($codigo == 201)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 201,
            "status" => "CREADO",
            )          
            ); 
        }
        else if ($codigo == 202)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 202,
            "status" => "ACEPTADO",
            )          
            ); 
        }
        else if ($codigo == 204)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 204,
            "status" => "SIN CONTENIDO",
            )          
            ); 
        }
        else if ($codigo == 400)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 400,
            "status" => "SOLICITUD INCORRECTA",
            )          
            ); 
        }
        else if ($codigo == 401)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 401,
            "status" => "NO AUTORIZADO",
            )          
            ); 
        }
        else if ($codigo == 403)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 403,
            "status" => "PROHIBIDO",
            )          
            ); 
        }
        else if ($codigo == 404)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 404,
            "status" => "NO ENCONTRADO",
            )          
            ); 
        }
        else if ($codigo == 500)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 500,
            "status" => "ERROR INTERNO",
            )          
            ); 
        }
        else if ($codigo == 501)
        {
            $return = array (
            "resultado"  => array (
            "fecha" => $fecha_del_dia,
            "hora" => $hora_del_dia,
            "code" => 501,
            "status" => "NO IMPLEMENTADO",
            )          
            ); 
        }

        return $return;
    }

}