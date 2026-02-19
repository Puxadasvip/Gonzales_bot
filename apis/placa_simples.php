<?php
header('Content-Type: application/json; charset=utf-8');

// ================= SEGURANÇA =================
require_once __DIR__ . '/../bot_apis/_security.php';

// ================= APIKEY =================
$apiKey = $_SERVER['HTTP_X_APIKEY'] ?? '';
$route  = $_SERVER['HTTP_X_ROUTE'] ?? 'consulta_placa';

if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['erro' => 'APIKEY ausente'], JSON_UNESCAPED_UNICODE);
    exit;
}

validate_apikey($apiKey, $route);

// ================= PLACA (MANTIDA) =================
$placa =
    $_GET['placa_simples']
    ?? $_GET['placa']
    ?? $_GET['dados']
    ?? '';

if ($placa === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Placa não informada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$placa = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $placa));

if (!preg_match('/^[A-Z0-9]{7}$/', $placa)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Placa inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= BACKEND =================
$url = 'http://149.56.18.68:25605/api/consulta/placa'
     . '?dados=' . urlencode($placa)
     . '&apikey=MalvadezaMods2025';

// ================= FUNÇÕES =================
function keyNorm(string $k): string {
    $k = iconv('UTF-8', 'ASCII//TRANSLIT', $k);
    return strtoupper(preg_replace('/[^A-Z0-9_]/', '_', trim($k)));
}

function normalizarData(string $v): ?string {
    $v = trim($v);

    if (preg_match('/^\d{8}$/', $v)) {
        $y = substr($v,0,4);
        $m = substr($v,4,2);
        $d = substr($v,6,2);
        return checkdate($m,$d,$y) ? "$y-$m-$d" : null;
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2,4})$/', $v, $m)) {
        $y = strlen($m[3]) === 2
            ? ((int)$m[3] <= 69 ? '20'.$m[3] : '19'.$m[3])
            : $m[3];
        return "$y-{$m[2]}-{$m[1]}";
    }

    return null;
}

// documento robusto
function extrairDocumento(string $v): array {
    $d = preg_replace('/\D/', '', $v);

    // CPF escondido em string maior (ex: 00046564287879)
    if (strlen($d) > 11) {
        $cpf = substr($d, -11);
        if ($cpf !== '00000000000') {
            return ['cpf' => $cpf];
        }
    }

    // CNPJ real
    if (strlen($d) === 14) {
        return ['cnpj' => $d];
    }

    // CPF normal
    if (strlen($d) === 11) {
        return ['cpf' => $d];
    }

    return [];
}

// nome só se tiver letra
function ehNome(string $v): bool {
    return preg_match('/[A-ZÀ-Ú]/iu', $v) === 1;
}

// ================= MAPEAMENTO =================
$map = [
    // VEÍCULO
    'PLACA' => ['veiculo','placa'],
    'MARCA' => ['veiculo','marca'],
    'MODELO' => ['veiculo','modelo'],
    'MARCA_MODELO' => ['veiculo','modelo'],
    'FABRICANTE' => ['veiculo','fabricante'],
    'CHASSI' => ['veiculo','chassi'],
    'NUMERO_MOTOR' => ['veiculo','motor'],
    'COR' => ['veiculo','cor'],
    'COMBUSTIVEL' => ['veiculo','combustivel'],
    'TIPO_VEICULO' => ['veiculo','tipo'],
    'RENAVAN' => ['veiculo','renavam'],
    'RENAVAM' => ['veiculo','renavam'],
    'ANO_FABRICACAO' => ['veiculo','ano_fabricacao'],
    'ANO_DE_FABRICACAO' => ['veiculo','ano_fabricacao'],
    'ANOFAB' => ['veiculo','ano_fabricacao'],
    'ANO_MODELO' => ['veiculo','ano_modelo'],
    'ANOMODE' => ['veiculo','ano_modelo'],

    // PROPRIETÁRIO
    'NOME' => ['proprietario','nome'],
    'PROPRIETARIO' => ['proprietario','nome'],
    'CPF' => ['proprietario','documento'],
    'CPF_CNPJ' => ['proprietario','documento'],
    'DOCUMENTO' => ['proprietario','documento'],

    // ENDEREÇO
    'LOGRADOURO' => ['endereco','logradouro'],
    'ENDERECO' => ['endereco','logradouro'],
    'NUMERO' => ['endereco','numero'],
    'COMPLEMENTO' => ['endereco','complemento'],
    'BAIRRO' => ['endereco','bairro'],
    'CIDADE' => ['endereco','cidade'],
    'ESTADO' => ['endereco','uf'],
    'CEP' => ['endereco','cep'],

    // REGISTRO
    'UF_PLACA' => ['registro','uf_placa'],
    'UF_PROPRIETARIO' => ['registro','uf_proprietario'],
    'UF_JURISDICAO' => ['registro','uf_jurisdicao'],
    'UF_MUNICIPIO' => ['registro','uf_municipio'],
    'MUNICIPIO_EMPLACAMENTO' => ['registro','municipio_emplacamento'],
    'DATA_EMPLACAMENTO' => ['registro','data_emplacamento'],
    'DAINCL' => ['registro','data_inclusao'],
    'DALICE' => ['registro','data_licenciamento'],
    'DAMOVI' => ['registro','data_movimento'],
];

// ================= CURL =================
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['resposta'])) {
    http_response_code(404);
    echo json_encode(['erro' => 'Placa não encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= PROCESSAMENTO =================
$resultado = [
    'veiculo' => ['placa' => $placa],
    'proprietario' => [],
    'endereco' => [],
    'registro' => []
];

preg_match_all('/(.+?)\s*[⎯\-–—]\s*(.+)/u', $data['resposta'], $linhas);

foreach ($linhas[1] as $i => $chave) {
    $key = keyNorm($chave);
    $val = trim($linhas[2][$i]);

    if (!isset($map[$key])) continue;

    [$grupo, $campo] = $map[$key];

    // documento
    if ($campo === 'documento') {
        $resultado['proprietario'] += extrairDocumento($val);
        continue;
    }

    // nome só se for nome
    if ($grupo === 'proprietario' && $campo === 'nome') {
        if (ehNome($val) && !isset($resultado['proprietario']['nome'])) {
            $resultado['proprietario']['nome'] = $val;
        }
        continue;
    }

    // datas
    if ($d = normalizarData($val)) {
        $val = $d;
    }

    if (!isset($resultado[$grupo][$campo]) || strlen($val) > strlen($resultado[$grupo][$campo])) {
        $resultado[$grupo][$campo] = $val;
    }
}

// ================= RESPOSTA FINAL =================
echo json_encode([
    'status' => 'ok',
    'dados' => $resultado,
    'origem' => [
        'status' => $data['status'] ?? null,
        'criador' => '@GonzalesDev'
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);