<?php

class Encriptar
{

function encrypt($string) {
$key = 'adef0237c8734d590456dab190958a8f7e2ea706689b1ca93dc01ff77791c9096a8fe31fb3078bb3231e329465a5a01392e465049c62e85ce3a7d1b56aaf1b4f';
$result = '';
for($i=0; $i<strlen($string); $i++) {
$char = substr($string, $i, 1);
$keychar = substr($key, ($i % strlen($key))-1, 1);
$char = chr(ord($char)+ord($keychar));
$result.=$char;
}
return base64_encode($result);
}

function decrypt($string) {
$key = 'adef0237c8734d590456dab190958a8f7e2ea706689b1ca93dc01ff77791c9096a8fe31fb3078bb3231e329465a5a01392e465049c62e85ce3a7d1b56aaf1b4f';
$result = '';
$string = base64_decode($string);
for($i=0; $i<strlen($string); $i++) {
$char = substr($string, $i, 1);
$keychar = substr($key, ($i % strlen($key))-1, 1);
$char = chr(ord($char)-ord($keychar));
$result.=$char;
}
return $result;
}

}

?>