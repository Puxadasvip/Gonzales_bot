<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

//GONZALES) ---
define('USUARIO_SERPRO', '80606067353'); 
define('SENHA_SERPRO', 'kKINGBOSS12@');

define('TOKEN_FILE', __DIR__ . '/token.txt');

/* ===========================================================
   OBTÉM NOVO TOKEN
   =========================================================== */
function obterNovoToken() {
    $payload = [
        "imei"      => "550249443172777",
        "latitude"  => 31.24916,
        "longitude" => 121.48789833333333,
        "username"  => USUARIO_SERPRO,
        "password"  => SENHA_SERPRO
    ];

    $json = json_encode($payload);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://radar.serpro.gov.br/core-rest/gip-rest/auth/loginTalonario",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept-Encoding: gzip",
            "Connection: Keep-Alive",
            "User-Agent: Dalvik/2.1.0 (Linux; Android 9)",
            "Content-Length: " . strlen($json)
        ],
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_TIMEOUT => 20
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $res) {
        $data = json_decode($res, true);
        if (!empty($data['token'])) {
            $token = str_replace('Token ', '', $data['token']);
            file_put_contents(TOKEN_FILE, $token);
            return $token;
        }
    }

    return null;
}

/* ===========================================================
   EXECUTA CONSULTA
   =========================================================== */
function realizarConsulta(string $endpoint, string $token): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Token {$token}",
            "Accept-Encoding: gzip",
            "User-Agent: Dalvik/2.1.0 (Linux; Android 9)",
            "Connection: Keep-Alive",
            "Host: radar.serpro.gov.br"
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 20
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['status' => 500, 'error' => $err];
    }

    return ['status' => $code, 'body' => $body, 'error' => null];
}

/* ===========================================================
   BLOQUEIA USO DE ?string=
   =========================================================== */
if (isset($_GET['string'])) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Formato inválido. Use ?placa=ABC1D23 ou ?cpf=12345678901'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===========================================================
   DEFINE CONSULTA
   =========================================================== */
$placa = $_GET['placa'] ?? null;
$cpf   = $_GET['cpf'] ?? null;

if ($placa && $cpf) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Informe apenas UM parâmetro: placa OU cpf.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($placa) {

    $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $placa));

    if (!preg_match('/^[A-Z]{3}[0-9A-Z]{4}$/', $placa)) {
        http_response_code(400);
        echo json_encode(['erro' => 'Placa inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endpoint = "https://radar.serpro.gov.br/consultas-departamento-transito/api/veiculo/placa/{$placa}";

} elseif ($cpf) {

    $cpf = preg_replace('/\D+/', '', $cpf);

    if (!preg_match('/^\d{11}$/', $cpf)) {
        http_response_code(400);
        echo json_encode(['erro' => 'CPF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endpoint = "https://radar.serpro.gov.br/consultas-departamento-transito/api/condutores/cpf/{$cpf}/";

} else {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Informe ?placa=ABC1D23 ou ?cpf=12345678901'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===========================================================
   TOKEN
   =========================================================== */
$token = @file_get_contents(TOKEN_FILE);

if (!$token) {
    $token = obterNovoToken();
    if (!$token) {
        http_response_code(500);
        echo json_encode(['erro' => 'Falha ao obter token do SERPRO.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ===========================================================
   CONSULTA
   =========================================================== */
$res = realizarConsulta($endpoint, $token);

// token expirado
if ($res['status'] === 403) {
    $token = obterNovoToken();
    if (!$token) {
        http_response_code(500);
        echo json_encode(['erro' => 'Token expirado e não foi possível renovar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = realizarConsulta($endpoint, $token);
}

if (!empty($res['error'])) {
    http_response_code(500);
    echo json_encode(['erro' => $res['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===========================================================
   SAÍDA
   =========================================================== */
http_response_code($res['status']);
echo json_encode(
    json_decode($res['body'], true),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);