<?php

//////////////////// CONFIG ////////////////////
const BOT_TOKEN     = 'BOT_TOKEN';
const API_URL       = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';

const LOG_FILE      = __DIR__ . '/bot.log';
const CONSULTAS_DIR = __DIR__ . '/consultas';

// @ do bot (sem @ aqui)
const BOT_USERNAME  = 'EmonNullbot';

// 👑 ID do dono/admin do bot (sem restrições de antiflood)
const ADMIN_ID = 7505318236;

// Auto-delete em grupos (segundos)
if (!defined('AUTO_DELETE_SECONDS')) define('AUTO_DELETE_SECONDS', 60);

// ===== AUTO-DELETE VIA MYSQL (FILA) =====
const DB_HOST    = 'localhost';
const DB_NAME    = 'u937550989_cron_delete';
const DB_USER    = 'u937550989_cron_delete';
const DB_PASS    = 'w23406891W@#';
const DB_CHARSET = 'utf8mb4';

// Antiflood (Hostinger-safe)
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }

if (!defined('SECURITY_FILE')) define('SECURITY_FILE', DATA_DIR . '/security.json');
if (!file_exists(SECURITY_FILE)) { @file_put_contents(SECURITY_FILE, "{}"); }

if (!defined('WINDOW_SECONDS')) define('WINDOW_SECONDS', 60);
if (!defined('MAX_EVENTS'))     define('MAX_EVENTS', 10);
if (!defined('BAN_SECONDS'))    define('BAN_SECONDS', 30);
if (!defined('BAN_MULTIPLIER')) define('BAN_MULTIPLIER', 2);
if (!defined('BAN_MAX_SECONDS'))define('BAN_MAX_SECONDS', 600);

// ===== TG TICKET (abre telegraph direto, travado por usuário, com expiração) =====
define('TG_TICKET_DIR', __DIR__ . '/tg_ticket');
if (!is_dir(TG_TICKET_DIR)) @mkdir(TG_TICKET_DIR, 0775, true);

// tempo que o botão fica válido (em segundos)
define('TG_TICKET_TTL', 600); // 10 min

date_default_timezone_set('America/Sao_Paulo');

/////////// HARDENING /////////////
ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

set_error_handler(function($no,$str,$file,$line){
  @file_put_contents(
    LOG_FILE,
    '['.date('Y-m-d H:i:s')."] PHP[$no] $str in $file:$line\n",
    FILE_APPEND
  );
});

set_exception_handler(function($e){
  @file_put_contents(
    LOG_FILE,
    '['.date('Y-m-d H:i:s')."] EXC ".$e->getMessage()."\n".$e->getTraceAsString()."\n",
    FILE_APPEND
  );
});

//////////////////// VIP SYSTEM ////////////////////
define('VIP_DIR', __DIR__ . '/vip');
if (!is_dir(VIP_DIR)) {
    @mkdir(VIP_DIR, 0775, true);
}

define('VIP_USERS_FILE', VIP_DIR . '/users.json');
define('VIP_PAY_FILE',   VIP_DIR . '/payments.json');

if (!file_exists(VIP_USERS_FILE)) file_put_contents(VIP_USERS_FILE, '{}');
if (!file_exists(VIP_PAY_FILE))   file_put_contents(VIP_PAY_FILE, '{}');

function vip_load_users(): array {
    return json_decode(@file_get_contents(VIP_USERS_FILE), true) ?: [];
}

function vip_save_users(array $data): void {
    file_put_contents(VIP_USERS_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function user_is_vip(int $userId): bool {
    $users = vip_load_users();
    return isset($users[$userId]) && ($users[$userId]['expires_at'] ?? 0) > time();
}

function vip_add_days(int $userId, int $days): void {
    $users = vip_load_users();
    $now = time();

    if (!isset($users[$userId]) || ($users[$userId]['expires_at'] ?? 0) < $now) {
        $users[$userId]['expires_at'] = $now + ($days * 86400);
    } else {
        $users[$userId]['expires_at'] += ($days * 86400);
    }

    vip_save_users($users);
}
////////////////// FIM VIP SYSTEM //////////////////

//////////////////// PAYMENTS JSON (PIX) ////////////////////

/** Remove um payment_id do pagamentos.json */
function payments_remove(string $paymentId): bool {
    if ($paymentId === '') return false;

    $all = loadJson(PAYMENTS_JSON);
    if (!is_array($all) || empty($all)) return false;

    $removed = false;

    // ✅ se a chave for o próprio paymentId (seu caso mais comum)
    if (isset($all[$paymentId])) {
        unset($all[$paymentId]);
        $removed = true;
    } else {
        // fallback: procura dentro do array
        foreach ($all as $k => $v) {
            if (is_array($v) && ($v['payment_id'] ?? '') === $paymentId) {
                unset($all[$k]);
                $removed = true;
            }
        }
    }

    if ($removed) {
        saveJson(PAYMENTS_JSON, $all);
    }

    return $removed;
}

/** Remove PIX vencidos do pagamentos.json (expira_em < agora) */
function payments_cleanup_expired(): int {
    $all = loadJson(PAYMENTS_JSON);
    if (!is_array($all) || empty($all)) return 0;

    $now = time();
    $removed = 0;

    foreach ($all as $k => $v) {
        $exp = (int)($v['expira_em'] ?? 0);

        // remove se expirou (ou se estiver sem expira_em válido)
        if ($exp <= 0 || $exp < $now) {
            unset($all[$k]);
            $removed++;
        }
    }

    if ($removed > 0) {
        saveJson(PAYMENTS_JSON, $all);
    }

    return $removed;
}
////////////////// FIM PAYMENTS JSON //////////////////

////////////// CORE HELPERS //////////////
function logx(string $msg): void {
  @file_put_contents(LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
  
  // Rotação automática de logs (verifica a cada 100 escritas)
  static $counter = 0;
  if (++$counter % 100 === 0 && file_exists(__DIR__ . '/log_manager.php')) {
    require_once __DIR__ . '/log_manager.php';
    LogManager::rotate(LOG_FILE);
  }
}

/**
 * Normaliza CPF removendo caracteres especiais e validando
 * Aceita: 123.456.789-01, 12345678901, 123 456 789 01, etc
 */
function normalizarCPF(string $input): array {
  // Remove tudo que não é número
  $cpf = preg_replace('/\D+/', '', trim($input));
  
  // Valida tamanho
  if (strlen($cpf) !== 11) {
    return ['valido' => false, 'erro' => 'CPF deve ter 11 dígitos'];
  }
  
  // Valida sequências repetidas (000.000.000-00, 111.111.111-11, etc)
  if (preg_match('/(\d)\1{10}/', $cpf)) {
    return ['valido' => false, 'erro' => 'CPF inválido (sequência repetida)'];
  }
  
  return ['valido' => true, 'cpf' => $cpf, 'formatado' => formatarCPF($cpf)];
}

/**
 * Formata CPF para exibição: 12345678901 → 123.456.789-01
 */
function formatarCPF(string $cpf): string {
  $cpf = preg_replace('/\D+/', '', $cpf);
  if (strlen($cpf) !== 11) return $cpf;
  
  return substr($cpf, 0, 3) . '.' . 
         substr($cpf, 3, 3) . '.' . 
         substr($cpf, 6, 3) . '-' . 
         substr($cpf, 9, 2);
}

/**
 * Normaliza CNPJ removendo caracteres especiais
 * Aceita: 12.345.678/0001-90, 12345678000190, etc
 */
function normalizarCNPJ(string $input): array {
  // Remove tudo que não é número
  $cnpj = preg_replace('/\D+/', '', trim($input));
  
  // Valida tamanho
  if (strlen($cnpj) !== 14) {
    return ['valido' => false, 'erro' => 'CNPJ deve ter 14 dígitos'];
  }
  
  // Valida sequências repetidas
  if (preg_match('/(\d)\1{13}/', $cnpj)) {
    return ['valido' => false, 'erro' => 'CNPJ inválido (sequência repetida)'];
  }
  
  return ['valido' => true, 'cnpj' => $cnpj, 'formatado' => formatarCNPJ($cnpj)];
}

/**
 * Formata CNPJ para exibição: 12345678000190 → 12.345.678/0001-90
 */
function formatarCNPJ(string $cnpj): string {
  $cnpj = preg_replace('/\D+/', '', $cnpj);
  if (strlen($cnpj) !== 14) return $cnpj;
  
  return substr($cnpj, 0, 2) . '.' . 
         substr($cnpj, 2, 3) . '.' . 
         substr($cnpj, 5, 3) . '/' . 
         substr($cnpj, 8, 4) . '-' . 
         substr($cnpj, 12, 2);
}

/**
 * Normaliza nome removendo acentos, caracteres especiais e espaços extras
 * João da Silva → joao da silva
 */
function normalizarNome(string $input): string {
  // Remove espaços no início e fim
  $nome = trim($input);
  
  // Remove múltiplos espaços
  $nome = preg_replace('/\s+/', ' ', $nome);
  
  // Converte para minúsculas
  $nome = mb_strtolower($nome, 'UTF-8');
  
  // Remove acentos
  $acentos = [
    'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ç' => 'c', 'ñ' => 'n'
  ];
  
  $nome = strtr($nome, $acentos);
  
  // Remove caracteres especiais (mantém letras, números e espaços)
  $nome = preg_replace('/[^a-z0-9\s]/i', '', $nome);
  
  // Remove espaços extras novamente
  $nome = preg_replace('/\s+/', ' ', trim($nome));
  
  return $nome;
}

/**
 * Valida tamanho de nome (mínimo 3 caracteres)
 */
function validarNome(string $nome): array {
  $nomeNormalizado = normalizarNome($nome);
  
  if (strlen($nomeNormalizado) < 3) {
    return ['valido' => false, 'erro' => 'Nome deve ter no mínimo 3 caracteres'];
  }
  
  if (strlen($nomeNormalizado) > 100) {
    return ['valido' => false, 'erro' => 'Nome muito longo (máximo 100 caracteres)'];
  }
  
  return ['valido' => true, 'nome' => $nomeNormalizado, 'original' => $nome];
}

/** remove nulls antes de enviar pro Telegram */
function tg_clean(array $a): array {
  foreach ($a as $k => $v) {
    if (is_array($v)) $a[$k] = tg_clean($v);
    if (array_key_exists($k, $a) && $a[$k] === null) unset($a[$k]);
  }
  return $a;
}

/**
 * ✅ tg() otimizado:
 * - reaproveita cURL handle
 * - gzip
 * - retry leve em erro de rede
 */
function tg(string $method, array $params = []): array {
  static $ch = null;

  $params = tg_clean($params);
  $url = API_URL . $method;

  if ($ch === null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Connection: keep-alive'],
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT        => 20,
      CURLOPT_TCP_KEEPALIVE  => 1,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_ENCODING       => 'gzip',
    ]);
  }

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));

  $res = curl_exec($ch);
  $err = curl_error($ch);

  if ($err) {
    // retry 1x rápido em erro de rede
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res2 = curl_exec($ch);
    $err2 = curl_error($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($err2) {
      logx("cURL error ($method): $err2");
      return ['ok'=>false,'description'=>$err2];
    }
    $res = $res2;
  }

  $data = json_decode($res ?: '[]', true);
  if (!is_array($data)) {
    logx("Decode error ($method): " . substr((string)$res, 0, 500));
    return ['ok'=>false,'description'=>'decode_error'];
  }

  if (($data['ok'] ?? true) !== true) {
    logx("API error ($method): " . substr((string)$res, 0, 400));
  }

  return $data;
}

/**
 * Responde um callback query com tratamento de timeout
 * Se o callback expirou (>3h), retorna false para que o código possa tratar
 */
function answerCallback(string $callbackQueryId, string $text = '', bool $showAlert = false): bool {
  if (empty($callbackQueryId)) {
    logx("⚠️ answerCallback: callback_query_id vazio!");
    return false;
  }
  
  logx("📤 Tentando responder callback: {$callbackQueryId}");
  
  $result = tg('answerCallbackQuery', [
    'callback_query_id' => $callbackQueryId,
    'text' => $text,
    'show_alert' => $showAlert
  ]);
  
  // Se der erro de timeout, retorna false
  if (!($result['ok'] ?? false)) {
    $errorMsg = $result['description'] ?? 'Erro desconhecido';
    
    if (strpos($errorMsg, 'query is too old') !== false || 
        strpos($errorMsg, 'query ID is invalid') !== false) {
      logx("⚠️ Callback expirou ou é inválido: {$errorMsg}");
      return false;
    }
    
    logx("❌ Erro ao responder callback: {$errorMsg}");
    return false;
  }
  
  logx("✅ Callback respondido com sucesso!");
  return true;
}

function http_get(string $url, int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'TelegramBot',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'   => ($err === ''),
        'code' => $code,
        'body' => (string)$body,
        'err'  => $err
    ];
}

function editMessageTextSafe(array $params): void {
  $r = tg('editMessageText', $params);
  if (($r['ok'] ?? false) !== true) {
    $d = $r['description'] ?? '';
    if (strpos($d, 'message is not modified') !== false) return;
    logx("editMessageTextSafe error: ".json_encode($r));
  }
}

