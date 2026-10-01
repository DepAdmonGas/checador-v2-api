<?php
include_once "BD.php";

class Empresa{

    function validaToken($token){

        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

    $sql = "SELECT id_estacion, token FROM op_rh_localidades_perfil
    WHERE token = '".$token."' ";
    $result_root = mysqli_query($conexion, $sql);
    $row_root = mysqli_num_rows($result_root);
    if ($row_root > 0) {
    
        $return = 1;

    }else{
    
        $return = 0;

    }

    $ClassConexionBD->desconectarBD($conexion);
    return $return;

    }

    function idEmpresa($key, $value){

        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

            $sql = "SELECT id_estacion FROM op_rh_localidades_perfil WHERE $key = '".$value."' ";
            $result_root = mysqli_query($conexion, $sql);
            $row_root = mysqli_num_rows($result_root);    
            while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){
            $idEmpresa = $row_root['id_estacion'];
            }

            $ClassConexionBD->desconectarBD($conexion);
            return $idEmpresa;
    
    }

    function validaPersonalEmpresa($idEmpresa, $idpersonal){

        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

        $sql = "SELECT id, id_estacion FROM op_rh_personal
        WHERE id = '".$idpersonal."' AND id_estacion = '".$idEmpresa."' ";
        $result_root = mysqli_query($conexion, $sql);
        $row_root = mysqli_num_rows($result_root);

        if ($row_root > 0) {
        
        $return = array(
            "idEstacion" => $idEmpresa,
            "estado" => 1);

        }else{
        
        $sqlPersonal = "SELECT id, id_estacion FROM op_rh_personal
        WHERE id = '".$idpersonal."'  ";
        $resultPersonal = mysqli_query($conexion, $sqlPersonal);
        $numPersonal = mysqli_num_rows($resultPersonal);
        if ($numPersonal > 0) {

        while($rowPersonal = mysqli_fetch_array($resultPersonal, MYSQLI_ASSOC)){
        $idEstacion = $rowPersonal['id_estacion'];
        }

        if($idEstacion == 9){

        $return = array(
            "idEstacion" => $idEstacion,
            "estado" => 1); 

        }else{
        $return = array(
            "idEstacion" => 0,
            "estado" => 0); 
        }

        }else{
        $return = array(
            "idEstacion" => 0,
            "estado" => 0);
        }

        }

        $ClassConexionBD->desconectarBD($conexion);
        return $return;

    }


}