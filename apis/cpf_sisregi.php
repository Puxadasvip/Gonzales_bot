<?php
header('Content-Type: application/json; charset=utf-8');

// ================= SEGURANÇA =================
require_once __DIR__ . '/../bot_apis/_security.php';

// ================= APIKEY VIA HEADER =================
$apiKey = $_SERVER['HTTP_X_APIKEY'] ?? '';
$route  = $_SERVER['HTTP_X_ROUTE'] ?? 'cpf_credilink';

// ================= BLOQUEIO REAL =================
if ($apiKey === '') {
    http_response_code(401);
    echo json_encode([
        'erro' => 'APIKEY ausente'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= VALIDAÇÃO REAL =================
validate_apikey($apiKey, $route);

// ================= PARAMETROS =================
$cpf = $_GET['cpf_sisregi'] ?? '';

if ($cpf === '') {
    http_response_code(400);
    echo json_encode([
        'erro' => 'CPF não informado'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{11}$/', $cpf)) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'CPF inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= ENDPOINT REAL =================
$url = 'https://meuvpsbr.shop/siregi/cpf.php?cpf=' . $cpf;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'erro'    => 'Erro ao conectar no backend',
        'detalhe' => curl_error($ch)
    ], JSON_UNESCAPED_UNICODE);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ================= RESPOSTA FINAL =================
http_response_code($httpCode);
echo $response;