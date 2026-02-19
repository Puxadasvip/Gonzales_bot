<?php
declare(strict_types=1);

const BOT_OWNER_ID = 7505318236;

// Onde salva configs
const GROUPS_DB_FILE = __DIR__ . '/groups.json';

// Defaults do grupo ( COMPLETO E COMPATÍVEL)
const GROUP_DEFAULTS = [

  'enabled' => true,

  // ===== BOAS-VINDAS =====
  'welcome_enabled' => false,
  'welcome_text' =>
    " Bem-vindo {user}!\n\n Regras:\n{rules}",

  'welcome_buttons' => [],
  'welcome_buttons_per_row' => 1,

  // ===== REGRAS =====
  'block_links' => false,
  'block_bots_usernames' => false,
  'block_photos' => false,
  'block_videos' => false,
  'block_gifs' => false,
  'block_stickers' => false,
  'block_voice' => false,
  'block_docs' => false,

  // ===== PUNIÇÃO =====
  // delete | mute | ban
  'punish_mode' => 'delete',
  'punish_seconds' => 300, // 5 minutos

  // ===== AUTO-DELETE =====
  'autodelete_enabled' => false,
  'autodelete_seconds' => 60,

  // ===== TEXTO DE REGRAS =====
  'rules_text' =>
    " Sem links\n Sem spam\n Respeito acima de tudo",
];