function deleteMessageSafe(int $chatId, int $messageId): void {
  if ($chatId === 0 || $messageId === 0) return;

  $r = tg('deleteMessage', [
    'chat_id'    => $chatId,
    'message_id' => $messageId
  ]);

  // ignora erros comuns (não polui o log)
  if (($r['ok'] ?? true) !== true) {
    $d = $r['description'] ?? '';
    if (
      strpos($d, 'message to delete not found') !== false ||
      strpos($d, 'message can\'t be deleted') !== false
    ) {
      return;
    }
  }
}

function mention_html(int $id, string $name): string {
  $safe = htmlspecialchars(
    $name !== '' ? $name : 'usuário',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
  );
  return '<a href="tg://user?id='.$id.'">'.$safe.'</a>';
}

/** ✅ quebra texto sem cortar UTF-8 */
function mb_chunk(string $text, int $size): array {
  $chunks = [];
  $len = mb_strlen($text, 'UTF-8');
  for ($i=0; $i<$len; $i += $size) {
    $chunks[] = mb_substr($text, $i, $size, 'UTF-8');
  }
  return $chunks;
}

/** Envia mensagem e retorna o message_id */
function replySmart(int $chatId, string $text, int $replyTo = 0, array $extra = []): int {
  if (mb_strlen($text, 'UTF-8') > 3500) {
    $parts = mb_chunk($text, 3500);
    $lastId = 0;

    foreach ($parts as $i => $chunk) {
      $r = tg('sendMessage', array_merge([
        'chat_id'                    => $chatId,
        'text'                       => $chunk,
        'parse_mode'                 => 'HTML',
        'reply_to_message_id'        => ($i === 0 ? $replyTo : null),
        'allow_sending_without_reply'=> true,
        'disable_web_page_preview'   => true,
      ], $i === count($parts)-1 ? $extra : []));

      $lastId = (int)($r['result']['message_id'] ?? $lastId);
    }

    return $lastId;
  }

  $r = tg('sendMessage', array_merge([
    'chat_id'                    => $chatId,
    'text'                       => $text,
    'parse_mode'                 => 'HTML',
    'reply_to_message_id'        => ($replyTo > 0 ? $replyTo : null),
    'allow_sending_without_reply'=> true,
    'disable_web_page_preview'   => true,
  ], $extra));

  return (int)($r['result']['message_id'] ?? 0);
}

/* ================= MENSAGENS PROFISSIONAIS ================= */
function msg_use(string $title, string $example): string {
  return "⚠️ <b>{$title}</b>\n\nPor favor, utilize o formato correto:\n<code>{$example}</code>";
}
function msg_off(string $serviceName, string $channelUrl='https://t.me/GonzalesCanal'): string {
  return "⚠️ <b>Não foi possível realizar a consulta.</b>\n\n"
    . "No momento, o serviço de <b>{$serviceName}</b> está indisponível.\n"
    . "Tente novamente em breve ou <a href=\"{$channelUrl}\">acesse nosso canal oficial</a> para atualizações.";
}
function msg_busy(): string {
  return "⏳ <b>Consultando...</b>\n<i>Processando sua solicitação.</i>";
}

////////// AUTO DELETE VIA MYSQL QUEUE //////////
function dbq(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT         => false,
  ]);
  return $pdo;
}

//////////////////// LGPD AUTO TABLE ////////////////////
function lgpd_auto_create_table(): void {
  try {
    $pdo = dbq();
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS lgpd_consentimentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        hash_consentimento CHAR(64) NOT NULL,
        versao_termos VARCHAR(10) NOT NULL,
        aceito_em DATETIME NOT NULL,
        ativo TINYINT(1) DEFAULT 1,
        UNIQUE KEY uniq_user_versao (user_id, versao_termos)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  } catch (Throwable $e) {
    logx('LGPD TABLE ERROR: '.$e->getMessage());
  }
}
lgpd_auto_create_table();

/**
 * Enfileira mensagens para auto-delete
 * ✅ Nunca no privado
 * ✅ Só grupos / supergrupos / canais
 */
function enqueue_autodelete(int $chatId, int $resultMsgId, int $origMsgId, int $seconds): void {
  // nunca no privado
  if ($chatId > 0) return;

  $seconds = max(10, min(86400, $seconds));
  $origMsgId = (int)$origMsgId;
  if ($origMsgId < 0) $origMsgId = 0;

  try {
    $pdo = dbq();
    $st = $pdo->prepare("
      INSERT INTO delete_queue (chat_id, result_msg_id, orig_msg_id, delete_at)
      VALUES (:chat_id, :result_msg_id, :orig_msg_id, :delete_at)
    ");
    $st->execute([
      ':chat_id'       => $chatId,
      ':result_msg_id' => $resultMsgId,
      ':orig_msg_id'   => $origMsgId,
      ':delete_at'     => time() + $seconds,
    ]);
  } catch (Throwable $e) {
    logx("enqueue_autodelete error: " . $e->getMessage());
  }
}

////////// BOTÃO APAGAR //////////
function keyboard_apagar(int $ownerId, ?int $origCmdId = null): array {
    $orig = $origCmdId ? (int)$origCmdId : 0;
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Apagar', 'callback_data' => "APAGAR|$ownerId|$orig"]
            ]
        ]
    ];
}

// ✅ Cria ticket e botão “Ver resultado completo” (para consultas grandes)
function tg_ticket_create(int $ownerId, string $url, string $tipo='GEN', int $ttl = TG_TICKET_TTL): string {
  $key = substr(bin2hex(random_bytes(16)), 0, 24); // 24 hex
  $file = TG_TICKET_DIR . "/{$key}.json";
  $payload = [
    'owner_id' => $ownerId,
    'url'      => $url,
    'tipo'     => $tipo,
    'ts'       => time(),
    'ttl'      => $ttl,
  ];
  @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
  return $key;
}

function keyboard_ver_resultado(int $ownerId, string $tipo, string $key, int $origCmdId = 0): array {
  $orig = $origCmdId > 0 ? $origCmdId : 0;
  return [
    'inline_keyboard' => [
      [
        ['text' => '📄 Ver resultado completo', 'callback_data' => "TGVIEW|$ownerId|$tipo|$key"]
      ],
      [
        ['text' => 'Apagar', 'callback_data' => "APAGAR|$ownerId|$orig"]
      ]
    ]
  ];
}

/**
 * ✅ Fluxo veloz:
 * - manda 1 mensagem "Consultando..."
 * - depois edita para o resultado final com botão
 * (reduz chamadas e consumo)
 */
function sendLoading(int $chatId, int $replyTo): int {
  return replySmart($chatId, msg_busy(), $replyTo);
}

function finishLoading(int $chatId, int $loadingId, string $finalText, int $ownerId, int $origCmdId, string $chatType): void {
  $origCmdId = ($origCmdId > 0) ? $origCmdId : 0;
  
  // Verifica se há URL do Telegraph para adicionar botão customizado
  $replyMarkup = null;
  if (!empty($GLOBALS['telegraph_url'])) {
    $telegraphUrl = $GLOBALS['telegraph_url'];
    $replyMarkup = [
      'inline_keyboard' => [
        [
          ['text' => '📄 Ver Resultado Completo', 'url' => $telegraphUrl]
        ],
        [
          ['text' => 'Apagar', 'callback_data' => "APAGAR|$ownerId|$origCmdId"]
        ]
      ]
    ];
    // Limpa a variável global
    unset($GLOBALS['telegraph_url']);
    unset($GLOBALS['telegraph_button_text']);
  } else {
    $replyMarkup = keyboard_apagar($ownerId, $origCmdId);
  }

  // Se for grande demais, edições podem falhar: envia normal e apaga loading
  if ($loadingId <= 0 || mb_strlen($finalText, 'UTF-8') > 3900) {
    if ($loadingId > 0) deleteMessageSafe($chatId, $loadingId);

    $resultId = replySmart(
      $chatId,
      $finalText,
      $origCmdId,
      ['reply_markup' => $replyMarkup]
    );

    if ($chatType !== 'private' && $resultId > 0) {
      enqueue_autodelete($chatId, $resultId, $origCmdId, AUTO_DELETE_SECONDS);
    }
    return;
  }

  editMessageTextSafe([
    'chat_id'      => $chatId,
    'message_id'   => $loadingId,
    'text'         => $finalText,
    'parse_mode'   => 'HTML',
    'disable_web_page_preview' => true,
    'reply_markup' => $replyMarkup,
  ]);

  if ($chatType !== 'private') {
    enqueue_autodelete($chatId, $loadingId, $origCmdId, AUTO_DELETE_SECONDS);
  }
}

/**
 * Envia o resultado final da consulta (mantida)
 * ✅ Sempre com botão apagar
 * ✅ Em grupo agenda auto-delete
 * ✅ Em privado NÃO agenda (só botão)
 */
function sendResultFinal(
  int $chatId,
  string $resp,
  int $replyTo,
  int $ownerId,
  int $origCmdId,
  string $chatType
): void {

  $origCmdId = ($origCmdId > 0) ? $origCmdId : (($replyTo > 0) ? $replyTo : 0);

  $resultId = replySmart(
    $chatId,
    $resp,
    $replyTo,
    ['reply_markup' => keyboard_apagar($ownerId, $origCmdId)]
  );

  if ($chatType !== 'private' && $resultId > 0) {
    enqueue_autodelete($chatId, $resultId, $origCmdId, AUTO_DELETE_SECONDS);
  }
}

//////////////////// ASSINATURA ////////////////////
function append_signature(string $html, int $userId, string $firstName): string {
  $assinatura  = "👤 <b>Usuário:</b> " . mention_html($userId, $firstName) . "\n";
  $assinatura .= "🤖 <b>Bot:</b> @" . BOT_USERNAME;
  return rtrim($html) . "\n\n" . $assinatura;
}

function is_error_response(string $text): bool {
  $t = trim($text);
  if ($t === '') return true;

  // remove tags HTML do começo para não atrapalhar
  $plain = trim(strip_tags($t));

  // Detecta emoji de erro (com ou sem variação) no início
  if (preg_match('/^\s*(⚠️?|❗️?|🚫|⛔️?)/u', $plain)) return true;

  // Detecta palavras comuns de erro
  $lower = mb_strtolower($plain, 'UTF-8');
  if (strpos($lower, 'não encontrado') !== false) return true;
  if (strpos($lower, 'nao encontrado') !== false) return true;
  if (strpos($lower, 'inválid') !== false) return true;
  if (strpos($lower, 'indispon') !== false) return true;
  if (strpos($lower, 'fora do ar') !== false) return true;
  if (strpos($lower, 'não foi possível') !== false) return true;
  if (strpos($lower, 'nao foi possivel') !== false) return true;

  return false;
}

function is_consulta_cmd(string $cmd): bool {
    return in_array($cmd, [
        '/cpf','/cnpj','/cep','/nome',
        '/telefone','/tel','/placa',
        '/ip','/bin','/checker'
    ], true);
}

//////////////////// TECLADOS ////////////////////
function keyboard_main_private(int $ownerId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'➕ Adicionar em Grupo','url'=>'https://t.me/'.BOT_USERNAME.'?startgroup=new']
      ],
      [
        ['text'=>'🪪 Consultas',"callback_data"=>"MENU_CONSULTAS|$ownerId"],
        ['text'=>'📢 Canal','url'=>'https://t.me/GonzalesCanal']
      ],
      [
        ['text'=>'⚙️ Gerenciar Grupos',"callback_data"=>"GER_GRUPOS|$ownerId"]
      ],
      // ✅ BOTÃO VIP/RENOVAÇÃO
      [
        ['text'=>'💎 Meu Plano VIP',"callback_data"=>"VIP_MEUPLANO|$ownerId"]
      ],
      // ✅ SUPORTE
      [
        ['text'=>'🆘 Suporte','url'=>'https://t.me/GonzalesDev']
      ]
    ]
  ];
}

function keyboard_main_group(int $ownerId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'➕ Adicionar em Grupo','url'=>'https://t.me/'.BOT_USERNAME.'?startgroup=new']
      ],
      [
        ['text'=>'🪪 Consultas',"callback_data"=>"MENU_CONSULTAS|$ownerId"],
        ['text'=>'📢 Canal','url'=>'https://t.me/GonzalesCanal']
      ]
    ]
  ];
}

function keyboard_consultas(int $ownerId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'CPF',"callback_data"=>"CONSULTA_CPF|$ownerId"],
        ['text'=>'CNPJ',"callback_data"=>"CONSULTA_CNPJ|$ownerId"],
      ],
      [
        ['text'=>'CEP',"callback_data"=>"CONSULTA_CEP|$ownerId"],
      ],
      [
        ['text'=>'NOME',"callback_data"=>"CONSULTA_NOME|$ownerId"],
        ['text'=>'TELEFONE',"callback_data"=>"CONSULTA_TELEFONE|$ownerId"],
      ],
      [
        ['text'=>'PLACA',"callback_data"=>"CONSULTA_PLACA|$ownerId"],
      ],
      [
        ['text'=>'BIN',"callback_data"=>"CONSULTA_BIN|$ownerId"],
        ['text'=>'IP',"callback_data"=>"CONSULTA_IP|$ownerId"],
      ],
      [
        ['text'=>'↩️ Voltar',"callback_data"=>"BACK_MAIN|$ownerId"],
      ],
    ]
  ];
}

