<?php
declare(strict_types=1);

/**
 * handlers.php (PRO) - COMPLETO, AJUSTADO E COMPATÍVEL COM painel.php
 * ✅ Painel “tela única”
 * ✅ Boas-vindas (new_chat_members + chat_member)
 * ✅ Texto padrão de boas-vindas (modelo profissional)
 * ✅ Botões boas-vindas (URL e MSG) - max 11
 * ✅ Layout botões boas-vindas: 1 por linha OU 2/1/2/1...
 * ✅ WBMSG edita A MESMA mensagem (não envia nova)
 * ✅ WBACK restaura o texto original
 * ✅ Regras: Links / @bots
 * ✅ Anti-spam com cooldown
 * ✅ Anti-Porn (texto)
 * ✅ Anti-Palavrão melhorado + custom pelo painel (menu completo)
 * ✅ Mídias:
 *    - Compatível com painel.php: TOGGLE_BLOCKMEDIA (liga/desliga geral)
 *    - E também suporta separado (stickers / foto-vídeo) caso você use depois
 */

function ga_bot_username_for_links(): string {
  if (defined('BOT_USERNAME') && is_string(BOT_USERNAME) && BOT_USERNAME !== '') return BOT_USERNAME;
  return 'EmonNullbot';
}
function ga_add_group_url(): string {
  return 'https://t.me/' . ga_bot_username_for_links() . '?startgroup=new';
}

