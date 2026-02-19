Arruma neste códico e manda ele novamente completo atualizado

<?php  
declare(strict_types=1);  
  
/**  
 * handlers.php (PRO)  
 * ✅ Painel “tela única” (sempre substitui a mesma mensagem)  
 * ✅ Boas-vindas funciona quando usuário entra (new_chat_members)  
 * ✅ Adicionar botão: TEXTO | URL  ou  TEXTO | MENSAGEM (funciona)  
 * ✅ Voltar restaura sem empilhar mensagens  
 * ✅ Links/Bots/Punição/Tempo/Botões: tudo responde  
 *  
 * ✅ (NOVO) Regras Links / @bots agora punem DE VERDADE no grupo  
 * ✅ (NOVO) Auto-delete toggle usa ✅/❌  
 * ✅ (NOVO) Tempo auto-delete fixo (60s) -> botão "Tempo auto-delete" vira aviso  
 */  
  
function ga_bot_username_for_links(): string {  
  if (defined('BOT_USERNAME') && is_string(BOT_USERNAME) && BOT_USERNAME !== '') return BOT_USERNAME;  
  return 'EmonNullbot';  
}  
function ga_add_group_url(): string {  
  return 'https://t.me/' . ga_bot_username_for_links() . '?startgroup=new';  
}  
  
/** fallback caso mention_html não exista (normalmente existe no index.php) */  
function ga_mention_html(int $id, string $name): string {  
  if (function_exists('mention_html')) return mention_html($id, $name);  
  $safe = htmlspecialchars($name !== '' ? $name : 'usuário', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');  
  return '<a href="tg://user?id='.$id.'">'.$safe.'</a>';  
}  
  
/**  
 * ===========================  
 * “TELA ÚNICA” - editor padrão  
 * ===========================  
 */  
function ga_edit_screen(array $cb, string $text, array $replyMarkup): void {  
  $msg = (array)($cb['message'] ?? []);  
  $chatId = (int)($msg['chat']['id'] ?? 0);  
  $messageId = (int)($msg['message_id'] ?? 0);  
  if ($chatId === 0 || $messageId === 0) return;  
  
  editMessageTextSafe([  
    'chat_id' => $chatId,  
    'message_id' => $messageId,  
    'text' => $text,  
    'parse_mode' => 'HTML',  
    'reply_markup' => $replyMarkup,  
    'disable_web_page_preview' => true,  
  ]);  
}  
  
/**  
 * ===========================  
 * CACHE da mensagem de boas-vindas (pra voltar) + ENTITIES  
 * ===========================  
 */  
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
  
  // TTL: 2 dias  
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
  
/**  
 * ===========================  
 * MENU DE GRUPOS (privado)  
 * ===========================  
 */  
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
  
  $text = "<b>📌 Menu de Gerenciamento de Grupos.</b>\n\n"  
        . "Escolha abaixo qual grupo você quer gerenciar:";  
  
  $kbBase = ga_groups_menu_kb($userId);  
  $rows = $kbBase['inline_keyboard'] ?? [];  
  if (!is_array($rows)) $rows = [];  
  
  $rows[] = [  
    ['text'=>'⬅️ Voltar para o Menu', 'callback_data'=>"GER_GRUPOS_BACK|{$userId}"]  
  ];  
  
  return [  
    'text' => $text,  
    'reply_markup' => ['inline_keyboard' => $rows],  
  ];  
}  
  
/**  
 * /grupos (manda menu no PV respondendo o comando)  
 */  
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
  
/**  
 * ===========================  
 * AUTO: quando vira admin  
 * ===========================  
 */  
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
  
/**  
 * ===========================  
 * CALLBACK ROUTER  
 * ===========================  
 */  
