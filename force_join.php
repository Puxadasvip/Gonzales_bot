<?php
/**
 * Force Join – exigir que o usuário esteja no canal antes de usar comandos (PV)
 * - Mostra botão "Entrar no Canal" + "✅ Já entrei"
 * - Ao clicar em "✅ Já entrei":
 *    • se NÃO entrou: alerta informando que ainda não entrou
 *    • se entrou: libera e mostra confirmação
 *
 * Coloque este arquivo na MESMA pasta do bot.php.
 */

//////////////// CONFIG //////////////////

// Ative/desative rapidamente o recurso:
if (!defined('FORCE_JOIN_ENABLED')) define('FORCE_JOIN_ENABLED', true);

// ID numérico do canal (ex.: -1001234567890)
if (!defined('FORCE_JOIN_CHAT_ID')) define('FORCE_JOIN_CHAT_ID', -1001735827798);

// Link do canal para o botão
if (!defined('FORCE_JOIN_CHANNEL')) define('FORCE_JOIN_CHANNEL', 'https://t.me/puxadasgratis21');

// Texto principal do gate (profissional)
if (!defined('FORCE_JOIN_TEXT')) define('FORCE_JOIN_TEXT',
  "🔒 <b>Acesso restrito</b>\n\n" .
  "Para utilizar os comandos, é necessário <b>entrar no nosso canal oficial</b>.\n" .
  "Após entrar, toque em <b>✅ Já entrei</b> para liberar o acesso."
);

// Mensagem quando ainda NÃO entrou
if (!defined('FORCE_JOIN_NOT_YET_TEXT')) define('FORCE_JOIN_NOT_YET_TEXT',
  "🔒 Você ainda <b>não entrou no canal</b>.\n\n" .
  "Entre no canal e toque em <b>✅ Já entrei</b> novamente."
);

// Mensagem quando entrou e foi liberado
if (!defined('FORCE_JOIN_OK_TEXT')) define('FORCE_JOIN_OK_TEXT',
  "✅ <b>Acesso liberado com sucesso!</b>\n\n" .
  "Você já pode utilizar <b>todos os comandos</b> do bot no privado."
);

//////////////////////////////////////////

// Usa as funções tg(), editMessageTextSafe() e logx() do bot.php

function keyboard_force_join(int $userId): array {
  return [
    'inline_keyboard' => [
      [
        ['text' => '📢 Entrar no Canal', 'url' => FORCE_JOIN_CHANNEL]
      ],
      [
        // trava por usuário para ninguém clicar no botão de outro
        ['text' => '✅ Já entrei', 'callback_data' => 'JOIN_CHECK|' . $userId]
      ]
    ]
  ];
}

/**
 * Verifica se o usuário é membro do canal.
 */
function is_member_of_channel(int $userId): bool {
  if (!FORCE_JOIN_ENABLED) return true;
  if (!FORCE_JOIN_CHAT_ID || FORCE_JOIN_CHAT_ID === 0) return true;

  $r = tg('getChatMember', [
    'chat_id' => FORCE_JOIN_CHAT_ID,
    'user_id' => $userId
  ]);

  if (($r['ok'] ?? false) !== true) {
    // Se não consegue checar, não bloqueia para não travar o bot.
    logx('force_join getChatMember falhou: ' . json_encode($r));
    return true;
  }

  $status = (string)($r['result']['status'] ?? '');

  // Aceita apenas creator, administrator e member (usuários que estão no canal)
  // left, kicked e restricted = não está no canal
  if (in_array($status, ['creator','administrator','member'], true)) return true;

  return false;
}

/**
 * Gate que bloqueia comandos no PV enquanto o user não entrar no canal.
 * Retorna true se pode continuar, false se já respondeu com o aviso.
 * 
 * ✅ VERIFICA EM TEMPO REAL se o usuário está no canal a cada comando
 */
function gate_private_force_join(int $userId, string $chatType, string $cmd, int $chatId, int $replyTo): bool {
  if (!FORCE_JOIN_ENABLED) return true;
  if ($chatType !== 'private') return true;
  if (in_array($cmd, ['/start','/menu'], true)) return true;

  // ✅ VERIFICA EM TEMPO REAL se o usuário está no canal
  if (is_member_of_channel($userId)) return true;

  // ❌ NÃO está no canal - bloqueia e mostra mensagem
  tg('sendMessage', [
    'chat_id' => $chatId,
    'text' => FORCE_JOIN_TEXT,
    'parse_mode' => 'HTML',
    'reply_to_message_id' => ($replyTo ?: 0),
    'allow_sending_without_reply' => true,
    'disable_web_page_preview' => true,
    'reply_markup' => keyboard_force_join($userId),
  ]);

  return false;
}

/**
 * Trata o clique no botão "✅ Já entrei".
 * Retorna true se tratou o callback (para não cair em "ação inválida").
 * 
 * ✅ VERIFICA EM TEMPO REAL se o usuário realmente está no canal
 */
function force_join_handle_callback(array $cb): bool {
  if (!FORCE_JOIN_ENABLED) return false;

  $data = (string)($cb['data'] ?? '');
  if (strpos($data, 'JOIN_CHECK|') !== 0) return false;

  $fromId = (int)($cb['from']['id'] ?? 0);
  $parts  = explode('|', $data);
  $ownerId = isset($parts[1]) ? (int)$parts[1] : 0;

  // Só o dono pode clicar
  if ($ownerId > 0 && $fromId !== $ownerId) {
    tg('answerCallbackQuery', [
      'callback_query_id' => $cb['id'] ?? '',
      'text' => '⚠️ Apenas o usuário que solicitou o acesso pode confirmar.',
      'show_alert' => true
    ]);
    return true;
  }

  // ✅ VERIFICA EM TEMPO REAL se o usuário está no canal
  $ok = is_member_of_channel($fromId);

  if (!$ok) {
    // ❌ Ainda não entrou no canal
    tg('answerCallbackQuery', [
      'callback_query_id' => $cb['id'] ?? '',
      'text' => strip_tags(FORCE_JOIN_NOT_YET_TEXT),
      'show_alert' => true
    ]);
    return true;
  }

  // ✅ Liberado: avisa e edita a mensagem para "liberado"
  tg('answerCallbackQuery', [
    'callback_query_id' => $cb['id'] ?? '',
    'text' => '✅ Acesso liberado! Você já pode usar os comandos no privado.',
    'show_alert' => true
  ]);

  // editar a mensagem onde estava o gate
  if (isset($cb['message']['chat']['id'], $cb['message']['message_id'])) {
    $chatId = (int)$cb['message']['chat']['id'];
    $msgId  = (int)$cb['message']['message_id'];

    editMessageTextSafe([
      'chat_id' => $chatId,
      'message_id' => $msgId,
      'text' => FORCE_JOIN_OK_TEXT,
      'parse_mode' => 'HTML',
      'disable_web_page_preview' => true,
    ]);
  }

  return true;

}