function ga_mention_html(int $id, string $name): string {
  if (function_exists('mention_html')) return mention_html($id, $name);
  $safe = htmlspecialchars($name !== '' ? $name : 'usuário', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return '<a href="tg://user?id='.$id.'">'.$safe.'</a>';
}

/** ===========================
 * TELA ÚNICA
 * =========================== */
function ga_edit_screen(array $cb, string $text, array $replyMarkup): void {
  $msg = (array)($cb['message'] ?? []);
  $chatId = (int)($msg['chat']['id'] ?? 0);
  $messageId = (int)($msg['message_id'] ?? 0);
  if ($chatId === 0 || $messageId === 0) return;

  $params = [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'reply_markup' => $replyMarkup,
    'disable_web_page_preview' => true,
  ];

  if (function_exists('editMessageTextSafe')) {
    editMessageTextSafe($params);
    return;
  }
  tg('editMessageText', $params);
}

/** ===========================
 * CACHE (voltar)
 * =========================== */
function ga_welcome_cache_file(): string { return GA_DATA_DIR . '/welcome_cache.json'; }

function ga_welcome_cache_load(): array {
  $f = ga_welcome_cache_file();
  if (!file_exists($f)) return [];
  $raw = @file_get_contents($f);
  $j = json_decode($raw ?: '[]', true);
  return is_array($j) ? $j : [];
}
function ga_welcome_cache_save(array $data): void {
  @file_put_contents(ga_welcome_cache_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function ga_welcome_cache_put(int $chatId, int $messageId, string $text, ?array $replyMarkup, ?array $entities = null): void {
  $all = ga_welcome_cache_load();
  $key = $chatId . ':' . $messageId;

  // TTL 2 dias
  $now = time();
  foreach ($all as $k => $v) {
    if (!is_array($v)) { unset($all[$k]); continue; }
    $ts = (int)($v['ts'] ?? 0);
    if ($ts > 0 && ($now - $ts) > 172800) unset($all[$k]);
  }

  $all[$key] = [
    'ts' => $now,
    'text' => $text,
    'reply_markup' => $replyMarkup,
    'entities' => (is_array($entities) ? $entities : null),
  ];
  ga_welcome_cache_save($all);
}
function ga_welcome_cache_get(int $chatId, int $messageId): ?array {
  $all = ga_welcome_cache_load();
  $key = $chatId . ':' . $messageId;
  return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : null;
}

/** ===========================
 * GRUPOS (menu privado)
 * =========================== */
function ga_groups_for_user(int $userId): array {
  $all = ga_load_all();
  $groups = $all['groups'] ?? [];
  if (!is_array($groups)) return [];

  $out = [];
  foreach ($groups as $g) {
    if (!is_array($g)) continue;
    if ((int)($g['owner_id'] ?? 0) !== $userId) continue;
    $out[] = $g;
  }

  usort($out, function($a,$b){
    $ta = (string)($a['title'] ?? '');
    $tb = (string)($b['title'] ?? '');
    return strcmp(mb_strtolower($ta,'UTF-8'), mb_strtolower($tb,'UTF-8'));
  });

  return $out;
}

function ga_groups_menu_kb(int $userId): array {
  $groups = ga_groups_for_user($userId);

  $rows = [];
  $row = [];

  foreach ($groups as $g) {
    $gid = (int)($g['chat_id'] ?? 0);
    if ($gid === 0) continue;
    $title = trim((string)($g['title'] ?? ''));
    if ($title === '') $title = "Grupo {$gid}";
    $row[] = ['text' => $title, 'callback_data' => "GA|OPEN_GROUP|{$gid}"];
    if (count($row) >= 2) { $rows[] = $row; $row = []; }
  }
  if ($row) $rows[] = $row;

  return ['inline_keyboard' => $rows];
}

function ga_groups_menu_payload(int $userId): array {
  $groups = ga_groups_for_user($userId);
  $hasGroups = count($groups) > 0;

  if (!$hasGroups) {
    $text = "❌ <b>Ops " . ga_mention_html($userId, "usuário") . "</b>\n\n"
          . "Você ainda não adicionou em nenhum Grupo.\n"
          . "Clique no botão abaixo em <b>Adicionar</b>.";

    $kb = [
      'inline_keyboard' => [
        [
          ['text'=>'➕ Adicionar bot em grupo (como admin)', 'url'=>ga_add_group_url()],
        ],
        [
          ['text'=>'⬅️ Voltar para o Menu', 'callback_data'=>"GER_GRUPOS_BACK|{$userId}"],
        ],
      ]
    ];

    return ['text'=>$text, 'reply_markup'=>$kb];
  }

  $text = "<b>📌 Menu de Gerenciamento de Grupos.</b>\n\nEscolha o grupo:";

  $kbBase = ga_groups_menu_kb($userId);
  $rows = $kbBase['inline_keyboard'] ?? [];
  if (!is_array($rows)) $rows = [];

  $rows[] = [
    ['text'=>'⬅️ Voltar para o Menu', 'callback_data'=>"GER_GRUPOS_BACK|{$userId}"]
  ];

  return ['text'=>$text, 'reply_markup'=>['inline_keyboard'=>$rows]];
}

function ga_send_groups_menu(int $userId, string $extraText = '', int $replyTo = 0): void {
  $payload = ga_groups_menu_payload($userId);

  if ($extraText !== '') {
    $payload['text'] = "<b>✅ " . htmlspecialchars($extraText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n" . $payload['text'];
  }

  tg('sendMessage', [
    'chat_id' => $userId,
    'text' => $payload['text'],
    'parse_mode' => 'HTML',
    'reply_markup' => $payload['reply_markup'],
    'disable_web_page_preview' => true,
    'reply_to_message_id' => ($replyTo > 0 ? $replyTo : null),
    'allow_sending_without_reply' => true,
  ]);
}

/** ===========================
 * QUANDO O BOT VIRA ADMIN
 * =========================== */
function ga_handle_my_chat_member(array $u): void {
  $chat   = (array)($u['chat'] ?? []);
  $chatId = (int)($chat['id'] ?? 0);
  $type   = (string)($chat['type'] ?? '');
  if (!in_array($type, ['group','supergroup'], true)) return;

  $from     = (array)($u['from'] ?? []);
  $byUserId = (int)($from['id'] ?? 0);

  $new   = (array)($u['new_chat_member'] ?? []);
  $isBot = (bool)($new['user']['is_bot'] ?? false);
  if (!$isBot) return;

  $status     = (string)($new['status'] ?? '');
  $isAdminNow = in_array($status, ['administrator','creator'], true);

  $title = (string)($chat['title'] ?? '');

  if ($isAdminNow && $chatId !== 0 && $byUserId !== 0) {
    ga_set_owner($chatId, $byUserId, $title);
    ga_send_groups_menu($byUserId, "Fui adicionado como admin em: " . ($title ?: "Grupo {$chatId}"));
  }
}

/** ===========================
 * BOAS-VINDAS por chat_member
 * =========================== */
function ga_handle_chat_member_welcome(array $u): void {
  $chat   = (array)($u['chat'] ?? []);
  $chatId = (int)($chat['id'] ?? 0);
  $type   = (string)($chat['type'] ?? '');
  if (!in_array($type, ['group','supergroup'], true)) return;

  $cfg = ga_group_get($chatId);
  if (empty($cfg['welcome_enabled'])) return;

  $new = (array)($u['new_chat_member'] ?? []);
  $old = (array)($u['old_chat_member'] ?? []);
  $user = (array)($new['user'] ?? []);

  if (!isset($user['id'])) return;
  if (!empty($user['is_bot'])) return;

  $oldStatus = (string)($old['status'] ?? '');
  $newStatus = (string)($new['status'] ?? '');

  if ($oldStatus === 'left' && in_array($newStatus, ['member','restricted'], true)) {
    $uid  = (int)$user['id'];
    $name = (string)($user['first_name'] ?? 'Usuário');

    $userMention = ga_mention_html($uid, $name);
    $rules = (string)($cfg['rules_text'] ?? '');

    // ✅ MODELO PROFISSIONAL (padrão)
    $welcome = (string)($cfg['welcome_text'] ?? "👋 Bem-vindo(a), {user}!\n\n{rules}\n\n?? Dúvidas ou suporte? Fale com a administração.");

    $welcome = str_replace(
      ['{user}','{rules}'],
      [$userMention, htmlspecialchars($rules, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')],
      $welcome
    );

    $kb = ga_welcome_reply_markup($cfg, $chatId);

    tg('sendMessage', [
      'chat_id' => $chatId,
      'text' => $welcome,
      'parse_mode' => 'HTML',
      'reply_markup' => $kb,
      'disable_web_page_preview' => true,
      'allow_sending_without_reply' => true,
    ]);
  }
}

/** ===========================
 * BOTÕES BOAS-VINDAS (menu config)
 * =========================== */
function ga_kb_wbtn_menu(int $groupId): array {
  return [
    'inline_keyboard' => [
      [ ['text'=>'➕ Adicionar botão', 'callback_data'=>"GA|WBTN_ADD|{$groupId}"] ],
      [
        ['text'=>'✏️ Editar botão', 'callback_data'=>"GA|WBTN_EDIT_MENU|{$groupId}"],
        ['text'=>'🗑 Excluir botão', 'callback_data'=>"GA|WBTN_DELETE_MENU|{$groupId}"]
      ],
      [
        ['text'=>'📐 Layout 1/2', 'callback_data'=>"GA|WBTN_LAYOUT|{$groupId}"]
      ],
      [ ['text'=>'⬅️ Voltar', 'callback_data'=>"GA|OPEN_GROUP|{$groupId}"] ]
    ]
  ];
}

function ga_kb_wbtn_select(array $btns, int $groupId, string $mode): array {
  $rows = [];
  foreach ($btns as $i => $b) {
    $text = trim((string)($b['text'] ?? ''));
    if ($text === '') continue;
    $rows[] = [ [ 'text' => $text, 'callback_data' => "GA|WBTN_{$mode}|{$groupId}|{$i}" ] ];
  }
  $rows[] = [ [ 'text' => '⬅️ Voltar', 'callback_data' => "GA|WBTN_MENU|{$groupId}" ] ];
  return ['inline_keyboard' => $rows];
}

/**
 * Teclado da mensagem de boas-vindas
 * - max 11 botões
 * - layout: welcome_buttons_per_row=1 => 1 por linha
 * - layout=2 => padrão 2/1/2/1/...
 */
function ga_welcome_reply_markup(array $cfg, int $groupId): ?array {
  $btns = $cfg['welcome_buttons'] ?? [];
  if (!is_array($btns) || !$btns) return null;

  $mode = ((int)($cfg['welcome_buttons_per_row'] ?? 1) === 2) ? 2 : 1;
  $btns = array_slice(array_values($btns), 0, 11);

  $rows = [];
  $row  = [];
  $rowTarget = ($mode === 2) ? 2 : 1;

  foreach ($btns as $i => $b) {
    $type = (string)($b['type'] ?? '');
    $text = trim((string)($b['text'] ?? ''));
    if ($text === '') continue;

    if ($type === 'url') {
      $url = trim((string)($b['url'] ?? ''));
      if ($url === '') continue;
      $row[] = ['text'=>$text, 'url'=>$url];
    } elseif ($type === 'msg') {
      $row[] = ['text'=>$text, 'callback_data'=>"GA|WBMSG|{$groupId}|{$i}"];
    }

    if (count($row) >= $rowTarget) {
      $rows[] = $row;
      $row = [];
      if ($mode === 2) {
        $rowTarget = ($rowTarget === 2) ? 1 : 2;
      }
    }
  }

  if ($row) $rows[] = $row;

  return $rows ? ['inline_keyboard'=>$rows] : null;
}

/** ===========================
 * ADMIN CHECK
 * =========================== */
function ga_is_admin(int $chatId, int $userId): bool {
  if (function_exists('is_admin_of_chat')) return (bool)is_admin_of_chat($chatId, $userId);
  $r = tg('getChatMember', ['chat_id'=>$chatId,'user_id'=>$userId]);
  if (($r['ok'] ?? false) !== true) return false;
  $st = (string)($r['result']['status'] ?? '');
  return in_array($st, ['creator','administrator'], true);
}

/** ===========================
 * DETECTORES (LINK / @BOT)
 * =========================== */
function ga_has_link(array $m): bool {
  $text = (string)($m['text'] ?? '');
  $cap  = (string)($m['caption'] ?? '');
  $hay  = trim($text . "\n" . $cap);

  if ($hay !== '' && preg_match('~(https?://|www\.|t\.me/|telegram\.me/)~iu', $hay)) return true;

  $ents = [];
  if (isset($m['entities']) && is_array($m['entities'])) $ents = array_merge($ents, $m['entities']);
  if (isset($m['caption_entities']) && is_array($m['caption_entities'])) $ents = array_merge($ents, $m['caption_entities']);
  foreach ($ents as $e) {
    if (!is_array($e)) continue;
    $t = (string)($e['type'] ?? '');
    if ($t === 'url' || $t === 'text_link') return true;
  }
  return false;
}

function ga_has_bot_username(array $m): bool {
  $text = (string)($m['text'] ?? '');
  $cap  = (string)($m['caption'] ?? '');
  $hay  = trim($text . "\n" . $cap);

  if ($hay !== '' && preg_match_all('~@([a-z0-9_]{3,})~iu', $hay, $mm)) {
    foreach ($mm[1] as $u) {
      if (preg_match('~bot$~iu', (string)$u)) return true;
    }
  }
  return false;
}

/** ===========================
 * BADWORDS (padrão + custom)
 * =========================== */
function ga_badwords_default_patterns(): array {
  return [
    '~\b(porn|porno|pornografia|xvideos|xvideo|xnxx|redtube|onlyfans)\b~iu',
    '~\b(vou te matar|te mato|matar|morre|morrer)\b~iu',
    '~\b(estupr(ar|o|a)|estupro)\b~iu',
    '~\b(vou comer sua m[aã]e|vou comer teu pai|comer sua m[aã]e|comer teu pai)\b~iu',
    '~\b(fdp|filho da puta|puta|piranha|vagabund[oa]|vadia)\b~iu',
    '~\b(pau|piroca|pica|buceta|xota|caralho|rola|gozar|siririca)\b~iu',
  ];
}

function ga_badwords_custom_list(array $cfg): array {
  $list = $cfg['badwords_custom'] ?? [];
  if (!is_array($list)) return [];
  $out = [];
  foreach ($list as $w) {
    $w = trim((string)$w);
    if ($w === '') continue;
    if (mb_strlen($w,'UTF-8') > 60) $w = mb_substr($w, 0, 60, 'UTF-8');
    $out[] = $w;
  }
  return array_values(array_unique($out));
}

function ga_badwords_match(string $text, array $cfg): bool {
  $t = trim($text);
  if ($t === '') return false;

  $t = mb_strtolower($t, 'UTF-8');
  $t = str_replace(['@','_','-','.'], ' ', $t);

  foreach (ga_badwords_default_patterns() as $rx) {
    if (preg_match($rx, $t)) return true;
  }

  $custom = ga_badwords_custom_list($cfg);
  foreach ($custom as $w) {
    $w2 = mb_strtolower($w, 'UTF-8');
    if ($w2 !== '' && mb_strpos($t, $w2) !== false) return true;
  }

  return false;
}

/** ===========================
 * MÍDIA
 * =========================== */
function ga_is_sticker_msg(array $m): bool { return isset($m['sticker']); }
function ga_is_photovideo_msg(array $m): bool {
  return isset($m['photo']) || isset($m['video']) || isset($m['animation']) || isset($m['document']);
}

/** ===========================
 * ANTI-SPAM (STATE)
 * =========================== */
function ga_antispam_state_file(): string { return GA_DATA_DIR . '/antispam_state.json'; }

function ga_antispam_state_load(): array {
  $f = ga_antispam_state_file();
  if (!file_exists($f)) return [];
  $raw = @file_get_contents($f);
  $j = json_decode($raw ?: '{}', true);
  return is_array($j) ? $j : [];
}
function ga_antispam_state_save(array $data): void {
  $f = ga_antispam_state_file();
  @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ga_punish_label(array $cfg): string {
  $mode = (string)($cfg['punish_mode'] ?? 'delete');
  $sec  = (int)($cfg['punish_seconds'] ?? 60);
  if ($mode === 'ban')  return "🚫 Banido";
  if ($mode === 'mute') return "🔇 Mutado por {$sec}s";
  return "🧹 Mensagens apagadas";
}

function ga_try_delete_messages(int $chatId, array $messageIds): void {
  $unique = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
  foreach ($unique as $mid) {
    if ($mid <= 0) continue;
    if (function_exists('deleteMessageSafe')) {
      deleteMessageSafe($chatId, $mid);
    } else {
      tg('deleteMessage', ['chat_id'=>$chatId,'message_id'=>$mid]);
    }
  }
}

/**
 * Anti-spam:
 * - 5 msgs em 8s = punição
 * - cooldown punição 30s
 * - aviso: 1 vez a cada 120s
 */
function ga_antispam_check_and_apply(array $cfg, int $chatId, int $userId, int $messageId, string $userName='usuário'): bool {
  if (empty($cfg['antispam_enabled'])) return false;

  $windowSec = 8;
  $maxMsgs   = 5;
  $punishCooldown = 30;
  $noticeCooldown = 120;

  $now = time();
  $key = $chatId . ':' . $userId;

  $st = ga_antispam_state_load();
  $row = isset($st[$key]) && is_array($st[$key]) ? $st[$key] : [
    'ts' => [],
    'msg' => [],
    'last_punish' => 0,
    'last_notice' => 0,
  ];

  $lastPun = (int)($row['last_punish'] ?? 0);
  $lastNot = (int)($row['last_notice'] ?? 0);
  $ts  = isset($row['ts']) && is_array($row['ts']) ? $row['ts'] : [];
  $msg = isset($row['msg']) && is_array($row['msg']) ? $row['msg'] : [];

  if (($now - $lastPun) < $punishCooldown) {
    ga_try_delete_messages($chatId, [$messageId]);
    return true;
  }

  $ts[]  = $now;
  $msg[] = $messageId;

  $cut = $now - $windowSec;
  $newTs = [];
  $newMsg = [];
  foreach ($ts as $i => $t) {
    $t = (int)$t;
    if ($t >= $cut) {
      $newTs[] = $t;
      if (isset($msg[$i])) $newMsg[] = (int)$msg[$i];
    }
  }
  $ts = $newTs;
  $msg = array_slice($newMsg, -40);

  $row['ts'] = $ts;
  $row['msg'] = $msg;
  $st[$key] = $row;
  ga_antispam_state_save($st);

  if (count($ts) < $maxMsgs) return false;

  ga_try_delete_messages($chatId, $msg);
  ga_apply_punishment($cfg, $chatId, $userId, $messageId);

  $row['ts'] = [];
  $row['msg'] = [];
  $row['last_punish'] = $now;

  if (($now - $lastNot) >= $noticeCooldown) {
    $row['last_notice'] = $now;
    $u = ga_mention_html($userId, $userName);
    $motivo = "Anti-spam (muitas mensagens em {$windowSec}s)";
    $acao = ga_punish_label($cfg);

    tg('sendMessage', [
      'chat_id' => $chatId,
      'text' => "⚠️ <b>Punição aplicada</b>\n👤 {$u}\n📌 <b>Motivo:</b> {$motivo}\n⚙️ <b>Ação:</b> {$acao}",
      'parse_mode' => 'HTML',
      'disable_web_page_preview' => true,
      'allow_sending_without_reply' => true,
    ]);
  }

  $st[$key] = $row;
  ga_antispam_state_save($st);

  return true;
}

/** ===========================
 * PUNIÇÃO
 * =========================== */
function ga_apply_punishment(array $cfg, int $chatId, int $userId, int $messageId): void {
  if (function_exists('deleteMessageSafe')) {
    deleteMessageSafe($chatId, $messageId);
  } else {
    tg('deleteMessage', ['chat_id'=>$chatId,'message_id'=>$messageId]);
  }

  $mode = (string)($cfg['punish_mode'] ?? 'delete');
  $sec  = (int)($cfg['punish_seconds'] ?? 60);
  $sec  = max(10, min(86400*7, $sec));

  if ($mode === 'mute') {
    tg('restrictChatMember', [
      'chat_id' => $chatId,
      'user_id' => $userId,
      'permissions' => [
        'can_send_messages' => false,
        'can_send_media_messages' => false,
        'can_send_polls' => false,
        'can_send_other_messages' => false,
        'can_add_web_page_previews' => false,
        'can_change_info' => false,
        'can_invite_users' => false,
        'can_pin_messages' => false,
      ],
      'until_date' => time() + $sec,
    ]);
    return;
  }

  if ($mode === 'ban') {
    tg('banChatMember', [
      'chat_id' => $chatId,
      'user_id' => $userId,
      'until_date' => time() + $sec,
      'revoke_messages' => false,
    ]);
    return;
  }
}

/** ===========================
 * MENU tempo punição
 * =========================== */
function ga_kb_time(int $groupId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'⏱ 30s',  'callback_data'=>"GA|SET_PTIME|{$groupId}|30"],
        ['text'=>'⏱ 1min', 'callback_data'=>"GA|SET_PTIME|{$groupId}|60"],
      ],
      [
        ['text'=>'⏱ 5min', 'callback_data'=>"GA|SET_PTIME|{$groupId}|300"],
        ['text'=>'⏱ 15min','callback_data'=>"GA|SET_PTIME|{$groupId}|900"],
      ],
      [
        ['text'=>'⏱ 1h',   'callback_data'=>"GA|SET_PTIME|{$groupId}|3600"],
        ['text'=>'⏱ 6h',   'callback_data'=>"GA|SET_PTIME|{$groupId}|21600"],
      ],
      [
        ['text'=>'⬅️ Voltar', 'callback_data'=>"GA|OPEN_GROUP|{$groupId}"]
      ]
    ]
  ];
}

/** ===========================
 * CALLBACK ROUTER
 * =========================== */
function ga_callback_router(array $cb): bool {
  $data = (string)($cb['data'] ?? '');
  if (strpos($data, 'GA|') !== 0) return false;

  $fromId = (int)($cb['from']['id'] ?? 0);
  $cbId   = (string)($cb['id'] ?? '');

  $msg       = (array)($cb['message'] ?? []);
  $chatIdMsg = (int)($msg['chat']['id'] ?? 0);
  $messageId = (int)($msg['message_id'] ?? 0);

  $parts   = explode('|', $data);
  $action  = $parts[1] ?? '';
  $groupId = isset($parts[2]) ? (int)$parts[2] : 0;

  // menu grupos
  if ($action === 'GROUPS_MENU') {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    $payload = ga_groups_menu_payload($fromId);
    ga_edit_screen($cb, $payload['text'], $payload['reply_markup']);
    return true;
  }

  // WBMSG (qualquer user) - edita a mesma msg
  if ($action === 'WBMSG') {
    $idx = isset($parts[3]) ? (int)$parts[3] : -1;

    if ($groupId === 0 || $idx < 0 || $chatIdMsg === 0 || $messageId === 0) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);
      return true;
    }

    $cfg  = ga_group_get($groupId);
    $btns = $cfg['welcome_buttons'] ?? [];

    if (!is_array($btns) || !isset($btns[$idx]) || ($btns[$idx]['type'] ?? '') !== 'msg') {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);
      return true;
    }

    $titleBtn = (string)($btns[$idx]['text'] ?? '');
    $message  = (string)($btns[$idx]['message'] ?? '');

    $origText     = (string)($msg['text'] ?? '');
    $origMarkup   = $msg['reply_markup'] ?? null;
    $origEntities = (isset($msg['entities']) && is_array($msg['entities'])) ? $msg['entities'] : null;

    ga_welcome_cache_put(
      $chatIdMsg,
      $messageId,
      $origText,
      is_array($origMarkup) ? $origMarkup : null,
      $origEntities
    );

    $newText = "<b>" . htmlspecialchars($titleBtn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n"
             . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    ga_edit_screen($cb, $newText, [
      'inline_keyboard' => [
        [ ['text'=>'⬅️ Voltar', 'callback_data'=>"GA|WBACK|{$groupId}"] ]
      ]
    ]);

    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }

  // WBACK (qualquer user)
  if ($action === 'WBACK') {
    $cached = ga_welcome_cache_get($chatIdMsg, $messageId);
    if (!$cached) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Não achei a mensagem original.','show_alert'=>true]);
      return true;
    }

    $params = [
      'chat_id' => $chatIdMsg,
      'message_id' => $messageId,
      'text' => (string)($cached['text'] ?? ''),
      'disable_web_page_preview' => true,
    ];

    if (isset($cached['reply_markup']) && is_array($cached['reply_markup'])) {
      $params['reply_markup'] = $cached['reply_markup'];
    }

    if (isset($cached['entities']) && is_array($cached['entities'])) {
      $params['entities'] = $cached['entities'];
    } else {
      $params['parse_mode'] = 'HTML';
    }

    if (function_exists('editMessageTextSafe')) {
      editMessageTextSafe($params);
    } else {
      tg('editMessageText', $params);
    }

    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }

  // abrir painel
  if ($action === 'OPEN_GROUP') {
    if ($groupId === 0) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Grupo inválido.','show_alert'=>true]);
      return true;
    }
    if (!ga_user_can_manage($groupId, $fromId)) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Você não pode gerenciar esse grupo.','show_alert'=>true]);
      return true;
    }
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    ga_edit_screen($cb, ga_status_text($groupId), ga_kb_main($groupId));
    return true;
  }

  // daqui pra baixo: somente admin/owner
  if ($groupId === 0) {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Grupo inválido.','show_alert'=>true]);
    return true;
  }
  if (!ga_user_can_manage($groupId, $fromId)) {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Você não pode configurar este grupo.','show_alert'=>true]);
    return true;
  }

  $cfg = ga_group_get($groupId);

  // toggles base
  if ($action === 'TOGGLE_LINKS') {
    $newVal = empty($cfg['block_links']);
    ga_group_set($groupId, ['block_links'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'✅ Links: BLOQUEADO':'✅ Links: LIBERADO']);
  }
  elseif ($action === 'TOGGLE_BOTUSER') {
    $newVal = empty($cfg['block_bots_usernames']);
    ga_group_set($groupId, ['block_bots_usernames'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'✅ Bots: BLOQUEADO':'✅ Bots: LIBERADO']);
  }
  elseif ($action === 'TOGGLE_WELCOME') {
    $newVal = empty($cfg['welcome_enabled']);
    ga_group_set($groupId, ['welcome_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'✅ Boas-vindas: ATIVO':'✅ Boas-vindas: DESATIVADO']);
  }
  elseif ($action === 'SET_WELCOME') {
  ga_pending_set($fromId, ['type'=>'welcome','group_id'=>$groupId]);

  ga_edit_screen(
    $cb,
    "<b>✍️ Definir boas-vindas</b>\n\n"
    ."<b>Exemplo:</b>\n\n"
    ."<code>👋 Bem-vindo(a), {user}!\n\n{rules}\n\n📣 Dúvidas ou suporte? Fale com a administração.</code>",
    [
      'inline_keyboard'=>[
        [['text'=>'⬅️ Voltar','callback_data'=>"GA|OPEN_GROUP|{$groupId}"]],
      ]
    ]
  );

  tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
  return true;
}
  elseif ($action === 'TOGGLE_ANTISPAM') {
    $newVal = empty($cfg['antispam_enabled']);
    ga_group_set($groupId, ['antispam_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🛡 Anti-spam: ATIVADO':'🛡 Anti-spam: DESATIVADO']);
  }

  // filtros
  elseif ($action === 'TOGGLE_ANTIPORN') {
    $newVal = empty($cfg['anti_porn_enabled']);
    ga_group_set($groupId, ['anti_porn_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🔞 Anti-Porn: ATIVADO':'🔞 Anti-Porn: DESATIVADO']);
  }

  /**
   * ✅ COMPATIBILIDADE COM painel.php:
   * painel.php usa: block_media_enabled + callback TOGGLE_BLOCKMEDIA
   * Aqui a gente liga/desliga geral e também espelha para os modos separados.
   */
  elseif ($action === 'TOGGLE_BLOCKMEDIA') {
    $newVal = empty($cfg['block_media_enabled']);
    ga_group_set($groupId, [
      'block_media_enabled'       => $newVal,
      'block_stickers_enabled'    => $newVal,
      'block_photovideo_enabled'  => $newVal,
    ]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🖼 Mídias: BLOQUEADO':'🖼 Mídias: LIBERADO']);
  }

  // (opcionais) separado - se você usar depois no painel
  elseif ($action === 'TOGGLE_BLOCK_STICKERS') {
    $newVal = empty($cfg['block_stickers_enabled']);
    ga_group_set($groupId, ['block_stickers_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🧩 Stickers: BLOQUEADO':'🧩 Stickers: LIBERADO']);
  }
  elseif ($action === 'TOGGLE_BLOCK_PV') {
    $newVal = empty($cfg['block_photovideo_enabled']);
    ga_group_set($groupId, ['block_photovideo_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🖼 Foto/Vídeo: BLOQUEADO':'🖼 Foto/Vídeo: LIBERADO']);
  }

  // punição
  elseif ($action === 'CYCLE_PUNISH') {
    $m = (string)($cfg['punish_mode'] ?? 'delete');
    $next = ($m === 'delete') ? 'mute' : (($m === 'mute') ? 'ban' : 'delete');
    ga_group_set($groupId, ['punish_mode'=>$next]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'✅ Punição alterada.']);
  }
  elseif ($action === 'PUNISH_TIME') {
    ga_edit_screen($cb, "<b>⏱ Tempo de punição</b>\nEscolha o tempo:", ga_kb_time($groupId));
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'SET_PTIME') {
    $sec = isset($parts[3]) ? (int)$parts[3] : 300;
    $sec = max(10, min(86400*7, $sec));
    ga_group_set($groupId, ['punish_seconds'=>$sec]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'✅ Tempo atualizado.']);
  }

  // menu botões welcome
  elseif ($action === 'WBTN_MENU') {
    $perRow = (int)($cfg['welcome_buttons_per_row'] ?? 1);
    $layout = ($perRow === 2) ? '2/1 alternado' : '1 por linha';
    $total  = is_array($cfg['welcome_buttons'] ?? null) ? count($cfg['welcome_buttons']) : 0;

    ga_edit_screen($cb,
      "<b>🔘 Botões da Boas-vindas</b>\n\n📦 <b>Total:</b> {$total} / 11\n📐 <b>Layout:</b> {$layout}",
      ga_kb_wbtn_menu($groupId)
    );
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'WBTN_LAYOUT') {
    $perRow = (int)($cfg['welcome_buttons_per_row'] ?? 1);
    $perRow = ($perRow === 2) ? 1 : 2;
    ga_group_set($groupId, ['welcome_buttons_per_row'=>$perRow]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>($perRow===2?'📐 Layout: 2/1 alternado':'📐 Layout: 1 por linha')]);
    ga_edit_screen($cb, ga_status_text($groupId), ga_kb_main($groupId));
    return true;
  }
  elseif ($action === 'WBTN_ADD') {
    $btns = $cfg['welcome_buttons'] ?? [];
    if (is_array($btns) && count($btns) >= 11) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Limite de 11 botões.','show_alert'=>true]);
      return true;
    }
    ga_pending_set($fromId, ['type'=>'wbtn_add','group_id'=>$groupId]);

    ga_edit_screen($cb,
      "<b>➕ Adicionar botão</b>\n\nEnvie no privado:\n<code>TEXTO | URL</code>\nou\n<code>TEXTO | MENSAGEM</code>",
      ['inline_keyboard'=>[
        [['text'=>'⬅️ Voltar','callback_data'=>"GA|WBTN_MENU|{$groupId}"]],
        [['text'=>'🏠 Painel','callback_data'=>"GA|OPEN_GROUP|{$groupId}"]],
      ]]
    );
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'WBTN_EDIT_MENU') {
    $btns = $cfg['welcome_buttons'] ?? [];
    if (!is_array($btns) || !$btns) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Nenhum botão para editar.','show_alert'=>true]);
      return true;
    }
    ga_edit_screen($cb, "<b>✏️ Editar botão</b>\nEscolha:", ga_kb_wbtn_select($btns, $groupId, 'EDIT'));
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'WBTN_DELETE_MENU') {
    $btns = $cfg['welcome_buttons'] ?? [];
    if (!is_array($btns) || !$btns) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Nenhum botão para excluir.','show_alert'=>true]);
      return true;
    }
    ga_edit_screen($cb, "<b>🗑 Excluir botão</b>\nEscolha:", ga_kb_wbtn_select($btns, $groupId, 'DEL'));
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'WBTN_EDIT') {
    $idx = isset($parts[3]) ? (int)$parts[3] : -1;
    $btns = $cfg['welcome_buttons'] ?? [];
    if (!is_array($btns) || !isset($btns[$idx])) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);
      return true;
    }
    ga_pending_set($fromId, ['type'=>'wbtn_edit','group_id'=>$groupId,'index'=>$idx]);
    ga_edit_screen($cb, "<b>✏️ Editar botão</b>\nEnvie no privado: <code>TEXTO | URL</code> ou <code>TEXTO | MENSAGEM</code>",
      ['inline_keyboard'=>[
        [['text'=>'⬅️ Voltar','callback_data'=>"GA|WBTN_MENU|{$groupId}"]],
      ]]
    );
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'WBTN_DEL') {
    $idx = isset($parts[3]) ? (int)$parts[3] : -1;
    $btns = $cfg['welcome_buttons'] ?? [];
    if (!is_array($btns) || !isset($btns[$idx])) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);
      return true;
    }
    unset($btns[$idx]);
    $btns = array_values($btns);
    ga_group_set($groupId, ['welcome_buttons'=>$btns]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'🗑 Botão removido.']);
  }

  /**
   * ✅ Anti-Palavrão (menu completo)
   * OBS: No seu painel.php atual, o botão é TOGGLE_BADWORDS.
   * Aqui a gente mantém o TOGGLE_BADWORDS como "liga/desliga",
   * e também disponibiliza BADWORDS_MENU (caso você queira mudar o painel depois).
   */
  elseif ($action === 'TOGGLE_BADWORDS') {
    $newVal = empty($cfg['anti_badwords_enabled']);
    ga_group_set($groupId, ['anti_badwords_enabled'=>$newVal]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=> $newVal?'🤬 Anti-Palavrão: ATIVADO':'🤬 Anti-Palavrão: DESATIVADO']);
  }
  elseif ($action === 'BADWORDS_MENU') {
    $on = !empty($cfg['anti_badwords_enabled']);
    $custom = ga_badwords_custom_list($cfg);
    $count = count($custom);

    ga_edit_screen($cb,
      "<b>🤬 Anti-Palavrão</b>\n\n"
      ."• Ativo: <b>".($on?'✅':'❌')."</b>\n"
      ."• Custom do admin: <b>{$count}</b>\n\n"
      ."Use os botões abaixo:",
      [
        'inline_keyboard'=>[
          [
            ['text'=>($on?'Desativar ❌':'Ativar ✅'), 'callback_data'=>"GA|TOGGLE_BADWORDS|{$groupId}"],
          ],
          [
            ['text'=>'➕ Adicionar palavra/frase', 'callback_data'=>"GA|BADWORDS_ADD|{$groupId}"],
            ['text'=>'🗑 Remover palavra/frase', 'callback_data'=>"GA|BADWORDS_DEL_MENU|{$groupId}"],
          ],
          [
            ['text'=>'⬅️ Voltar', 'callback_data'=>"GA|OPEN_GROUP|{$groupId}"],
          ]
        ]
      ]
    );

    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'BADWORDS_ADD') {
    ga_pending_set($fromId, ['type'=>'badwords_add','group_id'=>$groupId]);
    ga_edit_screen($cb,
      "<b>➕ Adicionar palavra/frase</b>\n\n"
      ."Envie no privado UMA palavra ou frase por mensagem.\n"
      ."Ex: <code>vou comer sua mãe</code>\n"
      ."Ex: <code>estuprar</code>",
      ['inline_keyboard'=>[
        [['text'=>'⬅️ Voltar', 'callback_data'=>"GA|BADWORDS_MENU|{$groupId}"]],
      ]]
    );
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'BADWORDS_DEL_MENU') {
    $list = ga_badwords_custom_list($cfg);
    if (!$list) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Nenhuma palavra/frase custom ainda.','show_alert'=>true]);
      return true;
    }

    $rows = [];
    foreach ($list as $i => $w) {
      $rows[] = [[ 'text'=>$w, 'callback_data'=>"GA|BADWORDS_DEL|{$groupId}|{$i}" ]];
    }
    $rows[] = [[ 'text'=>'⬅️ Voltar', 'callback_data'=>"GA|BADWORDS_MENU|{$groupId}" ]];

    ga_edit_screen($cb, "<b>🗑 Remover</b>\nEscolha qual remover:", ['inline_keyboard'=>$rows]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);
    return true;
  }
  elseif ($action === 'BADWORDS_DEL') {
    $idx = isset($parts[3]) ? (int)$parts[3] : -1;
    $list = ga_badwords_custom_list($cfg);

    if ($idx < 0 || !isset($list[$idx])) {
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Item inválido.','show_alert'=>true]);
      return true;
    }

    unset($list[$idx]);
    $list = array_values($list);

    ga_group_set($groupId, ['badwords_custom'=>$list]);
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'🗑 Removido.']);
  }

  // redesenha painel
  ga_edit_screen($cb, ga_status_text($groupId), ga_kb_main($groupId));
  return true;
}

