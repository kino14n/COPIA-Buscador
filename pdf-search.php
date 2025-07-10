<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
$code = $_GET['code'] ?? '';
if($code===''){ http_response_code(400); echo json_encode(["error"=>"Falta parámetro 'code'"],JSON_UNESCAPED_UNICODE); exit; }
$esc = escapeshellarg($code);
exec("python3 pdf_search.py $esc 2>&1", $out, $ret);
if($ret!==0){ http_response_code(500); echo json_encode(["error"=>"Error ejecutando Python","details"=>$out],JSON_UNESCAPED_UNICODE); exit; }
echo implode("\n",$out);