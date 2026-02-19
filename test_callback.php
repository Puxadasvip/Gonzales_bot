<?php
/**
 * VERIFICADOR AUTOMÁTICO DE PAGAMENTOS
 * Sistema de Fallback - Verifica pagamentos pendentes a cada 5 minutos
 * Garante que nenhum pagamento fique sem liberação
 * 
 * Adicionar ao CRON:
 * */5 * * * * php /path/to/verificador_pagamentos.php
 */

declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

// =====================
// Configuração
// =====================
$PAYMENTS_FILE = __DIR__ . '/vip/payments.json';
$LOG_FILE = __DIR__ . '/verificador_pagamentos.log';

$config = require __DIR__ . '/misticpay/config.php';

// =====================
// Funções
// =====================
function vp_log(string $msg): void {
    global $LOG_FILE;
    file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

function vp_check_pix(string $transactionId): ?array {
    global $config;
    
    $payload = ['transactionId' => $transactionId];
    
    $ch = curl_init($config['API_BASE'] . '/api/transactions/check');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'ci: ' . $config['CLIENT_ID'],
            'cs: ' . $config['CLIENT_SECRET'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);
    
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($err || $code !== 200) {
        vp_log("Erro ao verificar PIX {$transactionId}: {$err}");
        return null;
    }
    
    $data = json_decode($res, true);
    if (!is_array($data)) {
        vp_log("Resposta inválida da API para {$transactionId}");
        return null;
    }
    
    return $data;
}

function vp_activate_vip(int $userId, int $dias): bool {
    require_once __DIR__ . '/bot.php';
    
    try {
        vip_add_days($userId, $dias);
        return true;
    } catch (Throwable $e) {
        vp_log("ERRO ao ativar VIP para {$userId}: " . $e->getMessage());
        return false;
    }
}

function vp_send_message(int $userId, string $text): void {
    require_once __DIR__ . '/bot.php';
    
    try {
        tg('sendMessage', [
            'chat_id' => $userId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]);
    } catch (Throwable $e) {
        vp_log("Erro ao enviar mensagem para {$userId}: " . $e->getMessage());
    }
}

// =====================
// Verifica Pagamentos
// =====================
vp_log('=== INICIANDO VERIFICAÇÃO ===');

if (!file_exists($PAYMENTS_FILE)) {
    vp_log('ERROR: payments.json não existe');
    exit(1);
}

// Abre arquivo com lock
$fp = @fopen($PAYMENTS_FILE, 'c+');
if (!$fp) {
    vp_log('ERROR: Não foi possível abrir payments.json');
    exit(1);
}

@flock($fp, LOCK_EX);
$content = stream_get_contents($fp);
$payments = json_decode($content ?: '{}', true);

if (!is_array($payments) || empty($payments)) {
    vp_log('Nenhum pagamento pendente');
    @flock($fp, LOCK_UN);
    @fclose($fp);
    exit(0);
}

vp_log('Pagamentos pendentes: ' . count($payments));

$now = time();
$activated = 0;
$removed = 0;
$errors = 0;

foreach ($payments as $transactionId => $payment) {
    $userId = (int)($payment['user_id'] ?? 0);
    $dias = (int)($payment['plano_dias'] ?? 0);
    $criadoEm = (int)($payment['created_at'] ?? 0);
    $expiraEm = (int)($payment['expira_em'] ?? 0);
    
    // Validação básica
    if ($userId <= 0 || $dias <= 0) {
        vp_log("REMOVENDO pagamento inválido: {$transactionId}");
        unset($payments[$transactionId]);
        $removed++;
        continue;
    }
    
    // Remove se expirou (após 24h)
    if ($expiraEm > 0 && $expiraEm < $now) {
        vp_log("REMOVENDO pagamento expirado: {$transactionId} (user: {$userId})");
        unset($payments[$transactionId]);
        $removed++;
        continue;
    }
    
    // Verifica status na API (apenas se tem mais de 2 minutos)
    $idade = $now - $criadoEm;
    if ($idade < 120) {
        vp_log("SKIP: Pagamento muito recente ({$idade}s): {$transactionId}");
        continue;
    }
    
    vp_log("Verificando PIX {$transactionId} (user: {$userId})...");
    
    $apiResult = vp_check_pix($transactionId);
    if (!$apiResult) {
        $errors++;
        continue;
    }
    
    $status = strtoupper($apiResult['transaction']['transactionState'] ?? '');
    vp_log("Status do PIX {$transactionId}: {$status}");
    
    // Se pagamento aprovado, ativa VIP
    if ($status === 'COMPLETO') {
        vp_log("PAGAMENTO APROVADO! Ativando VIP para user {$userId}...");
        
        if (vp_activate_vip($userId, $dias)) {
            vp_log("SUCCESS: VIP ativado para {$userId}");
            
            // Carrega dados atualizados do VIP
            require_once __DIR__ . '/bot.php';
            $users = vip_load_users();
            $expTs = (int)($users[$userId]['expires_at'] ?? 0);
            $exp = $expTs > 0 ? date('d/m/Y H:i', $expTs) : '—';
            
            $planoNome = match($dias) {
                7 => '1 Semana',
                14 => '2 Semanas',
                30 => '1 Mês',
                180 => '6 Meses',
                default => "{$dias} dias"
            };
            
            // Envia mensagem
            $msgText = "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n"
                     . "🎉 Sua conta VIP foi ativada com sucesso!\n\n"
                     . "📦 <b>Plano:</b> {$planoNome}\n"
                     . "📅 <b>Dias:</b> {$dias}\n"
                     . "⏳ <b>Válido até:</b> {$exp}\n\n"
                     . "🚀 Agora você tem acesso completo às consultas no privado!\n\n"
                     . "💬 Use /menu para começar.";
            
            vp_send_message($userId, $msgText);
            
            // Remove do arquivo
            unset($payments[$transactionId]);
            $activated++;
            
        } else {
            vp_log("ERROR: Falha ao ativar VIP para {$userId}");
            $errors++;
        }
    }
    // Se cancelado/expirado, remove
    elseif (in_array($status, ['CANCELADO', 'EXPIRADO', 'FAILED'])) {
        vp_log("REMOVENDO pagamento {$status}: {$transactionId}");
        unset($payments[$transactionId]);
        $removed++;
    }
}

// Salva alterações
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($payments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
@flock($fp, LOCK_UN);
@fclose($fp);

// Log final
vp_log("=== VERIFICAÇÃO CONCLUÍDA ===");
vp_log("Ativados: {$activated}");
vp_log("Removidos: {$removed}");
vp_log("Erros: {$errors}");
vp_log("Restantes: " . count($payments));

if (php_sapi_name() === 'cli') {
    echo "Verificação concluída!\n";
    echo "- Ativados: {$activated}\n";
    echo "- Removidos: {$removed}\n";
    echo "- Erros: {$errors}\n";
    echo "- Restantes: " . count($payments) . "\n";
}

exit(0);
