<?php
header('Content-Type: application/json; charset=utf-8');

// ================= SEGURANÇA =================
require_once __DIR__ . '/../bot_apis/_security.php';

// ================= APIKEY VIA HEADER =================
$apiKey = $_SERVER['HTTP_X_APIKEY'] ?? '';
$route  = $_SERVER['HTTP_X_ROUTE'] ?? 'telefone_credilink';

// ================= BLOQUEIO REAL =================
if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['erro' => 'APIKEY ausente'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= VALIDAÇÃO REAL =================
validate_apikey($apiKey, $route);

// ================= PARÂMETROS =================
// ⚠️ AQUI É O PONTO QUE ESTAVA ERRADO
$telefone = $_GET['telefone_credilink'] ?? '';

if ($telefone === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Telefone não informado'], JSON_UNESCAPED_UNICODE);
    exit;
}

// remove tudo que não for número
$telefone = preg_replace('/\D+/', '', $telefone);

// aceita 10 ou 11 dígitos
if (!preg_match('/^\d{10,11}$/', $telefone)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Telefone inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= ENDPOINT REAL =================
$url = 'https://vps-gonzales.duckdns.org/apis/api_credilink.php?telefone=' . $telefone;

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