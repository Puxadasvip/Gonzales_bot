<?php
declare(strict_types=1);

/**
 * painel.php (ATUALIZADO)
 * - Status do painel
 * - Teclado principal em layout 2/1/2/1/...
 * - Stickers separado
 * - Foto/Vídeo/GIF/Documento separado
 * - Anti-Palavrão abre menu BADWORDS_MENU
 */

function ga_status_text(int $groupId): string {
  $cfg = ga_group_get($groupId);

  $title = (string)($cfg['title'] ?? '');

  $blockLinks = !empty($cfg['block_links']);
  $blockBots  = !empty($cfg['block_bots_usernames']);
  $welcomeOn  = !empty($cfg['welcome_enabled']);
  $spamOn     = !empty($cfg['antispam_enabled']);

  // filtros
  $antiPornOn = !empty($cfg['anti_porn_enabled']);
  $antiBadOn  = !empty($cfg['anti_badwords_enabled']);

  // mídias separadas
  $stickersOn   = !empty($cfg['block_stickers_enabled']);
  $photoVideoOn = !empty($cfg['block_photovideo_enabled']);

  $punishMode = (string)($cfg['punish_mode'] ?? 'delete');
  $punishSec  = (int)($cfg['punish_seconds'] ?? 60);

  $text  = "⚙️ <b>Painel do Grupo</b>\n";
  $text .= "Chat ID: <code>{$groupId}</code>\n";

  if ($title !== '') {
    $text .= "Título: <b>" . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n";
  }

  $text .= "\n<b>Regras</b>\n";
  $text .= "• Links: <b>" . ($blockLinks ? '✅' : '❌') . "</b>\n";
  $text .= "• Bots: <b>" . ($blockBots ? '✅' : '❌') . "</b>\n";

  $text .= "\n<b>Boas-vindas</b>\n";
  $text .= "• Ativo: <b>" . ($welcomeOn ? '✅' : '❌') . "</b>\n";

  $text .= "\n<b>Anti-spam</b>\n";
  $text .= "• Ativo: <b>" . ($spamOn ? '✅' : '❌') . "</b>\n";

  $text .= "\n<b>Filtros</b>\n";
  $text .= "• 🔞 Anti-Porn (texto): <b>" . ($antiPornOn ? '✅' : '❌') . "</b>\n";
  $text .= "• 🤬 Anti-Palavrão: <b>" . ($antiBadOn ? '✅' : '❌') . "</b>\n";

  $text .= "\n<b>Mídias</b>\n";
  $text .= "• 🧩 Stickers: <b>" . ($stickersOn ? '✅' : '❌') . "</b>\n";
  $text .= "• 🖼 Foto/Vídeo/GIF/Doc: <b>" . ($photoVideoOn ? '✅' : '❌') . "</b>\n";

  $text .= "\n<b>Punição</b>\n";
  if ($punishMode === 'ban') {
    $text .= "• Modo: 🚫 <b>Banir usuário</b>\n";
  } elseif ($punishMode === 'mute') {
    $text .= "• Modo: 🔇 <b>Mutar usuário</b>\n";
  } else {
    $text .= "• Modo: 🧹 <b>Apagar mensagem</b>\n";
  }
  $text .= "• Tempo: <b>{$punishSec}s</b>\n";

  return $text;
}

/**
 * Teclado principal do painel do grupo
 * Layout: 2 / 1 / 2 / 1 / 2 / 1...
 */
function ga_kb_main(int $groupId): array {
  $cfg = ga_group_get($groupId);

  $blockLinks = !empty($cfg['block_links']);
  $blockBots  = !empty($cfg['block_bots_usernames']);
  $welcomeOn  = !empty($cfg['welcome_enabled']);
  $spamOn     = !empty($cfg['antispam_enabled']);

  $antiPornOn = !empty($cfg['anti_porn_enabled']);
  $antiBadOn  = !empty($cfg['anti_badwords_enabled']);

  $stickersOn   = !empty($cfg['block_stickers_enabled']);
  $photoVideoOn = !empty($cfg['block_photovideo_enabled']);

  return [
    'inline_keyboard' => [

      // 2
      [
        [
          'text' => '🔗 Links: ' . ($blockLinks ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_LINKS|{$groupId}"
        ],
        [
          'text' => '🤖 Bots: ' . ($blockBots ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_BOTUSER|{$groupId}"
        ],
      ],

      // 1
      [
        [
          'text' => '👋 Boas-vindas: ' . ($welcomeOn ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_WELCOME|{$groupId}"
        ],
      ],

      // 2
      [
        [
          'text' => '✍️ Definir texto',
          'callback_data' => "GA|SET_WELCOME|{$groupId}"
        ],
        [
          'text' => '🔘 Botões boas-vindas',
          'callback_data' => "GA|WBTN_MENU|{$groupId}"
        ],
      ],

      // 1
      [
        [
          'text' => '🛡 Anti-spam: ' . ($spamOn ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_ANTISPAM|{$groupId}"
        ],
      ],

      // 2
      [
        [
          'text' => '🔞 Anti-Porn (texto): ' . ($antiPornOn ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_ANTIPORN|{$groupId}"
        ],
        [
          'text' => '🤬 Anti-Palavrão: ' . ($antiBadOn ? '✅' : '❌'),
          'callback_data' => "GA|BADWORDS_MENU|{$groupId}"
        ],
      ],

      // 1
      [
        [
          'text' => '🧩 Stickers: ' . ($stickersOn ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_BLOCK_STICKERS|{$groupId}"
        ],
      ],

      // 2
      [
        [
          'text' => '🖼 Foto/Vídeo: ' . ($photoVideoOn ? '✅' : '❌'),
          'callback_data' => "GA|TOGGLE_BLOCK_PV|{$groupId}"
        ],
        [
          'text' => '🚫 Punição',
          'callback_data' => "GA|CYCLE_PUNISH|{$groupId}"
        ],
      ],

      // 1
      [
        [
          'text' => '⏱ Tempo punição',
          'callback_data' => "GA|PUNISH_TIME|{$groupId}"
        ],
      ],

      // 2 (fecha padrão bonito)
      [
        [
          'text' => '🔁 Trocar grupo',
          'callback_data' => "GA|GROUPS_MENU|0"
        ],
        [
          'text' => '🔄 Atualizar',
          'callback_data' => "GA|OPEN_GROUP|{$groupId}"
        ],
      ],
    ]
  ];
}