function keyboard_cpf_bases(int $ownerId, string $cpf, int $origCmdId): array {
    $cpfClean = preg_replace('/\D+/', '', $cpf);

    return [
        'inline_keyboard' => [
            // 1ª linha — Base Local
            [
                [
                    'text' => '📁 Base Local',
                    'callback_data' => "CPF_BASE|$ownerId|cpflocal|$cpfClean|$origCmdId"
                ],
            ],

            // 2ª linha — SISREG e Credilink
            [
                [
                    'text' => '🩺 SISREG-III',
                    'callback_data' => "CPF_BASE|$ownerId|cpfsisregi|$cpfClean|$origCmdId"
                ],
                [
                    'text' => '📉 Credilink',
                    'callback_data' => "CPF_BASE|$ownerId|credilinkcpf|$cpfClean|$origCmdId"
                ],
            ],

            // 3ª linha — CNH + SI-PNI
            [
                [
                    'text' => '🪪 CNH',
                    'callback_data' => "CPF_BASE|$ownerId|cpfcnh|$cpfClean|$origCmdId"
                ],
                [
                    'text' => '🧬 SI-PNI',
                    'callback_data' => "CPF_BASE|$ownerId|sipnicpf|$cpfClean|$origCmdId"
                ],
            ],

            // 4ª linha — Cancelar
            [
                [
                    'text' => '❌ Cancelar',
                    'callback_data' => "APAGAR|$ownerId|$origCmdId"
                ],
            ],
        ]
    ];
}

function keyboard_cpf_bases_private(int $ownerId, string $cpf, int $origCmdId): array {
    return keyboard_cpf_bases($ownerId, $cpf, $origCmdId);
}

function keyboard_cpf_bases_group(int $ownerId, string $cpf, int $origCmdId): array {
    $cpfClean = preg_replace('/\D+/', '', $cpf);

    return [
        'inline_keyboard' => [
            // 1ª linha — Base Local + Credilink
            [
                [
                    'text' => '📁 Base Local',
                    'callback_data' => "CPF_BASE|$ownerId|cpflocal|$cpfClean|$origCmdId"
                ],
                [
                    'text' => '📉 Credilink',
                    'callback_data' => "CPF_BASE|$ownerId|credilinkcpf|$cpfClean|$origCmdId"
                ],
            ],

            // 2ª linha — Cancelar
            [
                [
                    'text' => '❌ Cancelar',
                    'callback_data' => "APAGAR|$ownerId|$origCmdId"
                ],
            ],
        ]
    ];
}

function keyboard_ger_grupos(int $ownerId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'👥 Abrir Menu de Grupos', 'callback_data'=>"GER_GRUPOS_OPEN|$ownerId"]
      ],
      [
        ['text'=>'↩️ Voltar', 'callback_data'=>"GER_GRUPOS_BACK|$ownerId"]
      ]
    ]
  ];
}

//////////////////// MENUS ////////////////////
function sendMenu(int $chatId, int $userId, string $firstName, string $chatType, ?int $replyTo=null): void {
  $texto = "
<b>👋 Olá, " . mention_html($userId, $firstName) . " !</b>

<b>Bem-vindo ao Melhor Bot de Consultas</b> 🤖
Realize consultas completas com rapidez e total segurança.

<b>📋 Escolha uma opção para começar:</b>
";

  $kb = ($chatType === 'private')
      ? keyboard_main_private($userId)
      : keyboard_main_group($userId);

  tg('sendMessage', [
    'chat_id'                    => $chatId,
    'text'                       => trim($texto),
    'parse_mode'                 => 'HTML',
    'reply_markup'               => $kb,
    'reply_to_message_id'        => ($replyTo && $replyTo > 0) ? $replyTo : null,
    'allow_sending_without_reply'=> true,
    'disable_web_page_preview'   => true,
  ]);
}

function sendConsultasMenu(int $chatId, int $messageId, int $fromId, string $fromName, int $ownerId): void {
  $prefix = '👤 ' . mention_html($fromId, $fromName) . "\n";
  $text   = $prefix . "<b>Selecione o tipo de consulta:</b>\n<i>Toque em uma opção para ver o exemplo de uso.</i>";
  editMessageTextSafe([
    'chat_id'      => $chatId,
    'message_id'   => $messageId,
    'text'         => $text,
    'parse_mode'   => 'HTML',
    'reply_markup' => keyboard_consultas($ownerId),
    'disable_web_page_preview' => true,
  ]);
}

function sendHowTo(int $chatId, int $messageId, int $fromId, string $fromName, string $tipo, int $ownerId): void {
  $prefix = '👤 ' . mention_html($fromId, $fromName) . "\n";
  $map = [
    'cpf'       => ["<b>CPF</b>",       msg_use('Informe um CPF válido.', '/cpf 04303901067')],
    'cnpj'      => ["<b>CNPJ</b>",      msg_use('Informe um CNPJ válido.', '/cnpj 12345678000199')],
    'cep'       => ["<b>CEP</b>",       msg_use('Informe um CEP válido.', '/cep 01001000')],
    'nome'      => ["<b>NOME</b>",      msg_use('Informe o nome completo.', '/nome JOAO SILVA')],
    'telefone'  => ["<b>TELEFONE</b>",  msg_use('Informe um telefone válido.', '/telefone 11987654321')],
    'placa'     => ["<b>PLACA</b>",     msg_use('Informe uma placa válida.', '/placa TEE0B12')],
    'ip'        => ["<b>IP</b>",        msg_use('Informe um IP válido.', '/ip 8.8.8.8')],
    'bin'       => ["<b>BIN</b>",       msg_use('Informe um BIN válido.', '/bin 457173')],
  ];
  [$title, $how] = $map[$tipo] ?? ['Consulta','Use o comando apropriado.'];
  $text = $prefix . $title . "\n\n" . $how;

  editMessageTextSafe([
    'chat_id'      => $chatId,
    'message_id'   => $messageId,
    'text'         => $text,
    'parse_mode'   => 'HTML',
    'reply_markup' => keyboard_consultas($ownerId),
    'disable_web_page_preview' => true,
  ]);
}

//////////////////// CONSULTAS (loader) ////////////////////
function runConsulta(string $tipo, string $arg): string {
  $file = CONSULTAS_DIR . '/' . $tipo . '.php';
  if (!is_file($file)) {
    return "⚠️ Módulo de consulta <b>{$tipo}</b> não encontrado.\nCrie <code>consultas/{$tipo}.php</code>.";
  }

  $ARG    = $arg;
  $RESULT = null;

  $returned = include $file;

  if ($returned === '__SENT__') {
    return '';
  }

  if (is_string($returned) && trim($returned) !== '') return $returned;
  if (is_string($RESULT) && trim($RESULT) !== '') return $RESULT;

  return "⚠️ Módulo <b>{$tipo}</b> não retornou resultado.";
}

//////////////////// ANTIFLOOD ////////////////////
function security_read(): array {
  $fp = @fopen(SECURITY_FILE, 'c+');
  if (!$fp) return [];
  @flock($fp, LOCK_SH);
  $content = stream_get_contents($fp);
  @flock($fp, LOCK_UN);
  @fclose($fp);
  $data = json_decode($content ?: "{}", true);
  return is_array($data) ? $data : [];
}

function security_write(array $data): void {
  $fp = @fopen(SECURITY_FILE, 'c+');
  if (!$fp) return;
  @flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
  fflush($fp);
  @flock($fp, LOCK_UN);
  @fclose($fp);
}

function format_duration_br(int $seconds): string {
  $seconds = max(0, $seconds);
  $m = intdiv($seconds, 60);
  $s = $seconds % 60;
  if ($m > 0 && $s > 0) return $m . 'min ' . $s . 's';
  if ($m > 0) return $m . 'min';
  return $s . 's';
}

function security_guard(int $userId): array {
  // 👑 Dono do bot não tem restrições de antiflood
  if ($userId === ADMIN_ID) {
    return [true, null, 0];
  }

  $data = security_read();
  $now  = microtime(true);

  if (!isset($data[$userId])) {
    $data[$userId] = [
      'last'       => $now,
      'count'      => 0,
      'ban_until'  => 0,
      'ban_level'  => 0,
    ];
  } else {
    if (!isset($data[$userId]['ban_until'])) $data[$userId]['ban_until'] = 0;
    if (!isset($data[$userId]['ban_level'])) $data[$userId]['ban_level'] = 0;
  }

  if ($data[$userId]['ban_until'] > $now) {
    $remain = (int)ceil($data[$userId]['ban_until'] - $now);
    $pretty = format_duration_br($remain);
    $msg = "🚫 <b>Acesso temporariamente bloqueado.</b>\n"
         . "Você enviou muitos comandos em um curto intervalo de tempo.\n\n"
         . "Aguarde <b>{$pretty}</b> e tente novamente.";
    return [false, $msg, $remain];
  }

  if ($data[$userId]['ban_until'] > 0 && $data[$userId]['ban_until'] <= $now) {
    $data[$userId]['ban_until'] = 0;
    $data[$userId]['count']     = 0;
  }

  $elapsed = $now - (float)$data[$userId]['last'];
  if ($elapsed <= WINDOW_SECONDS) {
    $data[$userId]['count'] = (int)$data[$userId]['count'] + 1;
  } else {
    $dec = (int)floor($elapsed / WINDOW_SECONDS);
    $data[$userId]['count'] = max(0, (int)$data[$userId]['count'] - $dec);
  }
  $data[$userId]['last'] = $now;

  if ($data[$userId]['count'] >= MAX_EVENTS) {
    $data[$userId]['ban_level'] = (int)($data[$userId]['ban_level'] ?? 0) + 1;

    $banFor = BAN_SECONDS * pow(BAN_MULTIPLIER, max(0, $data[$userId]['ban_level'] - 1));
    if ($banFor > BAN_MAX_SECONDS) $banFor = BAN_MAX_SECONDS;

    $data[$userId]['ban_until'] = $now + $banFor;
    $data[$userId]['count']     = 0;
    security_write($data);

    $banForInt = (int)$banFor;
    $pretty    = format_duration_br($banForInt);
    $msg = "⚠️ <b>Limite de uso ultrapassado.</b>\n"
         . "Para manter o bot rápido e estável para todos, seu acesso foi temporariamente bloqueado.\n\n"
         . "Duração do bloqueio: <b>{$pretty}</b>.";
    return [false, $msg, $banForInt];
  }

  security_write($data);
  return [true, null, 0];
}

// ===================== VIP INFO HELPERS =====================
function vip_get_all(): array {
    $users = vip_load_users();
    return is_array($users) ? $users : [];
}

function vip_count_stats(): array {
    $users = vip_get_all();
    $now = time();
    $ativos = 0;
    $vencidos = 0;

    foreach ($users as $uid => $info) {
        $exp = (int)($info['expires_at'] ?? 0);
        if ($exp > $now) $ativos++;
        else $vencidos++;
    }
    return [$ativos, $vencidos];
}

function vip_clean_expired(): int {
    $users = vip_get_all();
    $now = time();
    $removed = 0;

    foreach ($users as $uid => $info) {
        $exp = (int)($info['expires_at'] ?? 0);
        if ($exp <= $now) {
            unset($users[$uid]);
            $removed++;
        }
    }

    vip_save_users($users);
    return $removed;
}
// =================== FIM VIP INFO HELPERS ===================

//////////////////// HEALTHCHECK ////////////////////
if (($_GET['health'] ?? '') === '1') { echo 'ok'; exit; }

require_once __DIR__ . '/force_join.php';
require_once __DIR__ . '/group_admin/bootstrap.php';
$helpers = __DIR__ . '/misticpay/helpers.php';
if (file_exists($helpers)) {
    require_once $helpers;
}

//////////////////// GROUP ADMIN HELPERS ////////////////////
function is_group_chat(string $type): bool { return in_array($type, ['group','supergroup'], true); }

function is_admin_of_chat(int $chatId, int $userId): bool {
  $r = tg('getChatMember', ['chat_id'=>$chatId,'user_id'=>$userId]);
  if (($r['ok'] ?? false) !== true) return false;
  $st = (string)($r['result']['status'] ?? '');
  return in_array($st, ['administrator','creator'], true);
}

function cmd_target_user_from_reply_or_entity(array $m): ?array {
  if (isset($m['reply_to_message']['from']['id'])) {
    return [
      'id' => (int)$m['reply_to_message']['from']['id'],
      'name' => (string)($m['reply_to_message']['from']['first_name'] ?? 'Usuário'),
    ];
  }
  $entities = $m['entities'] ?? [];
  if (is_array($entities)) {
    foreach ($entities as $e) {
      if (($e['type'] ?? '') === 'text_mention' && isset($e['user']['id'])) {
        return [
          'id' => (int)$e['user']['id'],
          'name' => (string)($e['user']['first_name'] ?? 'Usuário'),
        ];
      }
    }
  }
  return null;
}

//////////////////// ENTRADA ////////////////////
$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) { echo 'ok'; exit; }
$update = json_decode($raw, true);
if (!is_array($update)) { echo 'ok'; exit; }

// ===== IGNORA UPDATE ANTIGO (ANTI BACKLOG) =====
$now = time();

// mensagem velha
if (isset($update['message']['date'])) {
    if (($now - (int)$update['message']['date']) > 20) {
        echo 'ok'; exit;
    }
}

// ===== FIM =====

