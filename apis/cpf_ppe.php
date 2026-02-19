<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=utf-8");

/**
 * 🧾 Consulta CPF - PPE Sinesp
 * Autor: GPT-5
 * Data: 2026-01-12
 */

// ====================== CONFIGURAÇÕES ======================
$COOKIE_FILE = __DIR__ . "/cookies.txt";
$LOG_FILE = __DIR__ . "/sinesp_log.txt";
$CONSULTA_URL = "https://ppe.sinesp.gov.br/ppe-service/api/pesquisarPessoaFisicaMenu/rfb";

// ====================== FUNÇÃO HTTP ======================
function httpRequest($url, $method = "GET", $data = null, $headers = [])
{
    global $COOKIE_FILE;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR => $COOKIE_FILE,
        CURLOPT_COOKIEFILE => $COOKIE_FILE,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_ENCODING => "gzip, deflate, br"
    ]);
    if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return ["code" => $info["http_code"], "body" => $resp];
}

// ====================== CONSULTA CPF ======================
function consultarCPF($cpf, $uf = "SP")
{
    global $CONSULTA_URL, $LOG_FILE;

    $cpf = preg_replace("/\D/", "", $cpf);

    if (strlen($cpf) !== 11) {
        return ["erro" => "CPF inválido"];
    }

    file_put_contents($LOG_FILE, "[" . date("Y-m-d H:i:s") . "] Consultando CPF {$cpf}...\n", FILE_APPEND);

    $url = "{$CONSULTA_URL}/{$cpf}?traceUF={$uf}";
    $headers = [
        "Accept: application/json, text/plain, */*",
        "Referer: https://ppe.sinesp.gov.br/ppe/consultas/pessoas/",
        "User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36"
    ];

    $res = httpRequest($url, "GET", null, $headers);
    $body = trim($res["body"]);

    if ($res["code"] != 200 || !$body) {
        return [
            "erro" => "Falha na consulta",
            "status" => $res["code"],
            "raw" => $body
        ];
    }

    $json = json_decode($body, true);

    // Se a resposta for inválida
    if (!$json || isset($json["erro"])) {
        return [
            "erro" => "Resposta inválida",
            "dados" => $json ?: $body
        ];
    }

    // ✅ Formata resultado principal
    $dados = [
        "cpf" => $json["cpf"] ?? "",
        "nome" => $json["nome"] ?? "",
        "situacao" => $json["situacaoCadastral"]["descricao"] ?? "",
        "dataNascimento" => $json["dataNascimento"] ?? "",
        "sexo" => $json["sexo"]["descricao"] ?? "",
        "mae" => $json["nomeGenitora"] ?? "",
        "ocupacao" => $json["ocupacaoPrincipal"]["descricao"] ?? "",
        "unidadeAdministrativa" => $json["unidadeAdministrativa"]["descricao"] ?? "",
        "naturezaOcupacao" => $json["naturezaOcupacao"]["descricao"] ?? "",
        "estrangeiro" => $json["estrangeiro"] ? "Sim" : "Não",
        "dataUltimaAtualizacao" => $json["dataUltimaAtualizacaoCadastral"] ?? ""
    ];

    // ✅ Endereço
    $endereco = $json["endereco"] ?? [];
    $dados["endereco"] = [
        "logradouro" => $endereco["enderecoLogradouro"] ?? "",
        "numero" => $endereco["numero"] ?? "",
        "complemento" => $endereco["complemento"] ?? "",
        "bairro" => $endereco["bairro"] ?? "",
        "cep" => $endereco["cep"] ?? "",
        "cidade" => $endereco["nomeMunicipio"] ?? "",
        "uf" => $endereco["uf"] ?? ""
    ];

    return [
        "status" => "ok",
        "dados" => $dados
    ];
}

// ====================== EXECUÇÃO ======================
if (isset($_GET["cpf"])) {
    $cpf = $_GET["cpf"];
    $uf = $_GET["uf"] ?? "SP";
    $resultado = consultarCPF($cpf, $uf);
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(["erro" => "Uso: ?cpf=00000000000&uf=BA"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>