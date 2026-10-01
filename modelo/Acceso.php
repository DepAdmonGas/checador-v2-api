<?php
include_once "BD.php";
include_once "Empresa.php";
include_once "Encriptar.php";
include_once "CodeResponse.php"; 

class Acceso {
    
    function AccesoEmpresa($usuario, $password){

        $ClassEncriptar = new Encriptar();
        $ClassConexionBD = new BD();
        $ClassCodeResponse = new CodeResponse();
        date_default_timezone_set('America/Mexico_City');
        $fecha_del_dia = date("Y-m-d");
        $hora_del_dia = date("H:i:s");
        $hoy = date("Y-m-d H:i:s");

        if(!empty($usuario) && !empty($password)){
            
            $conexion = $ClassConexionBD->conectarBD();
            $token = "JWT ".bin2hex(random_bytes(64)).".".uniqid();  
            $UsuarioEMP = $ClassEncriptar->encrypt($usuario);
            $PasswordEMP = $ClassEncriptar->encrypt($password);
            
            $sql = "SELECT
            op_rh_localidades.id,
            op_rh_localidades.localidad,
            op_rh_localidades_perfil.usuario,
            op_rh_localidades_perfil.password
            FROM op_rh_localidades 
            INNER JOIN op_rh_localidades_perfil 
            ON op_rh_localidades.id = op_rh_localidades_perfil.id_estacion
            WHERE op_rh_localidades_perfil.usuario = '".$UsuarioEMP."' AND 
            op_rh_localidades_perfil.password = '".$PasswordEMP."' AND status = 1 LIMIT 1";
            $result_root = mysqli_query($conexion, $sql);
            $row_root = mysqli_num_rows($result_root);
            if ($row_root > 0) {
                while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){
                $idEmpresa = $row_root['id'];
                $empresanombre = $row_root['localidad'];
                }
                $sql_edit = "UPDATE op_rh_localidades_perfil SET token = '".$token."'
                WHERE id_estacion = '".$idEmpresa."' AND status = 1";
            
                if(mysqli_query($conexion, $sql_edit)) {
                    $array = array (
                        "resultado"  => array (
                        "empresa" => $empresanombre,
                        "token" => $token,
                        "fecha" => $fecha_del_dia,
                        "hora" => $hora_del_dia,
                        "code" => 200,
                        "status" => "OK"
                        )        
                    );
                }else{
                    $array = $ClassCodeResponse->Code(500);          
                } 
            }else{
                $array = $ClassCodeResponse->Code(401);
            }
            
            $ClassConexionBD->desconectarBD($conexion);
        
        }else{
            $array = $ClassCodeResponse->Code(204);
        }
    return $array;
    }

    function AccesoPersonal($token, $idpersonal, $fecha, $hora){
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

    //------------------------------------------------- Inicio
    if($validaToken == 1){
    //-- Validación token correcta
    if(!empty($idpersonal) && !empty($fecha) && !empty($hora)){
    
    $idPDecrypt = $ClassEncriptar->decrypt($idpersonal);
    $idEmpresa = $ClassEmpresa->idEmpresa("token", $token);
    $validaPersonalEmpresa = $ClassEmpresa->validaPersonalEmpresa($idEmpresa,$idPDecrypt);


    if($validaPersonalEmpresa['estado'] == 1){
    //-- Validación del personal y la empresa

    $idEmpresa = $validaPersonalEmpresa['idEstacion'];
    $Asistencia = $this->Asistencia($idEmpresa,$idPDecrypt); 

    $diaFecha = $this->nombreDia($fecha);           
    $HentradaHsalida = $this->HentradaHsalida($idEmpresa,$idPDecrypt,$diaFecha);
    $Retardo = $this->Retardo($idEmpresa);
    $Incidencias = $this->Incidencias($idEmpresa);
    $Salario = $this->Salario($idPDecrypt);
    $idAsistencia = $this->idAsistencia();
    $HoraEntrada = $HentradaHsalida['horaentrada'];
    $HoraSalida = $HentradaHsalida['horasalida'];

    if($Asistencia['estado'] == 0){
        //-----------------------------------
    if($HoraEntrada != "00:00:00" && $HoraSalida != "00:00:00"){

    if($HoraSalida >= "06:00:00" && $HoraSalida <= "09:00:00"){
    $return = RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario); 
    }else{
    $return = RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario); 
    }   

    }else{
    $return = RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario);  
    }
    //-----------------------------------
    }else{

    $ASid = $Asistencia['idasistencia'];
    $ASfecha = $Asistencia['Fecha'];
    $ASentrada = $Asistencia['Entrada'];
    $ASsalida = $Asistencia['Salida'];
    $ASentradaSensor = $Asistencia['EntradaSensor'];
    $ASsalidaSensor = $Asistencia['SalidaSensor'];

    if($ASfecha == $fecha){
    $return = RegistraSalida($ASid,$fecha,$hora);
    }else{

    if($ASsalida >= "06:00:00" && $ASsalida <= "09:00:00"){
    $fechaSiguiente = date("Y-m-d",strtotime($ASfecha."+ 1 days"));
    if($fechaSiguiente == $fecha){
    $return = RegistraSalida($ASid,$fecha,$hora);    
    }else{
    $return = RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario);  
    }
        
    }else{
    $return = RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario);  
    }

    }

    }

    //-------------------------------------------
    }else{  
    //-- No se encontro la persona en la empresa  
    $return = $ClassCodeResponse->Code(404);    
    }
    }else{
    //--- No se valido las variables
    $return = $ClassCodeResponse->Code(204);
    }
    //-------------------------------------------------
    }else{
    //-- Token no valido
    $return = $ClassCodeResponse->Code(401);
    }
    //------------------------------------------------- Fin

    return $return;
    }

    function nombreDia($fecha){

        $fechaTS = strtotime($fecha);
        switch(date('w', $fechaTS)){
            case 0: return "Domingo"; break;
            case 1: return "Lunes"; break;
            case 2: return "Martes"; break;
            case 3: return "Miércoles"; break;
            case 4: return "Jueves"; break;
            case 5: return "Viernes"; break;
            case 6: return "Sábado"; break;
        }
    }

    function HentradaHsalida($idEmpresa,$idPDecrypt,$diaFecha){

    $ClassConexionBD = new BD();
    $conexion = $ClassConexionBD->conectarBD();

    $sql = "SELECT hora_entrada, hora_salida FROM op_rh_personal_horario
    WHERE id_estacion = '".$idEmpresa."' AND id_personal = '".$idPDecrypt."' AND dia = '".$diaFecha."' LIMIT 1 ";
    $result_root = mysqli_query($conexion, $sql);
    $row_root = mysqli_num_rows($result_root);
    while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){   
        $horaentrada = $row_root['hora_entrada'];
        $horasalida = $row_root['hora_salida'];
    }

    $return = array (
            "horaentrada" => $horaentrada,
            "horasalida" => $horasalida             
    );          

    $ClassConexionBD->desconectarBD($conexion);
    return $return;

    }

    function ValidaAsistencia($idEmpresa,$idPDecrypt,$fecha){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

        $sql = "SELECT id, id_personal, fecha, hora_entrada_sensor, hora_salida_sensor FROM op_rh_personal_asistencia
        WHERE id_estacion = '".$idEmpresa."' AND id_personal = '".$idPDecrypt."' AND fecha = '".$fecha."' LIMIT 1 ";
        $result_root = mysqli_query($conexion, $sql);
        $row_root = mysqli_num_rows($result_root);
        if ($row_root > 0) {    
            while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){   
                $id = $row_root['id'];
                $EntradaSensor = $row_root['hora_entrada_sensor'];
                $SalidaSensor = $row_root['hora_salida_sensor'];
            }

            $return = array (
                "idasistencia" => $id,
                "EntradaSensor" => $EntradaSensor,
                "SalidaSensor" => $SalidaSensor,
                "estado" => 1             
        );          
        }else{    
            $return = array (
                "idasistencia" => 0,
                "EntradaSensor" => 0,
                "SalidaSensor" => 0,
                "estado" => 0  
            );
        }

        $ClassConexionBD->desconectarBD($conexion);
        return $return;

    }

    function Asistencia($idEmpresa,$idPDecrypt){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

        $sql = "SELECT id, id_personal, fecha, hora_entrada, hora_salida, hora_entrada_sensor, hora_salida_sensor FROM op_rh_personal_asistencia
        WHERE id_estacion = '".$idEmpresa."' AND id_personal = '".$idPDecrypt."' AND hora_salida_sensor = '00:00:00' ORDER BY fecha DESC LIMIT 1 ";
        $result_root = mysqli_query($conexion, $sql);
        $row_root = mysqli_num_rows($result_root);
        if ($row_root > 0) {    
            while($row_root = mysqli_fetch_array($result_root, MYSQLI_ASSOC)){   
                $id = $row_root['id'];
                $Fecha = $row_root['fecha'];
                $Entrada = $row_root['hora_entrada'];
                $Salida = $row_root['hora_salida'];
                $EntradaSensor = $row_root['hora_entrada_sensor'];
                $SalidaSensor = $row_root['hora_salida_sensor'];
            }

            $return = array (
                "idasistencia" => $id,
                "Fecha" => $Fecha,
                "Entrada" => $Entrada,
                "Salida" => $Salida,
                "EntradaSensor" => $EntradaSensor,
                "SalidaSensor" => $SalidaSensor,
                "estado" => 1             
        );          
        }else{    
            $return = array (
                "idasistencia" => 0,
                "Fecha" => 0,
                "Entrada" => 0,
                "Salida" => 0,
                "EntradaSensor" => 0,
                "SalidaSensor" => 0,
                "estado" => 0  
            );
        }

        $ClassConexionBD->desconectarBD($conexion);
        return $return;

    }

    function Retardo($idEmpresa){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();   

        $sql = "SELECT retardo FROM op_rh_localidades_retardo_incidencia WHERE id_estacion = '".$idEmpresa."' LIMIT 1 ";
        $result = mysqli_query($conexion, $sql);
        while($row = mysqli_fetch_array($result, MYSQLI_ASSOC)){
        $return = $row['retardo'];
        }

        $ClassConexionBD->desconectarBD($conexion);
        return $return;
    }

    function Incidencias($idEmpresa){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();   

        $sql = "SELECT incidencia FROM op_rh_localidades_retardo_incidencia WHERE id_estacion = '".$idEmpresa."' LIMIT 1 ";
        $result = mysqli_query($conexion, $sql);
        while($row = mysqli_fetch_array($result, MYSQLI_ASSOC)){
        $return = $row['incidencia'];
        }

        $ClassConexionBD->desconectarBD($conexion);
        return $return;
    }

    function ValDescanso($idasistencia){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

        $sql = "SELECT hora_entrada_sensor FROM op_rh_personal_asistencia
        WHERE id = '".$idasistencia."' AND hora_entrada_sensor = '00:00:00' LIMIT 1 ";
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

    function idAsistencia(){
        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

        $sql = "SELECT id FROM op_rh_personal_asistencia ORDER BY id DESC LIMIT 1 ";
        $result = mysqli_query($conexion, $sql);
        $numero = mysqli_num_rows($result);  
        if($numero > 0){
        while($row = mysqli_fetch_array($result, MYSQLI_ASSOC)){
        $idAsistencia = $row['id'] + 1;
        }
        }else{
        $idAsistencia = 1;
        }

        $ClassConexionBD->desconectarBD($conexion);
        return $idAsistencia; 
        }

        function Salario($id){
            $ClassConexionBD = new BD();
            $conexion = $ClassConexionBD->conectarBD();

            $sql_incidencia = "SELECT id, sd FROM op_rh_personal WHERE id = '".$id."' ORDER BY id DESC LIMIT 1 ";
            $result_incidencia = mysqli_query($conexion, $sql_incidencia);
            $numero_incidencia = mysqli_num_rows($result_incidencia);  
            while($row_incidencia = mysqli_fetch_array($result_incidencia, MYSQLI_ASSOC)){
            $salario = $row_incidencia['sd'];
            }

            $ClassConexionBD->desconectarBD($conexion);
            return $salario;  
            }
            }

            //--------------------------------------------------

    function RegistrarEntrada($idAsistencia,$idEmpresa,$idPDecrypt,$fecha,$HoraEntrada,$HoraSalida,$hora,$Retardo,$Incidencias,$Salario){

        $ClassConexionBD = new BD();
        $conexion = $ClassConexionBD->conectarBD();

            $sql_insert = "INSERT INTO op_rh_personal_asistencia (
                    id,
                    id_estacion,
                    id_personal,
                    fecha,
                    hora_entrada,
                    hora_salida,
                    hora_entrada_sensor,
                    hora_salida_sensor,
                    retardo_minutos,
                    incidencia_dias,
                    incidencia,
                    sd) 
                    VALUES (
                    '".$idAsistencia."',                    
                    '".$idEmpresa."',
                    '".$idPDecrypt."', 
                    '".$fecha."', 
                    '".$HoraEntrada."', 
                    '".$HoraSalida."',
                    '".$hora."', 
                    '',
                    '".$Retardo."',
                    '".$Incidencias."',
                    0,
                    '".$Salario."')";                                    
                    
                    if(mysqli_query($conexion, $sql_insert)) {  
                        
                    $return = array (
                    "resultado"  => array (
                    "fecha" => $fecha,
                    "hora" => $hora,
                    "code" => 201,
                    "status" => "CREADO",
                    "mensaje" => "HORA ENTRADA",
                    )          
                    );

                    }else{
                    $return = $ClassCodeResponse->Code(500);  
                    }

                    $ClassConexionBD->desconectarBD($conexion);
                    return $return;
            }

    function RegistraSalida($idasistencia,$fecha,$hora){

    $ClassConexionBD = new BD();
    $conexion = $ClassConexionBD->conectarBD(); 

    $sql_edit = "UPDATE op_rh_personal_asistencia SET 
    hora_salida_sensor = '".$hora."'
    WHERE id = '".$idasistencia."' ";
                        
    if(mysqli_query($conexion, $sql_edit)) {
    $return = array (
    "resultado"  => array (
    "fecha" => $fecha,
    "hora" => $hora,
    "code" => 201,
    "status" => "CREADO",
    "mensaje" => "HORA SALIDA",
    )          
    );  
    }else{
    $return = $ClassCodeResponse->Code(500);  
    }      

    $ClassConexionBD->desconectarBD($conexion);
    return $return;  
    }