try {

  // eventos de membro do bot
  if (isset($update['my_chat_member'])) {
    if (function_exists('ga_on_my_chat_member')) ga_on_my_chat_member($update['my_chat_member']);
    echo 'ok'; exit;
  }
  if (isset($update['chat_member'])) {
    if (function_exists('ga_on_my_chat_member')) ga_on_my_chat_member($update['chat_member']);
    echo 'ok'; exit;
  }

  // ===================== MESSAGE =====================
  if (isset($update['message'])) {
    $m         = $update['message'];
    $chatId    = (int)($m['chat']['id'] ?? 0);
    $chatType  = (string)($m['chat']['type'] ?? 'private');
    $text      = trim((string)($m['text'] ?? ''));
    $from      = (array)($m['from'] ?? []);
    $userId    = (int)($from['id'] ?? 0);
    $firstName = (string)($from['first_name'] ?? 'Usuário');
    $msgId     = (int)($m['message_id'] ?? 0);
    $GLOBALS['chatId'] = $chatId;
    $GLOBALS['msgId']  = $msgId;

    // detecta comando
    $isCmd = false;
    $cmdToken = '';
    if ($text !== '') {
      $cmdToken = explode(' ', $text)[0] ?? '';
      $isCmd = (strlen($cmdToken) > 1 && $cmdToken[0] === '/');
    }

    $cmd  = $isCmd ? strtolower(preg_replace('/@.+$/', '', $cmdToken)) : '';
    $args = $isCmd ? trim(substr($text, strlen($cmdToken))) : '';

    // group_admin no grupo: só roda para mensagens NÃO comando
    if (is_group_chat($chatType) && !$isCmd && function_exists('ga_handle_group_message')) {
      ga_handle_group_message($m);
    }

    // group_admin no PV: só roda se /grupos OU pendência
    if ($chatType === 'private' && function_exists('ga_handle_private_message')) {
      $hasPending = false;
      if (function_exists('ga_pending_get')) {
        $p = ga_pending_get($userId);
        $hasPending = !empty($p);
      }

      $cmdSafe = ($isCmd ? $cmd : '');

      if ($cmdSafe === '/grupos' || ($hasPending && !in_array($cmdSafe, ['/menu', '/start'], true))) {
        ga_handle_private_message($m);
        if ($cmdSafe === '/grupos') { echo 'ok'; exit; }
      }
    }

    // Force Join para comandos (exceto /start /menu) no PV
    if ($isCmd) {
      $firstTokenNorm = strtolower(preg_replace('/@.+$/', '', $cmdToken));
      if (!in_array($firstTokenNorm, ['/start','/menu'], true)) {
        if (function_exists('gate_private_force_join')) {
          if (!gate_private_force_join($userId, $chatType, $firstTokenNorm, $chatId, $msgId)) {
            echo 'ok'; exit;
          }
        }
      }
    }

    // ===================== ANTIFLOOD (VOLUME + REPETIÇÃO DE COMANDO) =====================

// ---- CONFIGURAÇÕES DO ANTIFLOOD POR COMANDO ----
define('CMD_WINDOW_SECONDS', 180); // 3 minutos
define('CMD_MAX_REPEAT', 3);       // máximo do mesmo comando no período

// ---- FUNÇÃO ANTIFLOOD POR COMANDO REPETIDO ----
function command_flood_guard(int $userId, string $cmd): array {
    // 👑 Dono do bot não tem restrições de antiflood
    if ($userId === ADMIN_ID) {
        return [true, null];
    }

    $file = DATA_DIR . '/command_flood.json';
    $now  = time();

    if (!file_exists($file)) {
        @file_put_contents($file, '{}');
    }

    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data)) $data = [];

    if (!isset($data[$userId])) {
        $data[$userId] = [];
    }

    if (!isset($data[$userId][$cmd])) {
        $data[$userId][$cmd] = [];
    }

    // remove execuções fora da janela
    $data[$userId][$cmd] = array_values(array_filter(
        $data[$userId][$cmd],
        fn($t) => ($now - $t) <= CMD_WINDOW_SECONDS
    ));

    // adiciona execução atual
    $data[$userId][$cmd][] = $now;

    // salva estado
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

    // verifica excesso
    if (count($data[$userId][$cmd]) > CMD_MAX_REPEAT) {
        $remain = CMD_WINDOW_SECONDS - ($now - $data[$userId][$cmd][0]);
        $remain = max(60, $remain);

        $msg = "🚫 <b>Uso excessivo do comando <code>{$cmd}</code>.</b>\n\n"
             . "Você está repetindo a mesma Comando várias vezes.\n"
             . "Aguarde <b>" . format_duration_br($remain) . "</b> para tentar novamente.";

        return [false, $msg];
    }

    return [true, null];
}

// ---- EXECUÇÃO DO ANTIFLOOD ----
if ($isCmd) {

    // 1️⃣ antiflood por volume (SEU ORIGINAL)
    [$allow, $msgBlock] = security_guard($userId);
    if (!$allow) {
        sendResultFinal($chatId, $msgBlock, $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
    }

    // 2️⃣ antiflood por repetição do mesmo comando
// 🔒 SOMENTE /placa entra nesse bloqueio
if ($cmd === '/placa') {
    [$okCmd, $msgCmd] = command_flood_guard($userId, $cmd);
    if (!$okCmd) {
        sendResultFinal($chatId, $msgCmd, $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
    }
}
}

// ===================== VIP PAYWALL =====================
if (
    $isCmd &&
    $chatType === 'private' &&
    is_consulta_cmd($cmd) &&
    !user_is_vip($userId)
) {

    $text = "🔐 <b>Acesso ao privado não ativo</b>\n\n"
      . "Para utilizar o bot em <b>conversas privadas</b>, é necessário realizar a "
      . "<b>ativação da sua conta na plataforma</b>.\n\n"
      . "Essa ativação <b>não se trata de VIP</b>, mas sim de um controle de acesso "
      . "para garantir estabilidade, segurança e uso adequado do sistema.\n\n"
      . "📄 <b>Planos de ativação disponíveis:</b>\n\n"
      . "• 1 semana — <b>R$ 10,00</b>\n"
      . "• 2 semanas — <b>R$ 15,00</b>\n"
      . "• 1 mês — <b>R$ 25,00</b>\n"
      . "• 6 meses — <b>R$ 120,00</b>\n\n"
      . "💳 Após a confirmação do pagamento, o acesso é "
      . "<b>liberado automaticamente</b>.\n\n"
      . "<i>Selecione uma opção abaixo para ativar seu acesso 👇</i>";

    tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $msgId,
        'reply_markup' => [
    'inline_keyboard' => [
        // 1ª linha — 1 semana
        [
            ['text' => '1 Semana', 'callback_data' => "VIP_BUY|$userId|vip_7"]
        ],

        // 2ª linha — 15 dias + 1 mês
        [
            ['text' => '2 Semanas', 'callback_data' => "VIP_BUY|$userId|vip_14"],
            ['text' => '1 Mês',     'callback_data' => "VIP_BUY|$userId|vip_30"]
        ],

        // 3ª linha — 6 meses
        [
            ['text' => '6 Meses', 'callback_data' => "VIP_BUY|$userId|vip_180"]
        ]
    ]
]
    ]);

    echo 'ok'; exit;
}
// ===================== FIM VIP PAYWALL =====================

    // /start /menu
    if ($isCmd && in_array($cmd, ['/start','/menu'], true)) {
      sendMenu($chatId, $userId, $firstName, $chatType, $msgId);
      echo 'ok'; exit;
    }

// ===================== PAINEL ADMIN (TELEGRAM) =====================
if ($isCmd && $cmd === '/admin') {
    // 👑 Apenas o dono do bot pode acessar
    if ($userId !== ADMIN_ID) {
        echo 'ok'; exit;
    }

    // Carregar estatísticas
    $users = vip_load_users();
    $now = time();
    $ativos = 0;
    $expirados = 0;
    
    foreach ($users as $uid => $info) {
        if (($info['expires_at'] ?? 0) > $now) {
            $ativos++;
        } else {
            $expirados++;
        }
    }
    
    $total = count($users);
    
    $texto = "👑 <b>PAINEL DE ADMINISTRAÇÃO</b>\n\n";
    $texto .= "📊 <b>Estatísticas:</b>\n";
    $texto .= "✅ Ativos: <code>{$ativos}</code>\n";
    $texto .= "❌ Expirados: <code>{$expirados}</code>\n";
    $texto .= "👥 Total: <code>{$total}</code>\n\n";
    $texto .= "🎛️ <b>Escolha uma ação abaixo:</b>";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ Adicionar VIP', 'callback_data' => 'admin_add'],
                ['text' => '🔄 Renovar VIP', 'callback_data' => 'admin_renew']
            ],
            [
                ['text' => '🗑️ Remover VIP', 'callback_data' => 'admin_remove'],
                ['text' => '👥 Ver Ativos', 'callback_data' => 'admin_list_active']
            ],
            [
                ['text' => '❌ Ver Expirados', 'callback_data' => 'admin_list_expired'],
                ['text' => '🧹 Limpar Expirados', 'callback_data' => 'admin_clean']
            ],
            [
                ['text' => '🔄 Atualizar', 'callback_data' => 'admin_refresh']
            ]
        ]
    ];
    
    tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => $texto,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $msgId,
        'allow_sending_without_reply' => true,
        'reply_markup' => json_encode($keyboard)
    ]);
    
    echo 'ok'; exit;
}

// ===================== MEU VIP (USUÁRIO) =====================
if ($isCmd && $cmd === '/meuvip') {
    // Carrega dados do usuário
    $users = vip_load_users();
    $now = time();
    
    // Verifica se o usuário tem VIP
    if (!isset($users[$userId])) {
        tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ <b>Você não possui plano ativo.</b>\n\n"
                    . "💎 Para ativar seu acesso, use /vip",
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $msgId,
            'allow_sending_without_reply' => true
        ]);
        echo 'ok'; exit;
    }
    
    $vipData = $users[$userId];
    $expiresAt = (int)($vipData['expires_at'] ?? 0);
    
    // Se expirou
    if ($expiresAt <= $now) {
        tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ <b>Seu plano expirou!</b>\n\n"
                    . "📅 Expirou em: " . date('d/m/Y H:i', $expiresAt) . "\n\n"
                    . "🔄 Para renovar seu acesso, use /vip",
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $msgId,
            'allow_sending_without_reply' => true,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🔄 Renovar Agora', 'callback_data' => "VIP_renovar|{$userId}|0"]]
                ]
            ])
        ]);
        echo 'ok'; exit;
    }
    
    // Calcula tempo restante
    $diff = $expiresAt - $now;
    $diasRestantes = floor($diff / 86400);
    $horasRestantes = floor(($diff % 86400) / 3600);
    $minutosRestantes = floor(($diff % 3600) / 60);
    
    if ($diasRestantes > 0) {
        $tempoRestante = "{$diasRestantes} dia" . ($diasRestantes > 1 ? 's' : '');
        if ($horasRestantes > 0) {
            $tempoRestante .= " e {$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
        }
    } elseif ($horasRestantes > 0) {
        $tempoRestante = "{$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
        if ($minutosRestantes > 0) {
            $tempoRestante .= " e {$minutosRestantes} minuto" . ($minutosRestantes > 1 ? 's' : '');
        }
    } else {
        $tempoRestante = "{$minutosRestantes} minuto" . ($minutosRestantes > 1 ? 's' : '');
    }
    
    // Formata data de expiração
    $expiraEm = date('d/m/Y', $expiresAt);
    $expiraHora = date('H:i', $expiresAt);
    
    // Monta mensagem
    $texto = "💎 <b>MEU PLANO VIP</b>\n\n";
    $texto .= "✅ <b>Status:</b> Ativo\n\n";
    $texto .= "📅 <b>Expira em:</b> {$expiraEm} às {$expiraHora}\n";
    $texto .= "⏳ <b>Tempo restante:</b> {$tempoRestante}\n\n";
    
    // Alerta se falta menos de 3 dias
    if ($diasRestantes < 3) {
        $texto .= "⚠️ <i>Seu plano está próximo do vencimento!</i>\n\n";
    }
    
    $texto .= "🚀 Aproveite seu acesso completo às consultas!\n\n";
    $texto .= "💬 Use /menu para ver os comandos disponíveis.";
    
    // Botões
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Renovar Plano', 'callback_data' => "VIP_renovar|{$userId}|0"]
            ],
            [
                ['text' => '🗑️ Cancelar Plano', 'callback_data' => "VIP_cancelar|{$userId}|0"]
            ]
        ]
    ];
    
    tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => $texto,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $msgId,
        'allow_sending_without_reply' => true,
        'reply_markup' => json_encode($keyboard)
    ]);
    
    echo 'ok'; exit;
}

