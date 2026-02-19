<?php
declare(strict_types=1);

function ga_cfg_to_text(int $chatId, array $cfg): string {
  $yn = fn($v) => !empty($v) ? '✅' : '❌';

  $t  = "🛠️ <b>Painel do Grupo</b>\n";
  $t .= "🆔 <code>{$chatId}</code>\n\n";
  $t .= "Status: " . ($cfg['enabled'] ? '✅ Ativo' : '⛔ Desativado') . "\n\n";

  $t .= "👋 Boas-vindas: " . $yn($cfg['welcome_enabled']) . "\n";
  $t .= "🔗 Bloquear links: " . $yn($cfg['block_links']) . "\n";
  $t .= "🤖 Bloquear bots: " . $yn($cfg['block_bots']) . "\n";
  $t .= "🖼 Fotos: " . $yn($cfg['block_photos']) . " | 🎥 Vídeos: " . $yn($cfg['block_videos']) . "\n";
  $t .= "🎞 GIFs: " . $yn($cfg['block_gifs']) . " | 🧩 Stickers: " . $yn($cfg['block_stickers']) . "\n";
  $t .= "🎙 Áudio: " . $yn($cfg['block_voice']) . " | 📎 Docs: " . $yn($cfg['block_docs']) . "\n\n";
  $t .= "⚖️ Punição: <b>" . htmlspecialchars((string)$cfg['punish'], ENT_QUOTES, 'UTF-8') . "</b>\n\n";
  $t .= "📜 <b>Regras:</b>\n<pre>" . htmlspecialchars((string)$cfg['rules_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";

  return $t;
}

function ga_open_panel_private(int $userId, int $chatId): void {
  $cfg = ga_group_get($chatId);
  $txt = ga_cfg_to_text($chatId, $cfg);

  tg('sendMessage', [
    'chat_id' => $userId,
    'text' => $txt,
    'parse_mode' => 'HTML',
    'reply_markup' => ga_kb_panel($chatId),
    'disable_web_page_preview' => true,
  ]);
}

function ga_handle_callback(array $cb): bool {
  $fromId = (int)($cb['from']['id'] ?? 0);
  $data   = (string)($cb['data'] ?? '');
  $cbId   = (string)($cb['id'] ?? '');

  if (strpos($data, 'GA_') !== 0) return false;

  // GA_OPEN|chatId
  $parts = explode('|', $data);
  $act = $parts[0] ?? '';

  $chatId = (int)($parts[1] ?? 0);
  if ($chatId === 0) {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Painel inválido.','show_alert'=>true]);
    return true;
  }

  // Checa se ele é admin do grupo (ou dono do bot)
  if (!ga_is_admin($chatId, $fromId)) {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'⚠️ Só admins do grupo podem configurar.','show_alert'=>true]);
    return true;
  }

  // Sempre responde callback pra não ficar carregando
  tg('answerCallbackQuery', ['callback_query_id'=>$cbId,'text'=>'','show_alert'=>false]);

  if ($act === 'GA_OPEN') {
    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  if ($act === 'GA_BACK') {
    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  if ($act === 'GA_VIEW') {
    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  if ($act === 'GA_TOGGLE') {
    $key = (string)($parts[2] ?? '');
    if ($key !== '') {
      $cfg = ga_group_get($chatId);
      $cfg[$key] = empty($cfg[$key]) ? true : false;
      ga_group_set($chatId, $cfg);
    }
    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  if ($act === 'GA_SET') {
    $key = (string)($parts[2] ?? '');
    $val = (string)($parts[3] ?? '');

    if ($key !== '') {
      if ($val === '1' || $val === '0') $val = ($val === '1');
      ga_group_patch($chatId, [$key => $val]);
    }
    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  if ($act === 'GA_MENU') {
    $sub = (string)($parts[2] ?? '');
    $cfg = ga_group_get($chatId);

    if ($sub === 'blocks') {
      tg('sendMessage', [
        'chat_id' => $fromId,
        'text' => "🧱 <b>Bloqueios</b>\nToque para ativar/desativar:",
        'parse_mode' => 'HTML',
        'reply_markup' => ga_kb_blocks($chatId, $cfg),
      ]);
      return true;
    }

    if ($sub === 'punish') {
      tg('sendMessage', [
        'chat_id' => $fromId,
        'text' => "⚖️ <b>Punição ao quebrar regra</b>\nEscolha a ação:",
        'parse_mode' => 'HTML',
        'reply_markup' => ga_kb_punish($chatId, $cfg),
      ]);
      return true;
    }

    if ($sub === 'welcome') {
      $txt = "👋 <b>Boas-vindas</b>\n\n"
           . "Status: " . (!empty($cfg['welcome_enabled']) ? "✅ Ativo" : "❌ Desativado") . "\n\n"
           . "Para ligar/desligar use os botões abaixo.\n"
           . "Obs: editar texto avançado eu adiciono na próxima etapa.";
      tg('sendMessage', [
        'chat_id' => $fromId,
        'text' => $txt,
        'parse_mode' => 'HTML',
        'reply_markup' => [
          'inline_keyboard' => [
            [
              ['text'=>'✅ Ligar','callback_data'=>"GA_SET|$chatId|welcome_enabled|1"],
              ['text'=>'❌ Desligar','callback_data'=>"GA_SET|$chatId|welcome_enabled|0"],
            ],
            [
              ['text'=>'⬅️ Voltar','callback_data'=>"GA_BACK|$chatId"]
            ]
          ]
        ]
      ]);
      return true;
    }

    if ($sub === 'rules') {
      $txt = "📜 <b>Regras do Grupo</b>\n\n"
           . "Atualmente:\n<pre>" . htmlspecialchars((string)$cfg['rules_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>\n\n"
           . "Obs: editar regras por mensagem eu adiciono na próxima etapa.";
      tg('sendMessage', [
        'chat_id' => $fromId,
        'text' => $txt,
        'parse_mode' => 'HTML',
        'reply_markup' => ga_kb_simple_back($chatId),
      ]);
      return true;
    }

    ga_open_panel_private($fromId, $chatId);
    return true;
  }

  return true;
}