/** ===========================
 * INPUT PRIVADO
 * =========================== */
function ga_private_input_router(array $m): void {
  $chat = (array)($m['chat'] ?? []);
  if ((string)($chat['type'] ?? '') !== 'private') return;

  $from = (array)($m['from'] ?? []);
  $userId = (int)($from['id'] ?? 0);
  if ($userId === 0) return;

  $text  = trim((string)($m['text'] ?? ''));
  $msgId = (int)($m['message_id'] ?? 0);

  if ($text === '/grupos' || str_starts_with($text, '/grupos ')) {
    ga_send_groups_menu($userId, '', $msgId);
    return;
  }

  if ($text === '/id' || str_starts_with($text, '/id ')) {
    $name = trim((string)($from['first_name'] ?? 'Usuário'));
    tg('sendMessage', [
      'chat_id'=>$userId,
      'text'=>"👤 <b>Seu ID:</b> <code>{$userId}</code>\n👤 <b>Nome:</b> ".htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      'parse_mode'=>'HTML',
      'reply_to_message_id'=>($msgId>0?$msgId:null),
      'allow_sending_without_reply'=>true
    ]);
    return;
  }

  if ($text === '') return;

  $pending = ga_pending_get($userId);
  if (!$pending) return;

  $type = (string)($pending['type'] ?? '');
  $gid  = (int)($pending['group_id'] ?? 0);
  if ($gid === 0) { ga_pending_clear($userId); return; }

  // welcome text
  if ($type === 'welcome') {
    ga_group_set($gid, ['welcome_text'=>$text]);
    ga_pending_clear($userId);

    tg('sendMessage', [
      'chat_id'=>$userId,
      'text'=>"✅ <b>Boas-vindas atualizada!</b>\n\nVolte ao painel:",
      'parse_mode'=>'HTML',
      'reply_to_message_id'=>($msgId>0?$msgId:null),
      'allow_sending_without_reply'=>true,
      'reply_markup'=>[
        'inline_keyboard'=>[
          [['text'=>'🏠 Abrir Painel do Grupo','callback_data'=>"GA|OPEN_GROUP|{$gid}"]],
          [['text'=>'🔘 Botões Boas-vindas','callback_data'=>"GA|WBTN_MENU|{$gid}"]],
          [['text'=>'📌 Menu de Grupos','callback_data'=>"GA|GROUPS_MENU|0"]],
        ]
      ]
    ]);
    return;
  }

  // badwords add
  if ($type === 'badwords_add') {
    $word = trim($text);
    if ($word === '') return;

    $cfg  = ga_group_get($gid);
    $list = ga_badwords_custom_list($cfg);

    if (count($list) >= 200) {
      tg('sendMessage', [
        'chat_id'=>$userId,
        'text'=>"⚠️ Limite de 200 palavras/frases custom atingido.",
      ]);
      ga_pending_clear($userId);
      return;
    }

    $list[] = $word;
    $list = array_values(array_unique($list));
    ga_group_set($gid, ['badwords_custom'=>$list]);

    tg('sendMessage', [
      'chat_id'=>$userId,
      'text'=>"✅ <b>Adicionado!</b>\n\nVolte ao painel:",
      'parse_mode'=>'HTML',
      'reply_markup'=>[
        'inline_keyboard'=>[
          [['text'=>'🤬 Anti-Palavrão','callback_data'=>"GA|BADWORDS_MENU|{$gid}"]],
          [['text'=>'🏠 Painel do Grupo','callback_data'=>"GA|OPEN_GROUP|{$gid}"]],
        ]
      ]
    ]);

    ga_pending_clear($userId);
    return;
  }

  // wbtn add/edit
  if ($type === 'wbtn_add' || $type === 'wbtn_edit') {
    $parts = array_map('trim', explode('|', $text, 2));
    if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
      tg('sendMessage', [
        'chat_id'=>$userId,
        'text'=>"⚠️ Formato inválido.\nUse:\n<code>TEXTO | URL</code>\nou\n<code>TEXTO | MENSAGEM</code>",
        'parse_mode'=>'HTML',
        'reply_to_message_id'=>($msgId>0?$msgId:null),
      ]);
      return;
    }

    $btnText = $parts[0];
    $value   = $parts[1];

    $cfg  = ga_group_get($gid);
    $btns = $cfg['welcome_buttons'] ?? [];
    if (!is_array($btns)) $btns = [];

    $isUrl = (bool)preg_match('~^https?://~i', $value) || (bool)preg_match('~^tg://~i', $value);

    if ($type === 'wbtn_add') {
      if (count($btns) >= 11) {
        tg('sendMessage', [
          'chat_id'=>$userId,
          'text'=>"⚠️ Limite de 11 botões atingido.",
        ]);
        return;
      }

      $btns[] = $isUrl
        ? ['type'=>'url','text'=>$btnText,'url'=>$value]
        : ['type'=>'msg','text'=>$btnText,'message'=>$value];
    } else {
      $idx = (int)($pending['index'] ?? -1);
      if ($idx < 0 || !isset($btns[$idx])) { ga_pending_clear($userId); return; }
      $btns[$idx] = $isUrl
        ? ['type'=>'url','text'=>$btnText,'url'=>$value]
        : ['type'=>'msg','text'=>$btnText,'message'=>$value];
    }

    ga_group_set($gid, ['welcome_buttons'=>$btns]);
    ga_pending_clear($userId);

    tg('sendMessage', [
      'chat_id'=>$userId,
      'text'=>"✅ <b>Botão salvo!</b>",
      'parse_mode'=>'HTML',
      'reply_to_message_id'=>($msgId>0?$msgId:null),
      'allow_sending_without_reply'=>true,
      'reply_markup'=>[
        'inline_keyboard'=>[
          [['text'=>'🔘 Botões Boas-vindas','callback_data'=>"GA|WBTN_MENU|{$gid}"]],
          [['text'=>'🏠 Painel do Grupo','callback_data'=>"GA|OPEN_GROUP|{$gid}"]],
        ]
      ]
    ]);
    return;
  }

  ga_pending_clear($userId);
}