// ===================== ADD VIP (ADMIN) =====================
if ($isCmd && $cmd === '/addvip') {

    // 🔐 COLOQUE SEU ID AQUI
    // Usa constante ADMIN_ID definida no topo

    if ($userId !== ADMIN_ID) {
    echo 'ok'; exit;
}

    $argsParts = preg_split('/\s+/', trim($args));
    $targetId  = (int)($argsParts[0] ?? 0);
    $timeRaw   = strtolower($argsParts[1] ?? '');

    if ($targetId <= 0 || $timeRaw === '') {
        sendResultFinal(
            $chatId,
            "⚠️ <b>Uso correto:</b>\n\n"
            . "<code>/addvip ID 10d</code>\n"
            . "<code>/addvip ID 5h</code>\n"
            . "<code>/addvip ID 30m</code>",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    // converte tempo
    if (!preg_match('/^(\d+)(d|h|m)$/', $timeRaw, $m)) {
        sendResultFinal(
            $chatId,
            "⚠️ Formato inválido. Use <code>10d</code>, <code>5h</code> ou <code>30m</code>.",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    $value = (int)$m[1];
    $unit  = $m[2];

    $seconds = match ($unit) {
        'd' => $value * 86400,
        'h' => $value * 3600,
        'm' => $value * 60,
    };

    // adiciona VIP
    $users = vip_load_users();
    $now   = time();

    if (!isset($users[$targetId]) || ($users[$targetId]['expires_at'] ?? 0) < $now) {
        $users[$targetId]['expires_at'] = $now + $seconds;
    } else {
        $users[$targetId]['expires_at'] += $seconds;
    }

    vip_save_users($users);

    sendResultFinal(
        $chatId,
        "✅ <b>VIP adicionado com sucesso</b>\n\n"
        . "👤 ID: <code>{$targetId}</code>\n"
        . "⏳ Tempo: <b>{$timeRaw}</b>",
        $msgId, $userId, $msgId, $chatType
    );

    echo 'ok'; exit;
}
// ===================== FIM ADD VIP =====================

// ===================== REMOVE VIP (ADMIN) =====================
if ($isCmd && $cmd === '/rm') {

    // 🔐 COLOQUE SEU ID AQUI (MESMO DO /addvip)
    // Usa constante ADMIN_ID definida no topo

    if ($userId !== ADMIN_ID) {
    echo 'ok'; exit;
}

    $targetId = (int)trim($args);

    if ($targetId <= 0) {
        sendResultFinal(
            $chatId,
            "⚠️ <b>Uso correto:</b>\n\n<code>/rm ID_DO_USUARIO</code>",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    $users = vip_load_users();

    if (!isset($users[$targetId])) {
        sendResultFinal(
            $chatId,
            "ℹ️ O usuário <code>{$targetId}</code> não possui VIP ativo.",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    unset($users[$targetId]);
    vip_save_users($users);

    sendResultFinal(
        $chatId,
        "✅ <b>VIP removido com sucesso</b>\n\n👤 ID: <code>{$targetId}</code>",
        $msgId, $userId, $msgId, $chatType
    );

    echo 'ok'; exit;
}
// ===================== FIM REMOVE VIP =====================

// ===================== INFO VIP (ADMIN) =====================
if ($isCmd && $cmd === '/infovip') {

    // Usa constante ADMIN_ID definida no topo

    // silencioso para não-admin
    if ($userId !== ADMIN_ID) { echo 'ok'; exit; }

    [$ativos, $vencidos] = vip_count_stats();

    $txt  = "📊 <b>STATUS VIP DO BOT</b>\n\n";
    $txt .= "👑 <b>VIPs ativos:</b> {$ativos}\n";
    $txt .= "⏰ <b>VIPs vencidos:</b> {$vencidos}\n\n";
    $txt .= "🗓️ <i>Atualizado:</i> " . date('d/m/Y H:i');

    tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => $txt,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $msgId,
        'allow_sending_without_reply' => true,
        'reply_markup' => [
            'inline_keyboard' => [
                [
                    ['text' => '🧹 Limpar VIPs vencidos', 'callback_data' => "VIP_CLEAN|{$userId}"]
                ]
            ]
        ]
    ]);

    echo 'ok'; exit;
}
// =================== FIM INFO VIP (ADMIN) ===================

    // /id
    if ($isCmd && $cmd === '/id') {
      if ($chatType === 'private') {
        $resp = "🧾 <b>Suas informações</b>\n\n"
              . "👤 Usuário: " . mention_html($userId, $firstName) . "\n"
              . "🆔 ID: <code>{$userId}</code>";
      } else {
        $title = (string)($m['chat']['title'] ?? '');
        $resp = "🧾 <b>Informações</b>\n\n"
              . "👤 Usuário: " . mention_html($userId, $firstName) . "\n"
              . "🆔 User ID: <code>{$userId}</code>\n\n"
              . "👥 Grupo: <b>" . htmlspecialchars($title ?: 'Grupo', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') . "</b>\n"
              . "🆔 Chat ID: <code>{$chatId}</code>";
      }
      sendResultFinal($chatId, $resp, $msgId, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    // /mute /ban (reply)
    if ($isCmd && ($cmd === '/mute' || $cmd === '/ban') && is_group_chat($chatType)) {
      if (!is_admin_of_chat($chatId, $userId)) {
        sendResultFinal($chatId, "⚠️ Apenas administradores podem usar <code>{$cmd}</code>.", $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }

      $target = cmd_target_user_from_reply_or_entity($m);
      if (!$target) {
        $msg = "⚠️ <b>Uso incorreto.</b>\n\nResponda a mensagem do usuário e envie <code>{$cmd}</code>.";
        sendResultFinal($chatId, $msg, $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }

      $tid = (int)$target['id'];
      if ($tid <= 0) { echo 'ok'; exit; }

      if ($cmd === '/mute') {
        $until = time() + 300;
        tg('restrictChatMember', [
          'chat_id' => $chatId,
          'user_id' => $tid,
          'permissions' => [ 'can_send_messages' => false ],
          'until_date' => $until,
        ]);
        sendResultFinal($chatId, "✅ Usuário silenciado (5 min).", $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }

      if ($cmd === '/ban') {
        tg('banChatMember', [
          'chat_id' => $chatId,
          'user_id' => $tid,
          'revoke_messages' => false,
        ]);
        sendResultFinal($chatId, "✅ Usuário banido.", $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
    }

    // ===================== CONSULTAS =====================

    if ($isCmd && $cmd === '/ip') {
      $ip = trim($args);
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        sendResultFinal($chatId, msg_use('IP inválido.', '/ip 8.8.8.8'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('ip', $ip);
      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/bin') {
      $binDigits = preg_replace('/\D+/', '', $args);
      if ($binDigits === '' || strlen($binDigits) < 6 || strlen($binDigits) > 8) {
        sendResultFinal($chatId, msg_use('BIN inválido.', '/bin 457173'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('bin', $binDigits);
      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/cpf') {
      // Usa função global de normalização
      $validacao = normalizarCPF($args);
      
      if (!$validacao['valido']) {
        $msgErro = "⚠️ <b>CPF inválido!</b>\n\n" . $validacao['erro'] . "\n\n"
                 . "<b>Exemplos:</b>\n"
                 . "• <code>/cpf 123.456.789-01</code>\n"
                 . "• <code>/cpf 12345678901</code>";
        sendResultFinal($chatId, $msgErro, $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      
      $cpf = $validacao['cpf'];
      $cpfFormatado = $validacao['formatado'];

      $textoMenu  = "👤 Olá " . mention_html($userId, $firstName) . "\n\n";
      $textoMenu .= "🧾 <b>Consulta de CPF</b>\n\n";
      $textoMenu .= "<b>CPF informado:</b> <code>$cpfFormatado</code>\n\n";
      $textoMenu .= "<b>Selecione a base de dados desejada:</b>";

      $kbCpf = ($chatType === 'private')
    ? keyboard_cpf_bases_private($userId, $cpf, $msgId)
    : keyboard_cpf_bases_group($userId, $cpf, $msgId);

tg('sendMessage', [
    'chat_id' => $chatId,
    'text' => $textoMenu,
    'parse_mode' => 'HTML',
    'reply_to_message_id' => $msgId,
    'allow_sending_without_reply'=> true,
    'reply_markup' => $kbCpf,
]);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/nome') {
      if ($args === '' || mb_strlen($args, 'UTF-8') < 3) {
        sendResultFinal($chatId, msg_use('Informe o comando seguido do nome completo.', '/nome JOAO SILVA'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('nome', $args);
      if ($resp !== '') {
        if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
        finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      } else {
        if ($loadingId > 0) deleteMessageSafe($chatId, $loadingId);
      }
      echo 'ok'; exit;
    }

if ($isCmd && $cmd === '/checker') {

    // 🔥 TEXTO REAL DO SEU CORE
    $rawText = trim($text);

    // remove /checker ou /checker@Bot
    $rawText = preg_replace('/^\/checker(@\w+)?/i', '', $rawText);
    $rawText = trim($rawText);

    if ($rawText === '') {
        sendResultFinal(
            $chatId,
            "⚠️ <b>Uso correto:</b>\n\n<code>/checker usuario:senha</code>\nAté 10 logins (1 por linha).",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    // aceita quebra por linha OU espaço
    $linhas = preg_split('/\r\n|\r|\n|\s+/', $rawText);
    $linhas = array_values(array_filter(array_map('trim', $linhas)));

    if (count($linhas) > 10) {
        sendResultFinal(
            $chatId,
            "⚠️ Máximo permitido: <b>10 logins</b>.",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    $loadingId = sendLoading($chatId, $msgId);

    require_once __DIR__ . '/checker_login.php';

    $on = 0;
    $resultados = [];

    foreach ($linhas as $linha) {

        if (!str_contains($linha, ':')) {
            $resultados[] = "⚠️ <b>Formato inválido:</b> <code>$linha</code>";
            continue;
        }

        [$user, $senha] = explode(':', $linha, 2);
        $user  = strtoupper(trim($user));   // 👈 como o painel exige
        $senha = strtoupper(trim($senha));

        $ok = checkerLoginSisreg($user, $senha);

        if ($ok) {
            $on++;
            $resultados[] = "✅ <b>$user</b> → ONLINE";
        } else {
            $resultados[] = "❌ <b>$user</b> → OFFLINE";
        }

        sleep(1); // anti-ban
    }

    if ($loadingId) deleteMessageSafe($chatId, $loadingId);

    if ($on === 0) {
        sendResultFinal(
            $chatId,
            "❌ <b>Todos os logins estão OFFLINE</b>",
            $msgId, $userId, $msgId, $chatType
        );
        echo 'ok'; exit;
    }

    sendResultFinal(
        $chatId,
        "🔍 <b>Resultado do Checker</b>\n\n" . implode("\n", $resultados),
        $msgId, $userId, $msgId, $chatType
    );
    echo 'ok'; exit;
}

    if ($isCmd && ($cmd === '/tel' || $cmd === '/telefone')) {
      $digits = preg_replace('/\D+/', '', $args);
      if ($digits === '' || strlen($digits) < 10 || strlen($digits) > 11) {
        sendResultFinal($chatId, msg_use('Telefone inválido.', '/telefone 11987654321'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('telefone', $digits);
      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/placa') {
      $placa = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $args));
      if ($placa === '' || !preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) {
        sendResultFinal($chatId, msg_use('Placa inválida.', '/placa TEE0B12'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('placa', $placa);
      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/cep') {
      $cepLimpo = preg_replace('/\D+/', '', $args);

      if ($cepLimpo === '' || strlen($cepLimpo) !== 8) {
        sendResultFinal($chatId, msg_use('CEP inválido.', '/cep 01001000'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }

      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('cep', $cepLimpo);

      if ($resp === '') {
        if ($loadingId > 0) deleteMessageSafe($chatId, $loadingId);
        echo 'ok'; exit;
      }

      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    if ($isCmd && $cmd === '/cnpj') {
      $digits = preg_replace('/\D+/', '', $args);
      if ($digits === '' || strlen($digits) !== 14) {
        sendResultFinal($chatId, msg_use('CNPJ inválido.', '/cnpj 35391897000106'), $msgId, $userId, $msgId, $chatType);
        echo 'ok'; exit;
      }
      $loadingId = sendLoading($chatId, $msgId);
      $resp = runConsulta('cnpj', $digits);
      if (!is_error_response($resp)) $resp = append_signature($resp, $userId, $firstName);
      finishLoading($chatId, $loadingId, $resp, $userId, $msgId, $chatType);
      echo 'ok'; exit;
    }

    // ✅ (REMOVIDO) SUPORTE DE IA — não mexe com mensagens que não são comandos
    echo 'ok'; exit;
  }

  // ===================== CALLBACK =====================
  if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    
    // ✅ LOG ABSOLUTO - REGISTRA **TODOS** OS CALLBACKS
    $rawData = (string)($cb['data'] ?? '');
    $fromId = (int)($cb['from']['id'] ?? 0);
    $cbAge = isset($cb['message']['date']) ? (time() - (int)$cb['message']['date']) : 0;
    
    logx("📥 [GLOBAL] CALLBACK RECEBIDO DE QUALQUER USUÁRIO:");
    logx("   └─ Data: {$rawData}");
    logx("   └─ From: {$fromId}");
    logx("   └─ Age: {$cbAge}s (" . round($cbAge/3600, 1) . "h)");
    
    // ✅ RESPONDE O CALLBACK **IMEDIATAMENTE** - ANTES DE QUALQUER COISA!
    // Isso garante que o Telegram sempre receba uma resposta rápida
    $callbackAnswered = false;
    if (isset($cb['id']) && !empty($cb['id'])) {
      $quickAnswer = answerCallback($cb['id'], '', false);
      $callbackAnswered = $quickAnswer;
      
      if (!$quickAnswer) {
        // ❌ Telegram rejeitou o callback (botão muito antigo)
        logx("⚠️ Callback EXPIRADO! Telegram rejeitou. Vou editar mensagem com novos botões...");
        
        // Extrai informações do callback
        $chatId = (int)($cb['message']['chat']['id'] ?? 0);
        $messageId = (int)($cb['message']['message_id'] ?? 0);
        $fromId = (int)($cb['from']['id'] ?? 0);
        $fromName = (string)($cb['from']['first_name'] ?? 'Usuário');
        
        // Se for um callback de menu, regenera o menu
        if ($rawData && strpos($rawData, 'MENU_CONSULTAS') !== false) {
          logx("🔄 Regenerando menu de consultas...");
          sendConsultasMenu($chatId, $messageId, $fromId, $fromName, $fromId);
          echo 'ok'; exit;
        }
        
        if ($rawData && strpos($rawData, 'BACK_MAIN') !== false) {
          logx("🔄 Regenerando menu principal...");
          $chatType = (string)($cb['message']['chat']['type'] ?? 'private');
          $text = "<b>👋 Olá, " . mention_html($fromId, $fromName) . " !</b>\n\n<b>Bem-vindo ao Melhor Bot de Consultas</b> 🤖\nRealize consultas completas com rapidez e total segurança.\n\n<b>📋 Escolha uma opção para começar:</b>";
          $kb = ($chatType === 'private') ? keyboard_main_private($fromId) : keyboard_main_group($fromId);
          editMessageTextSafe([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => $kb,
            'disable_web_page_preview' => true,
          ]);
          echo 'ok'; exit;
        }
        
        if ($rawData && (strpos($rawData, 'VIP_MEUPLANO') !== false || strpos($rawData, 'GER_GRUPOS') !== false)) {
          logx("🔄 Callback expirado para ação sensível. Enviando nova mensagem...");
          editMessageTextSafe([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "⚠️ <b>Este botão expirou!</b>\n\nOs botões inline do Telegram expiram após algumas horas.\n\nPor favor, use <code>/menu</code> para gerar novos botões.",
            'parse_mode' => 'HTML'
          ]);
          echo 'ok'; exit;
        }
        
        // Para outros callbacks, apenas informa
        logx("⚠️ Callback expirado mas vou tentar processar mesmo assim...");
      } else {
        logx("⚡ Callback respondido IMEDIATAMENTE: SUCESSO");
      }
    }
    
    // ✅ LOG DE DEBUG
    $cbAge = isset($cb['message']['date']) ? (time() - (int)$cb['message']['date']) : 0;
    logx("🔔 CALLBACK RECEBIDO: " . json_encode([
      'callback_id' => $cb['id'] ?? 'N/A',
      'data' => $cb['data'] ?? 'N/A',
      'from_id' => $cb['from']['id'] ?? 'N/A',
      'message_date' => $cb['message']['date'] ?? 'N/A',
      'age_seconds' => $cbAge,
      'age_hours' => round($cbAge / 3600, 1)
    ]));

    // ❌ REMOVIDO O LIMITE DE TEMPO!
    // Agora processa TODOS os callbacks, não importa a idade
    // Se o Telegram rejeitar (callback_answered = false), 
    // o código vai editar a mensagem com botões novos

    // callbacks do group_admin primeiro
    if (function_exists('ga_handle_callback')) {
      if (ga_handle_callback($cb)) { echo 'ok'; exit; }
    }

    // ✅ FORCE JOIN callback (botão "✅ Já entrei")
    if (function_exists('force_join_handle_callback')) {
      if (force_join_handle_callback($cb)) { echo 'ok'; exit; }
    }

    $chatId    = (int)($cb['message']['chat']['id'] ?? 0);
    $chatType  = (string)($cb['message']['chat']['type'] ?? 'private');
    $messageId = (int)($cb['message']['message_id'] ?? 0);
    $fromId    = (int)($cb['from']['id'] ?? 0);
    $fromName  = (string)($cb['from']['first_name'] ?? 'usuário');
    $rawData   = (string)($cb['data'] ?? '');

    // ===================== CALLBACKS DO PAINEL ADMIN =====================
    // Callbacks sem pipe (formato simples: admin_refresh, admin_add, etc)
    if (strpos($rawData, 'admin_') === 0) {
      
      // Apenas admin pode usar
      if ($fromId !== ADMIN_ID) {
        answerCallback($cb['id'], '⛔ Apenas o administrador pode usar este painel.', true);
        echo 'ok'; exit;
      }
      
      $now = time();
      
      switch ($rawData) {
        
        // Atualizar painel
        case 'admin_refresh': {
          $users = vip_load_users();
          $ativos = 0;
          $expirados = 0;
          
          foreach ($users as $uid => $info) {
            if (($info['expires_at'] ?? 0) > $now) {
              $ativos++;
            } else {
              $expirados++;
            }
          }
          
          $total = count($users);
          
          $texto = "👑 <b>PAINEL DE ADMINISTRAÇÃO</b>\n\n";
          $texto .= "📊 <b>Estatísticas:</b>\n";
          $texto .= "✅ Ativos: <code>{$ativos}</code>\n";
          $texto .= "❌ Expirados: <code>{$expirados}</code>\n";
          $texto .= "👥 Total: <code>{$total}</code>\n\n";
          $texto .= "🎛️ <b>Escolha uma ação abaixo:</b>";
          
          $keyboard = [
            'inline_keyboard' => [
              [
                ['text' => '➕ Adicionar VIP', 'callback_data' => 'admin_add'],
                ['text' => '🔄 Renovar VIP', 'callback_data' => 'admin_renew']
              ],
              [
                ['text' => '🗑️ Remover VIP', 'callback_data' => 'admin_remove'],
                ['text' => '👥 Ver Ativos', 'callback_data' => 'admin_list_active']
              ],
              [
                ['text' => '❌ Ver Expirados', 'callback_data' => 'admin_list_expired'],
                ['text' => '🧹 Limpar Expirados', 'callback_data' => 'admin_clean']
              ],
              [
                ['text' => '🔄 Atualizar', 'callback_data' => 'admin_refresh']
              ]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          answerCallback($cb['id'], '✅ Atualizado!', false);
          
          echo 'ok'; exit;
        }
        
        // Ver usuários ativos
        case 'admin_list_active': {
          $users = vip_load_users();
          $ativos = [];
          
          foreach ($users as $uid => $info) {
            if (($info['expires_at'] ?? 0) > $now) {
              $ativos[$uid] = $info;
            }
          }
          
          if (empty($ativos)) {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⚠️ Nenhum usuário ativo no momento.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }
          
          // Ordenar por expiração
          uasort($ativos, function($a, $b) {
            return ($a['expires_at'] ?? 0) <=> ($b['expires_at'] ?? 0);
          });
          
          $texto = "✅ <b>USUÁRIOS ATIVOS (" . count($ativos) . ")</b>\n\n";
          
          $count = 0;
          foreach ($ativos as $uid => $info) {
            if ($count >= 20) {
              $texto .= "\n<i>... e mais " . (count($ativos) - 20) . " usuário(s)</i>";
              break;
            }
            
            $expiresAt = $info['expires_at'];
            $diff = $expiresAt - $now;
            $days = floor($diff / 86400);
            $hours = floor(($diff % 86400) / 3600);
            
            if ($days > 0) {
              $timeLeft = "{$days}d {$hours}h";
            } else if ($hours > 0) {
              $timeLeft = "{$hours}h";
            } else {
              $timeLeft = floor($diff / 60) . "m";
            }
            
            $texto .= "👤 <code>{$uid}</code>\n";
            $texto .= "⏰ Expira: " . date('d/m/Y H:i', $expiresAt) . "\n";
            $texto .= "⏳ Restante: <b>{$timeLeft}</b>\n\n";
            
            $count++;
          }
          
          $keyboard = [
            'inline_keyboard' => [
              [['text' => '🔙 Voltar', 'callback_data' => 'admin_refresh']]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
          echo 'ok'; exit;
        }
        
        // Ver usuários expirados
        case 'admin_list_expired': {
          $users = vip_load_users();
          $expirados = [];
          
          foreach ($users as $uid => $info) {
            if (($info['expires_at'] ?? 0) <= $now) {
              $expirados[$uid] = $info;
            }
          }
          
          if (empty($expirados)) {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '✅ Nenhum usuário expirado!',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }
          
          // Ordenar por expiração (mais recente primeiro)
          uasort($expirados, function($a, $b) {
            return ($b['expires_at'] ?? 0) <=> ($a['expires_at'] ?? 0);
          });
          
          $texto = "❌ <b>USUÁRIOS EXPIRADOS (" . count($expirados) . ")</b>\n\n";
          
          $count = 0;
          foreach ($expirados as $uid => $info) {
            if ($count >= 20) {
              $texto .= "\n<i>... e mais " . (count($expirados) - 20) . " usuário(s)</i>";
              break;
            }
            
            $expiresAt = $info['expires_at'];
            $daysAgo = floor(($now - $expiresAt) / 86400);
            
            $texto .= "👤 <code>{$uid}</code>\n";
            $texto .= "⏰ Expirou: " . date('d/m/Y H:i', $expiresAt) . "\n";
            $texto .= "📅 Há: <b>{$daysAgo} dia(s)</b>\n\n";
            
            $count++;
          }
          
          $keyboard = [
            'inline_keyboard' => [
              [['text' => '🔙 Voltar', 'callback_data' => 'admin_refresh']]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
          echo 'ok'; exit;
        }
        
        // Limpar expirados
        case 'admin_clean': {
          $users = vip_load_users();
          $removed = 0;
          
          foreach ($users as $uid => $info) {
            if (($info['expires_at'] ?? 0) <= $now) {
              unset($users[$uid]);
              $removed++;
            }
          }
          
          if ($removed > 0) {
            vip_save_users($users);
          }
          
          tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => "✅ {$removed} usuário(s) expirado(s) removido(s)!",
            'show_alert' => true
          ]);
          
          // Atualizar painel
          $ativos = 0;
          foreach ($users as $uid => $info) {
            if (($info['expires_at'] ?? 0) > $now) {
              $ativos++;
            }
          }
          
          $total = count($users);
          
          $texto = "👑 <b>PAINEL DE ADMINISTRAÇÃO</b>\n\n";
          $texto .= "📊 <b>Estatísticas:</b>\n";
          $texto .= "✅ Ativos: <code>{$ativos}</code>\n";
          $texto .= "❌ Expirados: <code>0</code>\n";
          $texto .= "👥 Total: <code>{$total}</code>\n\n";
          $texto .= "🎛️ <b>Escolha uma ação abaixo:</b>";
          
          $keyboard = [
            'inline_keyboard' => [
              [
                ['text' => '➕ Adicionar VIP', 'callback_data' => 'admin_add'],
                ['text' => '🔄 Renovar VIP', 'callback_data' => 'admin_renew']
              ],
              [
                ['text' => '🗑️ Remover VIP', 'callback_data' => 'admin_remove'],
                ['text' => '👥 Ver Ativos', 'callback_data' => 'admin_list_active']
              ],
              [
                ['text' => '❌ Ver Expirados', 'callback_data' => 'admin_list_expired'],
                ['text' => '🧹 Limpar Expirados', 'callback_data' => 'admin_clean']
              ],
              [
                ['text' => '🔄 Atualizar', 'callback_data' => 'admin_refresh']
              ]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          echo 'ok'; exit;
        }
        
        // Instruções para adicionar VIP
        case 'admin_add': {
          $texto = "➕ <b>ADICIONAR VIP</b>\n\n";
          $texto .= "<b>Como usar:</b>\n";
          $texto .= "<code>/addvip ID TEMPO</code>\n\n";
          $texto .= "<b>Exemplos:</b>\n";
          $texto .= "• <code>/addvip 123456789 7d</code> (7 dias)\n";
          $texto .= "• <code>/addvip 123456789 1m</code> (1 mês = 30 dias)\n";
          $texto .= "• <code>/addvip 123456789 6m</code> (6 meses)\n\n";
          $texto .= "<b>Formatos de tempo:</b>\n";
          $texto .= "• <code>Xd</code> = X dias\n";
          $texto .= "• <code>Xh</code> = X horas\n";
          $texto .= "• <code>Xm</code> = X minutos\n\n";
          $texto .= "<i>💡 Se o usuário já tem VIP ativo, o tempo será adicionado!</i>";
          
          $keyboard = [
            'inline_keyboard' => [
              [['text' => '🔙 Voltar', 'callback_data' => 'admin_refresh']]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
          echo 'ok'; exit;
        }
        
        // Instruções para renovar VIP
        case 'admin_renew': {
          $texto = "🔄 <b>RENOVAR VIP</b>\n\n";
          $texto .= "<b>Como usar:</b>\n";
          $texto .= "<code>/addvip ID TEMPO</code>\n\n";
          $texto .= "<b>Exemplos:</b>\n";
          $texto .= "• <code>/addvip 123456789 30d</code> (adiciona 30 dias)\n";
          $texto .= "• <code>/addvip 123456789 1m</code> (adiciona 30 dias)\n\n";
          $texto .= "<i>💡 O comando /addvip funciona tanto para adicionar quanto para renovar!</i>\n\n";
          $texto .= "Se o usuário:\n";
          $texto .= "• <b>Tem VIP ativo:</b> tempo é adicionado\n";
          $texto .= "• <b>Está expirado:</b> novo VIP a partir de agora";
          
          $keyboard = [
            'inline_keyboard' => [
              [['text' => '🔙 Voltar', 'callback_data' => 'admin_refresh']]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
          echo 'ok'; exit;
        }
        
        // Instruções para remover VIP
        case 'admin_remove': {
          $texto = "🗑️ <b>REMOVER VIP</b>\n\n";
          $texto .= "<b>Como usar:</b>\n";
          $texto .= "<code>/rm ID</code>\n\n";
          $texto .= "<b>Exemplo:</b>\n";
          $texto .= "<code>/rm 123456789</code>\n\n";
          $texto .= "⚠️ <b>Atenção:</b>\n";
          $texto .= "Esta ação é <b>irreversível</b>!\n";
          $texto .= "O usuário perderá acesso VIP imediatamente.";
          
          $keyboard = [
            'inline_keyboard' => [
              [['text' => '🔙 Voltar', 'callback_data' => 'admin_refresh']]
            ]
          ];
          
          tg('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
          ]);
          
          tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
          echo 'ok'; exit;
        }
        
        default:
          tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '⚠️ Ação de admin desconhecida.',
            'show_alert' => true
          ]);
          echo 'ok'; exit;
      }
    }
    // ===================== FIM CALLBACKS PAINEL ADMIN =====================

    if (strpos($rawData, '|') !== false) {
      $parts   = explode('|', $rawData);
      $action  = $parts[0] ?? '';
      $ownerId = isset($parts[1]) ? (int)$parts[1] : 0;

      logx("🔍 Callback com pipe: action={$action}, ownerId={$ownerId}, fromId={$fromId}");

      // ✅ Verificação de owner APENAS para ações sensíveis
      // BACK_MAIN e MENU_CONSULTAS podem ser usados por qualquer um
      $allowedForEveryone = ['BACK_MAIN', 'MENU_CONSULTAS', 'GER_GRUPOS_BACK', 
                             'CONSULTA_CPF', 'CONSULTA_CNPJ', 'CONSULTA_CEP', 
                             'CONSULTA_NOME', 'CONSULTA_TELEFONE', 'CONSULTA_PLACA',
                             'CONSULTA_IP', 'CONSULTA_BIN'];
      
      if (!in_array($action, $allowedForEveryone)) {
        if ($ownerId > 0 && $fromId !== $ownerId) {
          logx("⚠️ Bloqueado: fromId {$fromId} != ownerId {$ownerId} para action {$action}");
          tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'] ?? '',
            'text'              => '⚠️ Apenas o usuário que realizou este comando pode usar este botão.',
            'show_alert'        => true
          ]);
          echo 'ok'; exit;
        }
      } else {
        logx("✅ Ação liberada para todos: {$action}");
      }

      switch ($action) {

        case 'APAGAR': {
          // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
          answerCallback($cb['id'], '🗑️ Apagando...', false);
          
          $origId = isset($parts[2]) ? (int)$parts[2] : 0;

          deleteMessageSafe($chatId, $messageId);

          if ($origId > 0) {
            deleteMessageSafe($chatId, $origId);
          }

          echo 'ok';
          exit;
        }

        // ✅ ABRIR TELEGRAPH DIRETO (sem mandar mensagem)
        case 'TGVIEW': {
          $tipo = (string)($parts[2] ?? '');
          $key  = (string)($parts[3] ?? '');

          if (!preg_match('/^[a-f0-9]{24}$/i', $key)) {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Link inválido.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          $ticketFile = TG_TICKET_DIR . "/{$key}.json";

          if (!is_file($ticketFile)) {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Consulta expirada. Faça novamente.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          $info = json_decode((string)@file_get_contents($ticketFile), true);
          if (!is_array($info)) {
            @unlink($ticketFile);
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Consulta inválida/expirada.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          $ts  = (int)($info['ts'] ?? 0);
          $ttl = (int)($info['ttl'] ?? 600);
          $own = (int)($info['owner_id'] ?? 0);

          if ($ts <= 0 || (time() - $ts) > $ttl) {
            @unlink($ticketFile);
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Consulta expirada. Faça novamente.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          // trava por usuário (extra)
          if ($own > 0 && $fromId !== $own) {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Você não solicitou essa consulta.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          $url = (string)($info['url'] ?? '');

          if ($url === '' || stripos($url, 'https://telegra.ph/') !== 0) {
            @unlink($ticketFile);
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⛔ Link inválido.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          // uso único
          @unlink($ticketFile);

          // abre direto
          tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'url' => $url
          ]);

          echo 'ok'; exit;
        }

case 'VIP_BUY': {
    // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
    answerCallback($cb['id'], '', false);
    
    // Esperado: VIP_BUY|ownerId|tipo
    $ownerId = isset($parts[1]) ? (int)$parts[1] : 0;
    $tipo    = isset($parts[2]) ? (string)$parts[2] : '';

    // 🔐 Segurança
    if ($ownerId > 0 && $fromId !== $ownerId) {
        editMessageTextSafe([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '⛔ <b>Não autorizado.</b>\n\nApenas quem solicitou pode usar este botão.',
            'parse_mode' => 'HTML'
        ]);
        echo 'ok'; exit;
    }

    // 📦 Planos
    $planos = [
        'vip_7' => [
            'dias'  => 7,
            'valor' => 10,
            'label' => '1 Semana'
        ],
        'vip_14' => [
            'dias'  => 14,
            'valor' => 15,
            'label' => '2 Semanas'
        ],
        'vip_30' => [
            'dias'  => 30,
            'valor' => 25,
            'label' => '1 Mês'
        ],
        'vip_180' => [
            'dias'  => 180,
            'valor' => 120,
            'label' => '6 Meses'
        ],
    ];

    if (!isset($planos[$tipo])) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '❌ Plano inválido.',
            'show_alert' => true
        ]);
        echo 'ok'; exit;
    }

    $plano = $planos[$tipo];

    tg('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => '⏳ Gerando PIX...',
        'show_alert' => false
    ]);

    // 🔗 Criação do PIX
    $url = "https://meuvpsbr.shop/misticpay/criar_pix.php"
         . "?user_id={$fromId}"
         . "&tipo={$tipo}";

    $http = http_get($url, 20);

    if (!$http['ok'] || (int)$http['code'] !== 200) {
        editMessageTextSafe([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => "❌ <b>Erro ao gerar pagamento.</b>\nTente novamente.",
            'parse_mode' => 'HTML'
        ]);
        echo 'ok'; exit;
    }

    $pix = json_decode($http['body'], true);

    if (!is_array($pix) || empty($pix['sucesso'])) {
        editMessageTextSafe([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => "❌ <b>Erro ao gerar PIX.</b>",
            'parse_mode' => 'HTML'
        ]);
        echo 'ok'; exit;
    }

    // Nota: criar_pix.php já salva no payments.json com expira_em
    // Não precisa salvar novamente aqui

    // 🖼️ Exibe QR Code com informações completas
    $expiraFormatado = $pix['expira_em_formatado'] ?? date('d/m/Y H:i', time() + 86400);
    
    tg('editMessageMedia', [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'media' => [
            'type'  => 'photo',
            'media' => $pix['qr_code'],
            'caption' =>
                "💳 <b>PAGAMENTO VIA PIX</b>\n\n"
              . "📦 <b>Plano:</b> {$plano['label']}\n"
              . "📅 <b>Duração:</b> {$plano['dias']} dias\n"
              . "💰 <b>Valor:</b> R$ " . number_format($plano['valor'], 2, ',', '.') . "\n"
              . "⏰ <b>Expira em:</b> {$expiraFormatado}\n\n"
              . "📌 <b>PIX Copia e Cola:</b>\n\n"
              . "<code>{$pix['copia_cola']}</code>\n\n"
              . "✅ Após o pagamento, seu acesso será <b>liberado automaticamente</b>.\n\n"
              . "⚠️ <i>Este PIX expira em 24 horas!</i>",
            'parse_mode' => 'HTML'
        ],
        'reply_markup' => [
            'inline_keyboard' => [
                [
                    ['text' => 'Apagar', 'callback_data' => "APAGAR|{$fromId}|0"]
                ]
            ]
        ]
    ]);

    echo 'ok';
    exit;
}

case 'VIP_CLEAN': {

    // trava por dono do botão (ownerId vem do callback_data)
    if ($fromId !== $ownerId) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '⛔ Não autorizado.',
            'show_alert' => true
        ]);
        echo 'ok'; exit;
    }

    $removed = vip_clean_expired();
    [$ativos, $vencidos] = vip_count_stats();

    editMessageTextSafe([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' =>
            "🧹 <b>Limpeza concluída</b>\n\n"
          . "❌ Removidos: <b>{$removed}</b>\n"
          . "👑 Ativos agora: <b>{$ativos}</b>\n"
          . "⏰ Vencidos agora: <b>{$vencidos}</b>\n\n"
          . "🗓️ " . date('d/m/Y H:i'),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ]);

    tg('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => 'OK',
        'show_alert' => false
    ]);

    echo 'ok'; exit;
}

// ===================== VIP RENOVAR (USUÁRIO) =====================
case 'VIP_renovar': {
    // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
    answerCallback($cb['id'], '', false);
    
    // Verifica se é o dono do botão
    if ($fromId !== $ownerId) {
        editMessageTextSafe([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '⛔ <b>Não autorizado.</b>\n\nApenas o dono do comando pode usar este botão.',
            'parse_mode' => 'HTML'
        ]);
        echo 'ok'; exit;
    }
    
    // Mostra planos disponíveis
    $texto = "🔄 <b>RENOVAR PLANO VIP</b>\n\n";
    $texto .= "Escolha o plano que deseja renovar:\n\n";
    $texto .= "• <b>1 semana</b> — R$ 10,00\n";
    $texto .= "• <b>2 semanas</b> — R$ 15,00\n";
    $texto .= "• <b>1 mês</b> — R$ 25,00\n";
    $texto .= "• <b>6 meses</b> — R$ 120,00\n\n";
    $texto .= "💳 Após a confirmação do pagamento, o tempo será <b>adicionado</b> ao seu plano atual.";
    
    editMessageTextSafe([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $texto,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '1 Semana — R$ 10', 'callback_data' => "VIP_BUY|{$fromId}|vip_7"]
                ],
                [
                    ['text' => '2 Semanas — R$ 15', 'callback_data' => "VIP_BUY|{$fromId}|vip_14"],
                    ['text' => '1 Mês — R$ 25', 'callback_data' => "VIP_BUY|{$fromId}|vip_30"]
                ],
                [
                    ['text' => '6 Meses — R$ 120', 'callback_data' => "VIP_BUY|{$fromId}|vip_180"]
                ],
                [
                    ['text' => '↩️ Voltar', 'callback_data' => "VIP_MEUPLANO|{$fromId}"]
                ]
            ]
        ])
    ]);
    
    echo 'ok'; exit;
}

// ===================== VIP CANCELAR (USUÁRIO) =====================
case 'VIP_cancelar': {
    // Verifica se é o dono do botão
    if ($fromId !== $ownerId) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '⛔ Não autorizado.',
            'show_alert' => true
        ]);
        echo 'ok'; exit;
    }
    
    // Mostra confirmação
    $texto = "⚠️ <b>CANCELAR PLANO VIP</b>\n\n";
    $texto .= "Tem certeza que deseja cancelar seu plano?\n\n";
    $texto .= "❌ Seu acesso VIP será <b>removido imediatamente</b>.\n";
    $texto .= "⚠️ Esta ação é <b>irreversível</b>!\n\n";
    $texto .= "Se confirmar, você precisará adquirir um novo plano para voltar a ter acesso.";
    
    editMessageTextSafe([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $texto,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '❌ Sim, cancelar meu plano', 'callback_data' => "VIP_cancelar_confirma|{$fromId}|0"]
                ],
                [
                    ['text' => '⬅️ Não, voltar', 'callback_data' => "VIP_cancelar_voltar|{$fromId}|0"]
                ]
            ]
        ])
    ]);
    
    tg('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => '⚠️ Atenção: Ação irreversível!',
        'show_alert' => true
    ]);
    
    echo 'ok'; exit;
}

// ===================== VIP CANCELAR CONFIRMAÇÃO =====================
case 'VIP_cancelar_confirma': {
    // Verifica se é o dono do botão
    if ($fromId !== $ownerId) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '⛔ Não autorizado.',
            'show_alert' => true
        ]);
        echo 'ok'; exit;
    }
    
    // Remove VIP do usuário
    $users = vip_load_users();
    
    if (!isset($users[$fromId])) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => '❌ Você não possui plano ativo.',
            'show_alert' => true
        ]);
        echo 'ok'; exit;
    }
    
    unset($users[$fromId]);
    vip_save_users($users);
    
    editMessageTextSafe([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => "✅ <b>Plano cancelado com sucesso!</b>\n\n"
                . "Seu acesso VIP foi removido.\n\n"
                . "Para reativar, use /vip a qualquer momento.",
        'parse_mode' => 'HTML'
    ]);
    
    tg('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => '✅ Plano cancelado!',
        'show_alert' => false
    ]);
    
    echo 'ok'; exit;
}

// ===================== VIP CANCELAR VOLTAR =====================
case 'VIP_cancelar_voltar': {
    // Verifica se é o dono do botão
    if ($fromId !== $ownerId) {
        answerCallback($cb['id'], '⛔ Não autorizado.', true);
        echo 'ok'; exit;
    }
    
    // Carrega dados do usuário
    $users = vip_load_users();
    $now = time();
    $vipData = $users[$fromId] ?? null;
    
    if (!$vipData) {
        editMessageTextSafe([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "❌ <b>Você não possui plano ativo.</b>",
            'parse_mode' => 'HTML'
        ]);
        echo 'ok'; exit;
    }
    
    $expiresAt = (int)($vipData['expires_at'] ?? 0);
    $diff = $expiresAt - $now;
    $diasRestantes = floor($diff / 86400);
    $horasRestantes = floor(($diff % 86400) / 3600);
    
    if ($diasRestantes > 0) {
        $tempoRestante = "{$diasRestantes} dia" . ($diasRestantes > 1 ? 's' : '');
        if ($horasRestantes > 0) {
            $tempoRestante .= " e {$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
        }
    } else {
        $tempoRestante = "{$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
    }
    
    $expiraEm = date('d/m/Y', $expiresAt);
    $expiraHora = date('H:i', $expiresAt);
    
    $texto = "💎 <b>MEU PLANO VIP</b>\n\n";
    $texto .= "✅ <b>Status:</b> Ativo\n\n";
    $texto .= "📅 <b>Expira em:</b> {$expiraEm} às {$expiraHora}\n";
    $texto .= "⏳ <b>Tempo restante:</b> {$tempoRestante}\n\n";
    
    if ($diasRestantes < 3) {
        $texto .= "⚠️ <i>Seu plano está próximo do vencimento!</i>\n\n";
    }
    
    $texto .= "🚀 Aproveite seu acesso completo às consultas!";
    
    editMessageTextSafe([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $texto,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Renovar Plano', 'callback_data' => "VIP_renovar|{$fromId}|0"]
                ],
                [
                    ['text' => '🗑️ Cancelar Plano', 'callback_data' => "VIP_cancelar|{$fromId}|0"]
                ]
            ]
        ])
    ]);
    
    tg('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => '✅ Cancelamento cancelado!',
        'show_alert' => false
    ]);
    
    echo 'ok'; exit;
}

        // ===================== VIP MEUPLANO (BOTÃO DO MENU) =====================
        case 'VIP_MEUPLANO': {
          logx("✅ Entrando em VIP_MEUPLANO - fromId: {$fromId}, ownerId: {$ownerId}");
          
          // Verifica se é o dono do botão
          if ($fromId !== $ownerId) {
            logx("⚠️ Usuário não autorizado: {$fromId} != {$ownerId}");
            editMessageTextSafe([
              'chat_id' => $chatId,
              'message_id' => $messageId,
              'text' => '⛔ <b>Não autorizado.</b>\n\nApenas o dono do comando pode usar este botão.',
              'parse_mode' => 'HTML'
            ]);
            echo 'ok'; exit;
          }

          // Carrega dados do usuário
          $users = vip_load_users();
          $now = time();
          
          logx("📊 Verificando VIP do usuário {$fromId}");
          
          // Verifica se o usuário tem VIP ativo
          if (!isset($users[$fromId]) || ($users[$fromId]['expires_at'] ?? 0) <= $now) {
            logx("❌ Usuário {$fromId} NÃO tem VIP ativo");
            
            // ❌ NÃO TEM VIP ou EXPIROU - mostra planos para contratar
            $texto = "💎 <b>PLANOS VIP DISPONÍVEIS</b>\n\n";
            $texto .= "Para utilizar o bot no privado, você precisa de um plano ativo.\n\n";
            $texto .= "📦 <b>Escolha seu plano:</b>\n\n";
            $texto .= "• <b>1 Semana</b> — R$ 10,00\n";
            $texto .= "• <b>2 Semanas</b> — R$ 15,00\n";
            $texto .= "• <b>1 Mês</b> — R$ 25,00\n";
            $texto .= "• <b>6 Meses</b> — R$ 120,00\n\n";
            $texto .= "✅ Pagamento via PIX com confirmação automática!";
            
            editMessageTextSafe([
              'chat_id' => $chatId,
              'message_id' => $messageId,
              'text' => $texto,
              'parse_mode' => 'HTML',
              'reply_markup' => json_encode([
                'inline_keyboard' => [
                  [
                    ['text' => '1 Semana — R$ 10', 'callback_data' => "VIP_BUY|{$fromId}|vip_7"]
                  ],
                  [
                    ['text' => '2 Semanas — R$ 15', 'callback_data' => "VIP_BUY|{$fromId}|vip_14"],
                    ['text' => '1 Mês — R$ 25', 'callback_data' => "VIP_BUY|{$fromId}|vip_30"]
                  ],
                  [
                    ['text' => '6 Meses — R$ 120', 'callback_data' => "VIP_BUY|{$fromId}|vip_180"]
                  ],
                  [
                    ['text' => '↩️ Voltar', 'callback_data' => "BACK_MAIN|{$fromId}"]
                  ]
                ]
              ])
            ]);
          } else {
            // ✅ TEM VIP ATIVO - mostra informações e opções
            $vipData = $users[$fromId];
            $expiresAt = (int)($vipData['expires_at'] ?? 0);
            
            // Calcula tempo restante
            $diff = $expiresAt - $now;
            $diasRestantes = floor($diff / 86400);
            $horasRestantes = floor(($diff % 86400) / 3600);
            $minutosRestantes = floor(($diff % 3600) / 60);
            
            if ($diasRestantes > 0) {
              $tempoRestante = "{$diasRestantes} dia" . ($diasRestantes > 1 ? 's' : '');
              if ($horasRestantes > 0) {
                $tempoRestante .= " e {$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
              }
            } elseif ($horasRestantes > 0) {
              $tempoRestante = "{$horasRestantes} hora" . ($horasRestantes > 1 ? 's' : '');
              if ($minutosRestantes > 0) {
                $tempoRestante .= " e {$minutosRestantes} minuto" . ($minutosRestantes > 1 ? 's' : '');
              }
            } else {
              $tempoRestante = "{$minutosRestantes} minuto" . ($minutosRestantes > 1 ? 's' : '');
            }
            
            // Formata data de expiração
            $expiraEm = date('d/m/Y', $expiresAt);
            $expiraHora = date('H:i', $expiresAt);
            
            // Monta mensagem
            $texto = "💎 <b>MEU PLANO VIP</b>\n\n";
            $texto .= "✅ <b>Status:</b> Ativo\n\n";
            $texto .= "📅 <b>Expira em:</b> {$expiraEm} às {$expiraHora}\n";
            $texto .= "⏳ <b>Tempo restante:</b> {$tempoRestante}\n\n";
            
            // Alerta se falta menos de 3 dias
            if ($diasRestantes < 3) {
              $texto .= "⚠️ <i>Seu plano está próximo do vencimento!</i>\n\n";
            }
            
            $texto .= "🚀 Aproveite seu acesso completo às consultas!";
            
            editMessageTextSafe([
              'chat_id' => $chatId,
              'message_id' => $messageId,
              'text' => $texto,
              'parse_mode' => 'HTML',
              'reply_markup' => json_encode([
                'inline_keyboard' => [
                  [
                    ['text' => '🔄 Renovar Plano', 'callback_data' => "VIP_renovar|{$fromId}|0"]
                  ],
                  [
                    ['text' => '↩️ Voltar', 'callback_data' => "BACK_MAIN|{$fromId}"]
                  ]
                ]
              ])
            ]);
          }

          echo 'ok'; exit;
        }
        // ===================== FIM VIP MEUPLANO =====================

        case 'MENU_CONSULTAS': {
          // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
          answerCallback($cb['id'], '', false);
          
          sendConsultasMenu($chatId, $messageId, $fromId, $fromName, $ownerId ?: $fromId);
          echo 'ok'; exit;
        }

        case 'CONSULTA_CPF':      answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'cpf',$ownerId?:$fromId);      echo 'ok'; exit;
        case 'CONSULTA_CNPJ':     answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'cnpj',$ownerId?:$fromId);     echo 'ok'; exit;
        case 'CONSULTA_CEP':      answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'cep',$ownerId?:$fromId);      echo 'ok'; exit;
        case 'CONSULTA_NOME':     answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'nome',$ownerId?:$fromId);     echo 'ok'; exit;
        case 'CONSULTA_TELEFONE': answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'telefone',$ownerId?:$fromId); echo 'ok'; exit;
        case 'CONSULTA_PLACA':    answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'placa',$ownerId?:$fromId);    echo 'ok'; exit;
        case 'CONSULTA_IP':       answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'ip',$ownerId?:$fromId);       echo 'ok'; exit;
        case 'CONSULTA_BIN':      answerCallback($cb['id']); sendHowTo($chatId,$messageId,$fromId,$fromName,'bin',$ownerId?:$fromId);      echo 'ok'; exit;

        case 'GER_GRUPOS': {
          // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
          answerCallback($cb['id'], '', false);
          
          if ($chatType !== 'private') {
            editMessageTextSafe([
              'chat_id' => $chatId,
              'message_id' => $messageId,
              'text' => '⚠️ <b>Comando disponível apenas no privado.</b>\n\nAbra uma conversa privada com o bot.',
              'parse_mode' => 'HTML'
            ]);
            echo 'ok'; exit;
          }

          $txt  = "🛠 <b>Gerência de Grupos</b>\n\n";
          $txt .= "<b>/grupos</b> - abrir menu de gerenciamento\n";
          $txt .= "<b>/id</b> - informações do usuário\n";
          $txt .= "<b>/mute</b> - silenciar (reply)\n";
          $txt .= "<b>/ban</b> - banir (reply)\n\n";
          $txt .= "📌 <i>Dica:</i> responda a mensagem do usuário e envie <code>/mute</code> ou <code>/ban</code>.";

          editMessageTextSafe([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $txt,
            'parse_mode'   => 'HTML',
            'reply_markup' => keyboard_ger_grupos($ownerId ?: $fromId),
            'disable_web_page_preview' => true,
          ]);

          echo 'ok'; exit;
        }

        case 'GER_GRUPOS_OPEN': {
          if ($chatType !== 'private') {
            tg('answerCallbackQuery', [
              'callback_query_id' => $cb['id'],
              'text' => '⚠️ Use no privado com o bot.',
              'show_alert' => true
            ]);
            echo 'ok'; exit;
          }

          if (function_exists('ga_groups_menu_payload')) {
            $payload = ga_groups_menu_payload($fromId);
            editMessageTextSafe([
              'chat_id' => $chatId,
              'message_id' => $messageId,
              'text' => $payload['text'],
              'parse_mode' => 'HTML',
              'reply_markup' => $payload['reply_markup'],
              'disable_web_page_preview' => true,
            ]);
          }

          tg('answerCallbackQuery', ['callback_query_id'=>$cb['id']]);
          echo 'ok'; exit;
        }

        case 'CPF_BASE': {
          $base = (string)($parts[2] ?? '');
          $cpfRaw  = (string)($parts[3] ?? '');
          $origId = isset($parts[4]) ? (int)$parts[4] : 0;

          $GLOBALS['chatId'] = $chatId;
          $GLOBALS['msgId']  = $origId ?: $messageId;

          tg('answerCallbackQuery', ['callback_query_id'=>$cb['id']??'','text'=>'⏳ Consultando...','show_alert'=>false]);

          // Usa função global de normalização
          $validacao = normalizarCPF($cpfRaw);
          
          if (!$validacao['valido'] || $base === '') {
            sendResultFinal($chatId, "⚠️ CPF inválido ou base não selecionada.", $origId ?: $messageId, $fromId, $origId ?: $messageId, $chatType);
            echo 'ok'; exit;
          }
          
          $cpf = $validacao['cpf'];

          deleteMessageSafe($chatId, $messageId);

          // define dados globais do usuário antes da consulta (para bloqueio individual funcionar)
          $GLOBALS['user_id']  = $fromId;
          $GLOBALS['chat_id']  = $chatId;
          $GLOBALS['username'] = $cb['from']['username'] ?? '';

          $loadingId = sendLoading($chatId, ($origId ?: 0));
          $resp = runConsulta($base, $cpf);

          if ($resp === '') {
            if ($loadingId > 0) deleteMessageSafe($chatId, $loadingId);
            echo 'ok';
            exit;
          }

          if (!is_error_response($resp)) {
            $resp = append_signature($resp, $fromId, $fromName);
          }

          finishLoading($chatId, $loadingId, $resp, $fromId, ($origId ?: 0), $chatType);
          echo 'ok';
          exit;
        }

        case 'GER_GRUPOS_BACK':
        case 'BACK_MAIN': {
          // ✅ RESPONDE O CALLBACK IMEDIATAMENTE
          answerCallback($cb['id'], '', false);
          
          $text = "
<b>👋 Olá, " . mention_html($fromId, $fromName) . " !</b>

<b>Bem-vindo ao Melhor Bot de Consultas</b> 🤖
Realize consultas completas com rapidez e total segurança.

<b>📋 Escolha uma opção para começar:</b>
";
          $kb = ($chatType === 'private')
            ? keyboard_main_private($ownerId ?: $fromId)
            : keyboard_main_group($ownerId ?: $fromId);

          editMessageTextSafe([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => trim($text),
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb,
            'disable_web_page_preview' => true,
          ]);

          echo 'ok'; exit;
        }

        default:
          answerCallback($cb['id'] ?? '', '⚠️ Ação inválida ou expirada. Use /menu para gerar novos botões.', false);
          echo 'ok'; exit;
      }
    }

    // ✅ Se chegou aqui, o callback não foi tratado - responde de qualquer forma
    answerCallback($cb['id'] ?? '', '⚠️ Ação não reconhecida.', false);
    echo 'ok'; exit;
  }

} catch (Throwable $e) {
  logx('Exception: '.$e->getMessage());
}

echo 'ok';