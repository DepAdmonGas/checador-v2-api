<?php
include_once "BD.php";
include_once "Empresa.php";
include_once "Encriptar.php";
include_once "CodeResponse.php";

class Personal{

    function listaPersonal($token){

        date_default_timezone_set('America/Mexico_City');
        $fecha_del_dia = date("Y-m-d");
        $hora_del_dia = date("H:i:s");
        $hoy = date("Y-m-d H:i:s");

        $ClassConexionBD = new BD();
        $ClassEncriptar = new Encriptar();
        $ClassEmpresa = new Empresa();
        $ClassCodeResponse = new CodeResponse();
        
        $conexion = $ClassConexionBD->conectarBD();        
        $validaToken = $ClassEmpresa->validaToken($token);

        $array = array();
        $num = 1;

        if($validaToken == 1){
            $idEmpresa = $ClassEmpresa->idEmpresa("token", $token);
            $sql = "SELECT 
            op_rh_personal.id,
            op_rh_personal.id_estacion,
            op_rh_personal.nombre_completo,
            op_rh_puestos.puesto,
            op_rh_personal.estado,
            op_rh_personal_acceso.huella,
            op_rh_personal_acceso.pin
            FROM op_rh_personal
            INNER JOIN op_rh_personal_acceso 
            ON op_rh_personal.id = op_rh_personal_acceso.id_personal
            INNER JOIN op_rh_puestos
            ON op_rh_personal.puesto = op_rh_puestos.id
            WHERE op_rh_personal.id_estacion = '".$idEmpresa."' AND op_rh_personal.estado = 1 ";
            $result_root = mysqli_query($conexion, $sql);
            $row_root = mysqli_num_rows($result_root);   
            if ($row_root > 0) {
                while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){     
                $id = $row_root['id'];                        
                $idempleado = $ClassEncriptar->encrypt($row_root['id']);
                $nombre = $row_root['nombre_completo'];
                $puesto = $row_root['puesto'];     
                if($row_root['huella'] == ""){
                    $huella = 0;
                }else{
                    $huella = $row_root['huella'];
                }
                if($row_root['pin'] == 0){
                    $pin = 0;
                }else{
                    $pin = $row_root['pin'];
                }      
                
                $horarioPersonal = $this->Horario($id);

                $data[] = array(
                    "idpersonal" => $idempleado,
                    "num" => $num,
                    "nombre" => $nombre,
                    "puesto" => $puesto,
                    "huella" => $huella,
                    "pin" => $pin,
                    "horario" => $horarioPersonal,
                );
                $num++;
            }

            if($idEmpresa == 2){

            $sql = "SELECT 
            op_rh_personal.id,
            op_rh_personal.id_estacion,
            op_rh_personal.nombre_completo,
            op_rh_puestos.puesto,
            op_rh_personal.estado,
            op_rh_personal_acceso.huella,
            op_rh_personal_acceso.pin
            FROM op_rh_personal
            INNER JOIN op_rh_personal_acceso 
            ON op_rh_personal.id = op_rh_personal_acceso.id_personal
            INNER JOIN op_rh_puestos
            ON op_rh_personal.puesto = op_rh_puestos.id
            WHERE op_rh_personal.id_estacion = 9 AND op_rh_personal.estado = 1 ";
            $result_root = mysqli_query($conexion, $sql);
            $row_root = mysqli_num_rows($result_root);   

            while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){     
                $id = $row_root['id'];                        
                $idempleado = $ClassEncriptar->encrypt($row_root['id']);
                $nombre = $row_root['nombre_completo'];
                $puesto = $row_root['puesto'];     
                if($row_root['huella'] == ""){
                    $huella = 0;
                }else{
                    $huella = $row_root['huella'];
                }
                if($row_root['pin'] == 0){
                    $pin = 0;
                }else{
                    $pin = $row_root['pin'];
                }      
                
                $horarioPersonal = $this->Horario($id);

                $data[] = array(
                    "idpersonal" => $idempleado,
                    "num" => $num,
                    "nombre" => $nombre,
                    "puesto" => $puesto,
                    "huella" => $huella,
                    "pin" => $pin,
                    "horario" => $horarioPersonal,
                );


                $num++;
            }

            }
                
                $return = array (
                    "resultado"  => array (
                        "empleados" => $data,
                        "fecha" => $fecha_del_dia,
                        "hora" => $hora_del_dia,
                        "code" => 200,
                        "status" => "OK",
                    )                
                );   
                         
            }else{ 
            $return = $ClassCodeResponse->Code(500);  
            }
        }else{
            $return = $ClassCodeResponse->Code(401);  
        }
        return $return;
    }

        function Horario($id){

        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD(); 
        $sql_a = "SELECT dia, hora_entrada, hora_salida FROM op_rh_personal_horario
                WHERE id_personal = '".$id."' ";
        $result_a = mysqli_query($conexion, $sql_a);
        $num = mysqli_num_rows($result_a);
                if($num > 0){
                while($row_a = mysqli_fetch_array($result_a, MYSQLI_ASSOC)){   
                $dia = $row_a['dia'];
                $hora_entrada = $row_a['hora_entrada'];
                $horasalida = $row_a['hora_salida'];
                
                $data[] = array(
                    "dia" => $dia,
                    "entrada" => $hora_entrada,
                    "salida" => $horasalida
                    
                );

                }
            }else{
                $data[] = array(
                    "dia" => '',
                    "entrada" => '',
                    "salida" => ''
                    
                ); 
            }

                return $data;
    }

    /* 
    ---
    INICIO DE LA FUNCION PARA EDITAR EL PIN DEL USUARIO SELECCIONADO 
    ---
    */
    function EditarPIN($token, $idpersonal, $pinpersonal){

        $ClassConexionBD = new BD();
        $ClassEncriptar = new Encriptar();
        $ClassEmpresa = new Empresa();
        $ClassCodeResponse = new CodeResponse();

        $conexion = $ClassConexionBD->conectarBD();        
        $validaToken = $ClassEmpresa->validaToken($token);

        if($validaToken == 1){

            if(!empty($idpersonal) && !empty($pinpersonal)){
                $idPDecrypt = $ClassEncriptar->decrypt($idpersonal);
                $idEmpresa = $ClassEmpresa->idEmpresa("token", $token);
                $validaPersonalEmpresa = $ClassEmpresa->validaPersonalEmpresa($idEmpresa,$idPDecrypt);
                if($validaPersonalEmpresa['estado'] == 1){
                
                if(strlen($pinpersonal) >= 5){

                $ValidaPin = $this->ValidaPin($pinpersonal);

                if($ValidaPin == 0){

                    $sql_edit = "UPDATE op_rh_personal_acceso SET 
                    pin = '".$pinpersonal."'
                    WHERE id_personal = '".$idPDecrypt."' ";   

                    if(mysqli_query($conexion, $sql_edit)) {
                        $return = $ClassCodeResponse->Code(202);
                    }else{
                        $return = $ClassCodeResponse->Code(500);
                    }
    
                }else{
                    $return = $ClassCodeResponse->Code(401); 
                }

                }else{
                    $return = $ClassCodeResponse->Code(400);
                }                
                }else{    
                    $return = $ClassCodeResponse->Code(404);    
                } 
        }else{
            $return = $ClassCodeResponse->Code(204);
        }

        }else{
            $return = $ClassCodeResponse->Code(401);
        }

        return $return;

    }

    function ValidaPin($pinpersonal){
        $ClassConexionBD = new BD();   
        $conexion = $ClassConexionBD->conectarBD();
        $sql = "SELECT pin FROM op_rh_personal_acceso
        WHERE pin = '".$pinpersonal."' ";
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
    /* 
    ---
    FIN DE LA FUNCION PARA EDITAR EL PIN DEL USUARIO SELECCIONADO 
    ---
    */


    /* 
    ---
    INICIO DE LA FUNCION PARA EDITAR LA HUELLA DEL USUARIO SELECCIONADO 
    ---
    */
    function EditarHUELLA($token, $idpersonal, $huellapersonal){
        $ClassConexionBD = new BD();
        $ClassEncriptar = new Encriptar();
        $ClassEmpresa = new Empresa();
        $ClassCodeResponse = new CodeResponse();

        $conexion = $ClassConexionBD->conectarBD();        
        $validaToken = $ClassEmpresa->validaToken($token);

        if($validaToken == 1){
        if(!empty($idpersonal) && !empty($huellapersonal)){
            $idPDecrypt = $ClassEncriptar->decrypt($idpersonal);
            $idEmpresa = $ClassEmpresa->idEmpresa("token", $token);
            $validaPersonalEmpresa = $ClassEmpresa->validaPersonalEmpresa($idEmpresa,$idPDecrypt);
            if($validaPersonalEmpresa['estado'] == 1){

                $sql_edit = "UPDATE op_rh_personal_acceso SET 
                huella = '".$huellapersonal."'
                WHERE id_personal = '".$idPDecrypt."' ";   

                if(mysqli_query($conexion, $sql_edit)) {
                    $return = $ClassCodeResponse->Code(202);
                }else{
                    $return = $ClassCodeResponse->Code(500);
                }

                $ClassConexionBD->desconectarBD($conexion);

            }else{
                $return = $ClassCodeResponse->Code(404);    
            }  
        }else{
            $return = $ClassCodeResponse->Code(204);
        }
        }else{
            $return = $ClassCodeResponse->Code(401);
        }

    return $return;

    }
    /* 
    ---
    FIN DE LA FUNCION PARA EDITAR LA HUELLA DEL USUARIO SELECCIONADO 
    ---
    */

    function listaPersonalAsistencia($token){
        date_default_timezone_set('America/Mexico_City');
        $fecha_del_dia = date("Y-m-d");
        $hora_del_dia = date("H:i:s");
        $hoy = date("Y-m-d H:i:s");

        $ClassConexionBD = new BD();
        $ClassEncriptar = new Encriptar();
        $ClassEmpresa = new Empresa();
        $ClassCodeResponse = new CodeResponse();
        
        $conexion = $ClassConexionBD->conectarBD();        
        $validaToken = $ClassEmpresa->validaToken($token);

        $array = array();
        $num = 1;

        if($validaToken == 1){
            $idEmpresa = $ClassEmpresa->idEmpresa("token", $token);
            $sql = "SELECT 
            op_rh_personal.id,
            op_rh_personal.id_estacion,
            op_rh_personal.nombre_completo,
            op_rh_personal.estado,
            op_rh_personal_acceso.huella,
            op_rh_personal_acceso.pin
            FROM op_rh_personal
            INNER JOIN op_rh_personal_acceso 
            ON op_rh_personal.id = op_rh_personal_acceso.id_personal
            WHERE op_rh_personal.id_estacion = '".$idEmpresa."' AND op_rh_personal.estado = 1 ";
            $result_root = mysqli_query($conexion, $sql);
            $num_rows = mysqli_num_rows($result_root);   
            if ($num_rows > 0) {
                while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){  
                $id = $row_root['id'];                           
                $idempleado = $ClassEncriptar->encrypt($id);
                $nombre = $row_root['nombre_completo'];     
                        
                

                $sql_a = "SELECT * FROM op_rh_personal_asistencia
                WHERE id_personal = '".$id."' ORDER BY id DESC ";
                $result_a = mysqli_query($conexion, $sql_a);
                while($row_a = mysqli_fetch_array($result_a, MYSQLI_ASSOC)){   
                $idAsistencia = $row_a['id'];
                $fecha = $row_a['fecha'];
                $hora_entrada = $row_a['hora_entrada_sensor'];
                $horasalida = $row_a['hora_salida_sensor'];
                
                $data_a[] = array(
                    "id" => $idAsistencia,
                    "fecha" => $fecha,
                    "entrada" => $hora_entrada,
                    "salida" => $horasalida
                );

                }

                $data[] = array(
                    "idpersonal" => $idempleado,
                    "num" => $num,
                    "nombre" => $nombre,
                    "asistencia" => $data_a,
                );

                $num++;
                
                }
                $return = array (
                    "resultado"  => array (
                        "empleados" => $data,
                        "fecha" => $fecha_del_dia,
                        "hora" => $hora_del_dia,
                        "code" => 200,
                        "status" => "OK",
                    )                
                );                
            }else{
            $return = $ClassCodeResponse->Code(500);  
            }
        }else{
            $return = $ClassCodeResponse->Code(401);  
        }
        return $return; 
    }
    //--------------------------------------------------------------------------------------------------

}