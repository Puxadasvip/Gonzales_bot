<?php
header('Content-Type: application/json; charset=utf-8');

// ==================================================
// IMPORTA SEGURANÇA (LOCAL REAL)
// ==================================================
require_once __DIR__ . '/../bot_apis/_security.php';

// ==================================================
// RECEBE HEADERS DO GATEWAY
// ==================================================
$apiKey = $_SERVER['HTTP_X_APIKEY'] ?? '';
$route  = $_SERVER['HTTP_X_ROUTE'] ?? '';

if ($apiKey === '' || $route === '') {
    http_response_code(401);
    echo json_encode(['erro' => 'Credenciais não informadas'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================================================
// VALIDA APIKEY (REAL)
// ==================================================
validate_apikey($apiKey, $route);

// ==================================================
// SE CHEGOU AQUI, APIKEY É VÁLIDA
// ==================================================
echo json_encode([
    'status' => 'ok',
    'rota'   => $route,
    'msg'    => 'APIKEY válida'
], JSON_UNESCAPED_UNICODE);