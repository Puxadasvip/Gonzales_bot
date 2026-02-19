<?php
declare(strict_types=1);

function ga_kb_open_panel(int $chatId): array {
  return [
    'inline_keyboard' => [
      [
        ['text' => '🛠️ Abrir Painel do Grupo', 'callback_data' => "GA_OPEN|$chatId"]
      ]
    ]
  ];
}

function ga_kb_panel(int $chatId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'✅ Ativar',  'callback_data'=>"GA_SET|$chatId|enabled|1"],
        ['text'=>'⛔ Desativar','callback_data'=>"GA_SET|$chatId|enabled|0"],
      ],
      [
        ['text'=>'👋 Boas-vindas', 'callback_data'=>"GA_MENU|$chatId|welcome"],
        ['text'=>'📜 Regras',      'callback_data'=>"GA_MENU|$chatId|rules"],
      ],
      [
        ['text'=>'🧱 Bloqueios',   'callback_data'=>"GA_MENU|$chatId|blocks"],
        ['text'=>'⚖️ Punição',     'callback_data'=>"GA_MENU|$chatId|punish"],
      ],
      [
        ['text'=>'📌 Ver Config',  'callback_data'=>"GA_VIEW|$chatId"],
      ],
    ]
  ];
}

function ga_kb_blocks(int $chatId, array $cfg): array {
  $b = function(string $k, string $label) use ($chatId, $cfg) {
    $on = !empty($cfg[$k]);
    return ['text' => ($on ? "✅ " : "❌ ") . $label, 'callback_data' => "GA_TOGGLE|$chatId|$k"];
  };

  return [
    'inline_keyboard' => [
      [ $b('block_links','Links'), $b('block_bots','Bots') ],
      [ $b('block_photos','Fotos'), $b('block_videos','Vídeos') ],
      [ $b('block_gifs','GIFs'), $b('block_stickers','Stickers') ],
      [ $b('block_voice','Áudios'), $b('block_docs','Arquivos') ],
      [
        ['text'=>'⬅️ Voltar', 'callback_data'=>"GA_BACK|$chatId"]
      ],
    ]
  ];
}

function ga_kb_punish(int $chatId, array $cfg): array {
  $cur = (string)($cfg['punish'] ?? 'delete');
  $mk = function(string $val, string $label) use ($chatId, $cur) {
    $on = ($cur === $val);
    return ['text' => ($on ? "✅ " : "") . $label, 'callback_data' => "GA_SET|$chatId|punish|$val"];
  };

  return [
    'inline_keyboard' => [
      [ $mk('delete','Apagar msg'), $mk('mute5m','Mute 5min') ],
      [ $mk('mute1h','Mute 1h'),   $mk('ban','Banir') ],
      [
        ['text'=>'⬅️ Voltar', 'callback_data'=>"GA_BACK|$chatId"]
      ],
    ]
  ];
}

function ga_kb_simple_back(int $chatId): array {
  return [
    'inline_keyboard' => [
      [
        ['text'=>'⬅️ Voltar', 'callback_data'=>"GA_BACK|$chatId"]
      ]
    ]
  ];
}