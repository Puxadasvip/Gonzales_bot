<?php
header('Content-Type: application/json; charset=utf-8');

/* ================= CONFIG ================= */

define('BASE_URL', 'https://sistema.consultcenter.com.br');
define('USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36');
define('PRODUCT_ID', 4177);

/**
 * COOKIE CAKEPHP VÁLIDO
 */
define('CAKEPHP', '5tol6tmeij8idgke4i7n4f6aq5');

/* ================= CURL ================= */

function curlRequest(string $url, array $opts = []): string
{
    $headers = [
        'Cookie: CAKEPHP=' . CAKEPHP,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Referer: ' . BASE_URL . '/localizador_nacional',
        'Connection: keep-alive'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ] + $opts);

    $html = curl_exec($ch);
    curl_close($ch);

    return $html ?: '';
}

/* ================= INPUT ================= */

$telefone = $_GET['telefone'] ?? '';
$telefone = preg_replace('/\D/', '', $telefone);

if (strlen($telefone) < 10) {
    http_response_code(400);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Telefone inválido'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ================= PASSO 1: PEGAR CSRF ================= */

$htmlForm = curlRequest(
    BASE_URL . '/localizador_nacional/consultar/' . PRODUCT_ID
);

if (!preg_match('/name="_csrfToken"\s+value="([^"]+)"/i', $htmlForm, $m)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'CSRF token não encontrado. Sessão inválida.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$csrf = $m[1];

/* ================= PASSO 2: POST CONSULTA ================= */

$postData = http_build_query([
    '_csrfToken' => $csrf,
    'product_id' => PRODUCT_ID,
    'documento'  => $telefone
]);

curlRequest(
    BASE_URL . '/localizador_nacional/consultar/' . PRODUCT_ID,
    [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $postData,
    ]
);

/* ================= PASSO 3: RESULTADO ================= */

$html = curlRequest(BASE_URL . '/localizador_nacional/resultado');

if (!$html || stripos($html, 'LOCALIZADOR NACIONAL') === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Falha ao consultar telefone (resultado vazio)'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ================= PARSE ================= */

libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xp = new DOMXPath($dom);

$rows = $xp->query("//table[contains(@class,'datatable_assertiva')]//tbody/tr");
$resultados = [];

foreach ($rows as $tr) {
    $td = $xp->query('./td', $tr);
    if ($td->length < 6) continue;

    $resultados[] = [
        'cpf'             => trim($td->item(0)->textContent),
        'nome'            => trim($td->item(1)->textContent),
        'data_nascimento' => trim($td->item(2)->textContent),
        'idade'           => trim($td->item(3)->textContent),
        'relacionado'     => trim($td->item(4)->textContent),
        'cidade_uf'       => trim($td->item(5)->textContent),
    ];
}

/* ================= OUTPUT ================= */

echo json_encode([
    'status'    => 'sucesso',
    'telefone'  => $telefone,
    'total'     => count($resultados),
    'registros' => $resultados
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);