function ga_callback_router(array $cb): bool {  
  $data = (string)($cb['data'] ?? '');  
  if (strpos($data, 'GA|') !== 0) return false;  
  
  $fromId    = (int)($cb['from']['id'] ?? 0);  
  $cbId      = (string)($cb['id'] ?? '');  
  $msg       = (array)($cb['message'] ?? []);  
  $chatIdMsg = (int)($msg['chat']['id'] ?? 0);  
  $messageId = (int)($msg['message_id'] ?? 0);  
  
  $parts   = explode('|', $data);  
  $action  = $parts[1] ?? '';  
  $groupId = isset($parts[2]) ? (int)$parts[2] : 0;  
  
  // Menu de grupos (na mesma mensagem)  
  if ($action === 'GROUPS_MENU') {  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    $payload = ga_groups_menu_payload($fromId);  
    ga_edit_screen($cb, $payload['text'], $payload['reply_markup']);  
    return true;  
  }  
  
  // Abrir painel do grupo  
  if ($action === 'OPEN_GROUP') {  
    $gid = $groupId;  
    if ($gid === 0) {  
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Grupo inválido.','show_alert'=>true]);  
      return true;  
    }  
    if (!ga_user_can_manage($gid, $fromId)) {  
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Você não pode gerenciar esse grupo.','show_alert'=>true]);  
      return true;  
    }  
  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    ga_edit_screen($cb, ga_status_text($gid), ga_kb_main($gid));  
    return true;  
  }  
  
  // Botão MSG da boas-vindas  
  if ($action === 'WBMSG') {  
    $idx = isset($parts[3]) ? (int)$parts[3] : -1;  
    if ($groupId === 0 || $idx < 0) {  
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);  
      return true;  
    }  
  
    $cfg = ga_group_get($groupId);  
    $btns = $cfg['welcome_buttons'] ?? [];  
    if (!is_array($btns) || !isset($btns[$idx]) || ($btns[$idx]['type'] ?? '') !== 'msg') {  
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Botão inválido.','show_alert'=>true]);  
      return true;  
    }  
  
    $titleBtn = (string)($btns[$idx]['text'] ?? '');  
    $message  = (string)($btns[$idx]['message'] ?? '');  
  
    // salva original com ENTITIES  
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
  
  // Voltar: restaura texto + entities  
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
    if (isset($cached['reply_markup']) && is_array($cached['reply_markup'])) $params['reply_markup'] = $cached['reply_markup'];  
    if (isset($cached['entities']) && is_array($cached['entities'])) $params['entities'] = $cached['entities'];  
  
    editMessageTextSafe($params);  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    return true;  
  }  
  
  // daqui pra baixo: ações do painel  
  if ($groupId === 0) {  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Grupo inválido.','show_alert'=>true]);  
    return true;  
  }  
  if (!ga_user_can_manage($groupId, $fromId)) {  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Você não pode configurar este grupo.','show_alert'=>true]);  
    return true;  
  }  
  
  $cfg = ga_group_get($groupId);  
  
  if ($action === 'TOGGLE_LINKS') {  
    $newVal = empty($cfg['block_links']);  
    ga_group_set($groupId, ['block_links' => $newVal]);  
    tg('answerCallbackQuery', [  
      'callback_query_id'=>$cbId,  
      'text'=> $newVal ? '✅ Links: BLOQUEADO' : '✅ Links: LIBERADO',  
      'show_alert'=>false  
    ]);  
  }  
  elseif ($action === 'TOGGLE_BOTUSER') {  
    $newVal = empty($cfg['block_bots_usernames']);  
    ga_group_set($groupId, ['block_bots_usernames' => $newVal]);  
    tg('answerCallbackQuery', [  
      'callback_query_id'=>$cbId,  
      'text'=> $newVal ? '✅ @bot: BLOQUEADO' : '✅ @bot: LIBERADO',  
      'show_alert'=>false  
    ]);  
  }  
  elseif ($action === 'TOGGLE_WELCOME') {  
    $newVal = empty($cfg['welcome_enabled']);  
    ga_group_set($groupId, ['welcome_enabled' => $newVal]);  
    tg('answerCallbackQuery', [  
      'callback_query_id'=>$cbId,  
      'text'=> $newVal ? '✅ Boas-vindas: ATIVO' : '✅ Boas-vindas: DESATIVADO',  
      'show_alert'=>false  
    ]);  
  }  
  elseif ($action === 'CYCLE_PUNISH') {  
    $m = (string)($cfg['punish_mode'] ?? 'delete');  
    $next = ($m === 'delete') ? 'mute' : (($m === 'mute') ? 'ban' : 'delete');  
    ga_group_set($groupId, ['punish_mode'=>$next]);  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'✅ Punição alterada.','show_alert'=>false]);  
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
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'✅ Tempo atualizado.','show_alert'=>false]);  
  }  
  elseif ($action === 'SET_WELCOME') {  
    ga_pending_set($fromId, ['type'=>'welcome', 'group_id'=>$groupId]);  
  
    ga_edit_screen($cb,  
      "<b>✍️ Definir boas-vindas</b>\n\n"  
      ."Envie agora a mensagem aqui no privado.\n"  
      ."Use <code>{user}</code> e <code>{rules}</code>.\n\n"  
      ."Ex:\n<code>Bem-vindo {user} ao grupo!\n\n{rules}</code>",  
      [  
        'inline_keyboard' => [  
          [['text'=>'⬅️ Voltar','callback_data'=>"GA|OPEN_GROUP|{$groupId}"]],  
        ]  
      ]  
    );  
  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    return true;  
  }  
  elseif ($action === 'WBTN_MENU') {  
    $perRow = (int)($cfg['welcome_buttons_per_row'] ?? 1);  
    $layout = ($perRow === 2) ? '2 por linha' : '1 por linha';  
  
    ga_edit_screen($cb,  
      "<b>🔘 Botões da Boas-vindas</b>\n\n"  
      ."Você pode adicionar até <b>10</b> botões.\n"  
      ."Layout atual: <b>{$layout}</b>\n\n"  
      ."Clique em ➕ para adicionar.",  
      ga_kb_wbtn_menu($groupId)  
    );  
  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    return true;  
  }  
  elseif ($action === 'WBTN_LAYOUT') {  
    $perRow = (int)($cfg['welcome_buttons_per_row'] ?? 1);  
    $perRow = ($perRow === 2) ? 1 : 2;  
    ga_group_set($groupId, ['welcome_buttons_per_row' => $perRow]);  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>($perRow===2?'✅ 2 por linha':'✅ 1 por linha'),'show_alert'=>false]);  
  }  
  elseif ($action === 'WBTN_ADD') {  
    $cfgBtns = $cfg['welcome_buttons'] ?? [];  
    if (is_array($cfgBtns) && count($cfgBtns) >= 10) {  
      tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Limite de 10 botões.','show_alert'=>true]);  
      return true;  
    }  
  
    ga_pending_set($fromId, ['type'=>'wbtn_add', 'group_id'=>$groupId]);  
  
    ga_edit_screen($cb,  
      "<b>➕ Adicionar botão</b>\n\n"  
      ."Envie assim:\n<code>TEXTO | URL</code>\n"  
      ."ou\n<code>TEXTO | MENSAGEM</code>\n\n"  
      ."Ex:\n<code>Meu canal | https://t.me/seucanal</code>\n"  
      ."<code>Suporte | Chame o @suporte</code>\n\n"  
      ."Depois de enviar, eu salvo e você volta pro menu automaticamente.",  
      [  
        'inline_keyboard' => [  
          [['text'=>'⬅️ Voltar','callback_data'=>"GA|WBTN_MENU|{$groupId}"]],  
          [['text'=>'🏠 Painel','callback_data'=>"GA|OPEN_GROUP|{$groupId}"]],  
        ]  
      ]  
    );  
  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId]);  
    return true;  
  }  
  elseif ($action === 'WBTN_CLEAR') {  
    ga_group_set($groupId, ['welcome_buttons'=>[]]);  
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'✅ Botões removidos.','show_alert'=>false]);  
  }  
  
  /**  
   * ✅ NOVO: botão do tempo do auto-delete vira apenas aviso (tempo fixo)  
   * (assim não abre menu nenhum e não muda o tempo)  
   */  
     
     
  elseif ($action === 'AUTO_TIME' || $action === 'AUTODELETE_TIME' || $action === 'TIME_AUTO_DELETE') {  
    tg('answerCallbackQuery', [  
      'callback_query_id'=>$cbId,  
      'text'=>'⏱ Tempo do auto-delete é fixo: 60s.',  
      'show_alert'=>false  
    ]);  
  }  
  
  // ✅ sempre redesenha o painel principal (sem empilhar)  
  ga_edit_screen($cb, ga_status_text($groupId), ga_kb_main($groupId));  
  return true;  
}  
  
