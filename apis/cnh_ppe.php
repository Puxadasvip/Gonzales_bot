<?php
/**
 * 🔍 Consulta CNH / CPF - appbuscacheckonn.com (versão compatível com hospedagem)
 * Autor: GPT-5
 * Data: 2026-01-12
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=utf-8");

// === 1️⃣ CPF RECEBIDO ===
if (!isset($_GET['cpf'])) {
    echo json_encode(['ok' => false, 'erro' => 'Informe ?cpf=XXXXXXXXXXX']);
    exit;
}
$cpf = preg_replace('/\D/', '', $_GET['cpf']);
if (strlen($cpf) < 11) {
    echo json_encode(['ok' => false, 'erro' => 'CPF inválido']);
    exit;
}

// === 2️⃣ CONFIGURAÇÕES ===
$url = 'https://appbuscacheckonn.com/consultas/consultacpf.aspx';
$cookieFile = __DIR__ . '/cookies.txt';

// === 3️⃣ FUNÇÃO HTTP (COM SNI E AGENTE REAL) ===
function httpRequest($url, $method = "GET", $data = null, $cookieFile = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => "gzip, deflate, br",
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0",
        CURLOPT_TIMEOUT => 40,
    ]);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    }

    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return false;
    return $res;
}

// === 4️⃣ GET INICIAL PARA PEGAR VIEWSTATE ===
$htmlGet = httpRequest($url, "GET", null, $cookieFile);
if (!$htmlGet) {
    echo json_encode(['ok' => false, 'erro' => 'Falha no GET inicial']);
    exit;
}

// === 5️⃣ EXTRAI CAMPOS ASP.NET ===
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($htmlGet);
$xp = new DOMXPath($dom);

function inputValue($xp, $name) {
    return $xp->query("//input[@name='{$name}']")->item(0)?->getAttribute('value') ?? '';
}

$viewstate  = inputValue($xp, '__VIEWSTATE');
$eventvalid = inputValue($xp, '__EVENTVALIDATION');
$viewgen    = inputValue($xp, '__VIEWSTATEGENERATOR');

if (!$viewstate || !$eventvalid) {
    echo json_encode(['ok' => false, 'erro' => 'VIEWSTATE não encontrado']);
    exit;
}

// === 6️⃣ POST REAL ===
$post = http_build_query([
    '__EVENTTARGET' => '',
    '__EVENTARGUMENT' => '',
    '__VIEWSTATE' => $viewstate,
    '__VIEWSTATEGENERATOR' => $viewgen,
    '__EVENTVALIDATION' => $eventvalid,
    'ctl00$ctl00$MainContent$ddlTipoConsulta' => 'Auto',
    'ctl00$ctl00$MainContent$tbCPF' => $cpf,
    'ctl00$ctl00$MainContent$btnConsultar' => 'Consultar'
]);

$html = httpRequest(
    $url,
    "POST",
    $post,
    $cookieFile,
    ["Content-Type: application/x-www-form-urlencoded"]
);

if (!$html || strlen($html) < 1000) {
    echo json_encode(['ok' => false, 'erro' => 'POST sem retorno válido']);
    exit;
}

// === 7️⃣ PARSE DO HTML FINAL ===
@$dom->loadHTML($html);
$xp = new DOMXPath($dom);

function texto($xp, $q) {
    return trim($xp->query($q)?->item(0)?->textContent ?? '');
}
function label($xp, $l) {
    return texto($xp, "//label[@class='label3' and contains(text(),'$l')]/following-sibling::span[1]");
}

$nome = texto($xp, "//span[@class='label1' and contains(text(),'Nome')]/following-sibling::span[@class='text1']");
$sexo = label($xp, 'Sexo');
$nasc = label($xp, 'Data de nascimento');
$mae  = label($xp, 'Nome da mãe');
$rf   = label($xp, 'Situação na Receita Federal');

// Telefones
$telefones = [];
foreach ($xp->query("//a[starts-with(@href,'tel:')]") as $a) {
    $telefones[] = preg_replace('/\D/', '', $a->textContent);
}
$telefones = array_values(array_unique($telefones));

// === 8️⃣ RESPOSTA FINAL ===
echo json_encode([
    'ok' => true,
    'cpf' => $cpf,
    'nome' => $nome,
    'sexo' => $sexo,
    'data_nascimento' => $nasc,
    'nome_mae' => $mae,
    'situacao_rf' => $rf,
    'telefones' => $telefones
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>