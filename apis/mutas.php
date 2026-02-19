<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {

    /* ================= CONFIG ================= */
    define('USUARIO_SERPRO', '80606067353');
    define('SENHA_SERPRO',   'kKINGBOSS12@');
    define('TOKEN_FILE',   __DIR__ . '/token.txt');
    define('CAPTCHA_FILE', __DIR__ . '/captcha.json');
    define('CAPTCHA_TTL',  60); // segundos (ajuste se quiser)

    /* ================= TOKEN ================= */
    function obterNovoToken() {
        $data = [
            "imei" => "550249443172777",
            "latitude" => 31.24916,
            "longitude" => 121.48789833333333,
            "username" => USUARIO_SERPRO,
            "password" => SENHA_SERPRO
        ];

        $ch = curl_init("https://radar.serpro.gov.br/core-rest/gip-rest/auth/loginTalonario");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Accept-Encoding: gzip",
                "User-Agent: Dalvik/2.1.0 (Linux; Android 9)"
            ],
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $json = json_decode($res, true);
            if (!empty($json['token'])) {
                $token = str_replace('Token ', '', $json['token']);
                file_put_contents(TOKEN_FILE, $token);
                return $token;
            }
        }
        return null;
    }

    /* ================= CAPTCHA CACHE ================= */
    function salvarCaptcha(string $captcha): void {
        file_put_contents(CAPTCHA_FILE, json_encode([
            'captcha'    => $captcha,
            'created_at' => time()
        ], JSON_UNESCAPED_UNICODE));
    }

    function obterCaptchaValido(): ?string {
        if (!file_exists(CAPTCHA_FILE)) return null;
        $data = json_decode(file_get_contents(CAPTCHA_FILE), true);
        if (!$data || empty($data['captcha']) || empty($data['created_at'])) return null;
        if (time() - $data['created_at'] > CAPTCHA_TTL) {
            @unlink(CAPTCHA_FILE);
            return null;
        }
        return $data['captcha'];
    }

    /* ================= REQUEST ================= */
    function realizarConsulta(string $endpoint, string $token): array {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Token $token",
                "Accept: application/json",
                "Accept-Encoding: gzip",
                "User-Agent: Dalvik/2.1.0 (Linux; Android 9)",
                "Connection: Keep-Alive",
                "Host: radar.serpro.gov.br"
            ],
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $body, 'error' => $err];
    }

    /* ================= API KEY ================= */
    if (($_GET['apikey'] ?? '') !== 'gonzales') {
        http_response_code(401);
        echo json_encode(['erro' => 'API key invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ================= PLACA ================= */
    $input = $_GET['string'] ?? '';
    $placa = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($input)));

    if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) {
        http_response_code(400);
        echo json_encode([
            'erro' => 'Placa invalida',
            'recebido' => $input,
            'processado' => $placa
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ================= CAPTCHA ================= */
    // Prioridade: captcha enviado -> cache -> null
    $captcha = null;
    if (!empty($_GET['captcha_response'])) {
        $captcha = $_GET['captcha_response'];
        salvarCaptcha($captcha);
    } else {
        $captcha = obterCaptchaValido();
    }

    /* ================= TOKEN ================= */
    $token = trim(@file_get_contents(TOKEN_FILE));
    if (!$token) {
        $token = obterNovoToken();
        if (!$token) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao obter token'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /* ================= ENDPOINT ================= */
    $endpoint = "https://radar.serpro.gov.br/core-rest/gip-rest/infracoes/listar-autos/placa/$placa/exigibilidade/1/ultimoId/0";
    if ($captcha) {
        $endpoint .= "?captcha_response=" . urlencode($captcha);
    }

    /* ================= CONSULTA ================= */
    $resultado = realizarConsulta($endpoint, $token);

    // Renova token se precisar
    if (in_array($resultado['status'], [401, 403], true)) {
        $token = obterNovoToken();
        if ($token) {
            $resultado = realizarConsulta($endpoint, $token);
        }
    }

    /* ================= CAPTCHA INVÁLIDO ================= */
    if ($resultado['status'] === 422) {
        @unlink(CAPTCHA_FILE);
        http_response_code(422);
        echo json_encode([
            'error' => 'CAPTCHA_REQUIRED',
            'message' => 'Captcha expirado ou inválido. Gere um novo captcha.',
            'debug' => [
                'endpoint' => $endpoint,
                'resposta_bruta' => $resultado['body']
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /* ================= ERRO GERAL ================= */
    if ($resultado['status'] !== 200 || empty($resultado['body'])) {
        http_response_code($resultado['status']);
        echo json_encode([
            'erro' => 'Falha ao consultar infrações',
            'debug' => [
                'http_status' => $resultado['status'],
                'endpoint' => $endpoint,
                'curl_error' => $resultado['error'],
                'resposta_bruta' => $resultado['body']
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $json = json_decode($resultado['body'], true);
    if (!isset($json['infracoes'])) {
        http_response_code(500);
        echo json_encode([
            'erro' => 'Resposta inesperada do SERPRO',
            'debug' => $json
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /* ================= SUCESSO ================= */
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'servico' => 'infracoes',
        'placa' => $placa,
        'quantidade' => $json['quantidadeInfracoes'] ?? count($json['infracoes']),
        'infracoes' => $json['infracoes']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Erro interno',
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}