/**  
 * ===========================  
 * INPUT DO PRIVADO  
 * ===========================  
 */  
function ga_private_input_router(array $m): void {  
  $chat = (array)($m['chat'] ?? []);  
  if ((string)($chat['type'] ?? '') !== 'private') return;  
  
  $from = (array)($m['from'] ?? []);  
  $userId = (int)($from['id'] ?? 0);  
  if ($userId === 0) return;  
  
  $text = trim((string)($m['text'] ?? ''));  
  $msgId = (int)($m['message_id'] ?? 0);  
  
  if ($text === '/grupos' || str_starts_with($text, '/grupos ')) {  
    ga_send_groups_menu($userId, '', $msgId);  
    return;  
  }  
  
  if ($text === '/id' || str_starts_with($text, '/id ')) {  
    $name = trim((string)($from['first_name'] ?? 'Usuário'));  
    $u = "👤 <b>Seu ID:</b> <code>{$userId}</code>\n"  
       . "👤 <b>Nome:</b> " . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');  
    tg('sendMessage', [  
      'chat_id'=>$userId,  
      'text'=>$u,  
      'parse_mode'=>'HTML',  
      'reply_to_message_id' => ($msgId>0?$msgId:null),  
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
  
  // ✅ salvar boas-vindas  
  if ($type === 'welcome') {  
    ga_group_set($gid, ['welcome_text'=>$text]);  
    ga_pending_clear($userId);  
  
    tg('sendMessage', [  
      'chat_id' => $userId,  
      'text' => "✅ <b>Boas-vindas atualizada!</b>\n\nAgora volte ao painel:",  
      'parse_mode' => 'HTML',  
      'reply_to_message_id' => ($msgId>0?$msgId:null),  
      'allow_sending_without_reply'=>true,  
      'reply_markup' => [  
        'inline_keyboard' => [  
          [['text'=>'🏠 Abrir Painel do Grupo','callback_data'=>"GA|OPEN_GROUP|{$gid}"]],  
          [['text'=>'📌 Menu de Grupos','callback_data'=>"GA|GROUPS_MENU|0"]],  
        ]  
      ]  
    ]);  
  
    return;  
  }  
  
  // ✅ adicionar botão  
  if ($type === 'wbtn_add') {  
    // formato: TEXTO | URL  ou  TEXTO | MENSAGEM  
    $parts = array_map('trim', explode('|', $text, 2));  
    if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {  
      tg('sendMessage', [  
        'chat_id'=>$userId,  
        'text'=> "⚠️ Formato inválido.\n\nUse:\n<code>TEXTO | URL</code>\nou\n<code>TEXTO | MENSAGEM</code>",  
        'parse_mode'=>'HTML',  
        'reply_to_message_id'=>($msgId>0?$msgId:null),  
        'allow_sending_without_reply'=>true,  
      ]);  
      return;  
    }  
  
    $btnText = $parts[0];  
    $value   = $parts[1];  
  
    $cfg = ga_group_get($gid);  
    $btns = $cfg['welcome_buttons'] ?? [];  
    if (!is_array($btns)) $btns = [];  
  
    // decide url vs msg  
    $isUrl = (bool)preg_match('~^https?://~i', $value) || (bool)preg_match('~^tg://~i', $value);  
  
    if ($isUrl) {  
      // valida URL simples  
      $url = $value;  
      if (!filter_var($url, FILTER_VALIDATE_URL) && stripos($url, 'tg://') !== 0) {  
        tg('sendMessage', [  
          'chat_id'=>$userId,  
          'text'=> "⚠️ URL inválida.\nEx: <code>Canal | https://t.me/seucanal</code>",  
          'parse_mode'=>'HTML',  
          'reply_to_message_id'=>($msgId>0?$msgId:null),  
          'allow_sending_without_reply'=>true,  
        ]);  
        return;  
      }  
      $btns[] = ['type'=>'url','text'=>$btnText,'url'=>$url];  
    } else {  
      $btns[] = ['type'=>'msg','text'=>$btnText,'message'=>$value];  
    }  
  
    // salva (storage sanitiza + limita 10)  
    ga_group_set($gid, ['welcome_buttons'=>$btns]);  
    ga_pending_clear($userId);  
  
    tg('sendMessage', [  
      'chat_id'=>$userId,  
      'text'=> "✅ <b>Botão adicionado com sucesso!</b>\n\nVolte ao menu de botões:",  
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
  
  // se for outro tipo, limpa  
  ga_pending_clear($userId);  
}  
  
/**  
 * ===========================  
 * Teclado da boas-vindas  
 * ===========================  
 */  
function ga_welcome_reply_markup(array $cfg, int $groupId): ?array {  
  $btns = $cfg['welcome_buttons'] ?? [];  
  if (!is_array($btns) || count($btns) === 0) return null;  
  
  $perRow = (int)($cfg['welcome_buttons_per_row'] ?? 1);  
  $perRow = ($perRow === 2) ? 2 : 1;  
  
  $btns = array_slice(array_values($btns), 0, 10);  
  
  $rows = [];  
  $row = [];  
  
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
  
    if (count($row) >= $perRow) { $rows[] = $row; $row = []; }  
  }  
  
  if (!empty($row)) $rows[] = $row;  
  if (!$rows) return null;  
  
  return ['inline_keyboard' => $rows];  
}  
  
/**  
 * ===========================  
 * (NOVO) Helpers de punição/regras  
 * ===========================  
 */  
function ga_is_admin(int $chatId, int $userId): bool {  
  // usa helper se existir no index.php  
  if (function_exists('is_admin_of_chat')) return (bool)is_admin_of_chat($chatId, $userId);  
  
  $r = tg('getChatMember', ['chat_id'=>$chatId,'user_id'=>$userId]);  
  if (($r['ok'] ?? false) !== true) return false;  
  $st = (string)($r['result']['status'] ?? '');  
  return in_array($st, ['creator','administrator'], true);  
}  
  
function ga_has_link(array $m): bool {  
  $text = (string)($m['text'] ?? '');  
  $cap  = (string)($m['caption'] ?? '');  
  $hay  = trim($text . "\n" . $cap);  
  
  if ($hay !== '') {  
    // http/https, www, t.me, telegram.me  
    if (preg_match('~(https?://|www\.|t\.me/|telegram\.me/)~iu', $hay)) return true;  
  }  
  
  // entities com url/text_link  
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
  
  if ($hay !== '') {  
    // pega @algumaCoisa  
    if (preg_match_all('~@([a-z0-9_]{3,})~iu', $hay, $mm)) {  
      foreach ($mm[1] as $u) {  
        // se terminar com "bot", tratar como username de bot  
        if (preg_match('~bot$~iu', (string)$u)) return true;  
      }  
    }  
  }  
  
  // entidades mention  
  $ents = [];  
  if (isset($m['entities']) && is_array($m['entities'])) $ents = array_merge($ents, $m['entities']);  
  if (isset($m['caption_entities']) && is_array($m['caption_entities'])) $ents = array_merge($ents, $m['caption_entities']);  
  
  foreach ($ents as $e) {  
    if (!is_array($e)) continue;  
    if (($e['type'] ?? '') === 'mention') {  
      $off = (int)($e['offset'] ?? 0);  
      $len = (int)($e['length'] ?? 0);  
      $substr = mb_substr($hay, $off, $len, 'UTF-8');  
      if ($substr && preg_match('~@([a-z0-9_]{3,})~iu', $substr, $mm2)) {  
        if (preg_match('~bot$~iu', (string)($mm2[1] ?? ''))) return true;  
      }  
    }  
  }  
  
  return false;  
}  
  
function ga_apply_punishment(array $cfg, int $chatId, int $userId, int $messageId): void {  
  // apaga mensagem sempre (quando regra ativa)  
  if (function_exists('deleteMessageSafe')) {  
    deleteMessageSafe($chatId, $messageId);  
  } else {  
    tg('deleteMessage', ['chat_id'=>$chatId,'message_id'=>$messageId]);  
  }  
  
  $mode = (string)($cfg['punish_mode'] ?? 'delete'); // delete|mute|ban  
  $sec  = (int)($cfg['punish_seconds'] ?? 60);  
  $sec  = max(10, min(86400*7, $sec));  
  
  if ($mode === 'mute') {  
    tg('restrictChatMember', [  
      'chat_id' => $chatId,  
      'user_id' => $userId,  
      'permissions' => ['can_send_messages' => false],  
      'until_date' => time() + $sec,  
    ]);  
    return;  
  }  
  
  if ($mode === 'ban') {  
    tg('banChatMember', [  
      'chat_id' => $chatId,  
      'user_id' => $userId,  
      'revoke_messages' => false,  
    ]);  
    return;  
  }  
  
  // delete -> já apagou a msg e encerra  
}  
  
/**  
 * ===========================  
 * Motor de regras no grupo + boas-vindas (ENTRADA)  
 * ===========================  
 */  
function ga_group_rules_engine(array $m): void {  
  $chat   = (array)($m['chat'] ?? []);  
  $chatId = (int)($chat['id'] ?? 0);  
  $type   = (string)($chat['type'] ?? '');  
  if (!in_array($type, ['group','supergroup'], true)) return;  
  
  $cfg = ga_group_get($chatId);  
  
  // ✅ BOAS-VINDAS quando usuário entra  
  if (!empty($cfg['welcome_enabled']) && isset($m['new_chat_members']) && is_array($m['new_chat_members'])) {  
    foreach ($m['new_chat_members'] as $u) {  
      if (!is_array($u)) continue;  
      $uid = (int)($u['id'] ?? 0);  
      if ($uid === 0) continue;  
  
      // evita dar boas-vindas pro próprio bot  
      if (!empty($u['is_bot'])) continue;  
  
      $name = (string)($u['first_name'] ?? 'Usuário');  
      $userMention = ga_mention_html($uid, $name);  
  
      $rules = (string)($cfg['rules_text'] ?? '');  
      $welcome = (string)($cfg['welcome_text'] ?? "👋 Bem-vindo {user}!\n\n{rules}");  
  
      // monta mensagem  
      $welcome = str_replace(  
        ['{user}','{rules}'],  
        [$userMention, htmlspecialchars($rules, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')],  
        $welcome  
      );  
  
      $welcomeHtml = nl2br($welcome, false);  
      $welcomeHtml = str_replace("<br>", "\n", $welcomeHtml);  
  
      $kb = ga_welcome_reply_markup($cfg, $chatId);  
  
      tg('sendMessage', [  
        'chat_id' => $chatId,  
        'text' => $welcomeHtml,  
        'parse_mode' => 'HTML',  
        'reply_markup' => $kb,  
        'disable_web_page_preview' => true,  
      ]);  
    }  
    // segue sem aplicar punição aqui (evento de entrada)  
    return;  
  }  
  
  // ✅ (NOVO) Regras: Links e @user de bots  
  $from = (array)($m['from'] ?? []);  
  $userId = (int)($from['id'] ?? 0);  
  $msgId  = (int)($m['message_id'] ?? 0);  
  if ($userId === 0 || $msgId === 0) return;  
  
  // não pune admin/dono  
  if (ga_is_admin($chatId, $userId)) return;  
  
  // se for serviço/aviso sem texto, ignore  
  $hasText = isset($m['text']) || isset($m['caption']) || isset($m['entities']) || isset($m['caption_entities']);  
  if (!$hasText) return;  
  
  // Links  
  if (!empty($cfg['block_links']) && ga_has_link($m)) {  
    ga_apply_punishment($cfg, $chatId, $userId, $msgId);  
    return;  
  }  
  
  // @bot usernames  
  if (!empty($cfg['block_bots_usernames']) && ga_has_bot_username($m)) {  
    ga_apply_punishment($cfg, $chatId, $userId, $msgId);  
    return;  
  }  
  
  // (o resto do seu sistema de regras / punição continua no seu projeto)  
}