/** ===========================
 * REGRAS DO GRUPO
 * =========================== */
function ga_group_rules_engine(array $m): void {
  $chat   = (array)($m['chat'] ?? []);
  $chatId = (int)($chat['id'] ?? 0);
  $type   = (string)($chat['type'] ?? '');
  if (!in_array($type, ['group','supergroup'], true)) return;

  $cfg = ga_group_get($chatId);

  // boas-vindas por new_chat_members
  if (!empty($cfg['welcome_enabled']) && isset($m['new_chat_members']) && is_array($m['new_chat_members'])) {
    foreach ($m['new_chat_members'] as $u) {
      if (!is_array($u)) continue;
      if (!empty($u['is_bot'])) continue;

      $uid = (int)($u['id'] ?? 0);
      if ($uid === 0) continue;

      $name = (string)($u['first_name'] ?? 'Usuário');
      $userMention = ga_mention_html($uid, $name);

      $rules = (string)($cfg['rules_text'] ?? '');

      // ✅ MODELO PROFISSIONAL (padrão) também aqui
      $welcome = (string)($cfg['welcome_text'] ?? "👋 Bem-vindo(a), {user}!\n\n{rules}\n\n📣 Dúvidas ou suporte? Fale com a administração.");

      $welcome = str_replace(
        ['{user}','{rules}'],
        [$userMention, htmlspecialchars($rules, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')],
        $welcome
      );

      $kb = ga_welcome_reply_markup($cfg, $chatId);

      tg('sendMessage', [
        'chat_id'=>$chatId,
        'text'=>$welcome,
        'parse_mode'=>'HTML',
        'reply_markup'=>$kb,
        'disable_web_page_preview'=>true,
        'allow_sending_without_reply'=>true
      ]);
    }
    return;
  }

  $from = (array)($m['from'] ?? []);
  $userId = (int)($from['id'] ?? 0);
  $msgId  = (int)($m['message_id'] ?? 0);
  if ($userId === 0 || $msgId === 0) return;

  // não pune admin
  if (ga_is_admin($chatId, $userId)) return;

  $hasText = isset($m['text']) || isset($m['caption']) || isset($m['entities']) || isset($m['caption_entities']);
  $textAll = trim((string)($m['text'] ?? '') . "\n" . (string)($m['caption'] ?? ''));

  // anti-spam primeiro
  if (!empty($cfg['antispam_enabled'])) {
    $name = (string)($from['first_name'] ?? 'usuário');
    if (ga_antispam_check_and_apply($cfg, $chatId, $userId, $msgId, $name)) return;
  }

  // ✅ mídia (compatível com painel.php)
  $blockMedia = !empty($cfg['block_media_enabled'])
    || !empty($cfg['block_stickers_enabled'])
    || !empty($cfg['block_photovideo_enabled']);

  if ($blockMedia) {
    // se usa modo geral, bloqueia tudo de mídia abaixo
    if (!empty($cfg['block_media_enabled'])) {
      if (ga_is_sticker_msg($m) || ga_is_photovideo_msg($m)) {
        ga_apply_punishment($cfg, $chatId, $userId, $msgId);
        return;
      }
    } else {
      // modo separado
      if (!empty($cfg['block_stickers_enabled']) && ga_is_sticker_msg($m)) {
        ga_apply_punishment($cfg, $chatId, $userId, $msgId);
        return;
      }
      if (!empty($cfg['block_photovideo_enabled']) && ga_is_photovideo_msg($m)) {
        ga_apply_punishment($cfg, $chatId, $userId, $msgId);
        return;
      }
    }
  }

  if (!$hasText && $textAll === '') {
    return;
  }

  // links
  if (!empty($cfg['block_links']) && ga_has_link($m)) {
    ga_apply_punishment($cfg, $chatId, $userId, $msgId);
    return;
  }

  // @bots
  if (!empty($cfg['block_bots_usernames']) && ga_has_bot_username($m)) {
    ga_apply_punishment($cfg, $chatId, $userId, $msgId);
    return;
  }

  // anti-porn (texto)
  if (!empty($cfg['anti_porn_enabled']) && $textAll !== '') {
    if (preg_match('~\b(porn|porno|pornografia|xvideos|xnxx|redtube|onlyfans)\b~iu', $textAll)) {
      ga_apply_punishment($cfg, $chatId, $userId, $msgId);
      return;
    }
  }

  // anti-palavrão (texto + custom)
  if (!empty($cfg['anti_badwords_enabled']) && $textAll !== '') {
    if (ga_badwords_match($textAll, $cfg)) {
      ga_apply_punishment($cfg, $chatId, $userId, $msgId);
      return;
    }
  }
}