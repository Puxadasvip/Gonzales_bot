<?php
declare(strict_types=1);

if (!defined('GA_DIR')) define('GA_DIR', __DIR__);
if (!defined('GA_DATA_DIR')) define('GA_DATA_DIR', GA_DIR . '/data');

if (!is_dir(GA_DATA_DIR)) { @mkdir(GA_DATA_DIR, 0775, true); }

require_once GA_DIR . '/storage.php';
require_once GA_DIR . '/auth.php';
require_once GA_DIR . '/panel.php';
require_once GA_DIR . '/handlers.php';

/**
 * Telegram manda quando bot é adicionado/promovido.
 * Menu deve ir SOMENTE pro privado do usuário que fez a ação (from.id)
 */
function ga_on_my_chat_member(array $u): void {
  ga_handle_my_chat_member($u);
}

/** Aplica regras / boas-vindas em grupos */
function ga_handle_group_message(array $message): void {
  ga_group_rules_engine($message);
}

/** Captura mensagens no privado (ex.: definir boas-vindas / adicionar botão) */
function ga_handle_private_message(array $message): void {
  ga_private_input_router($message);
}

/** Callbacks do painel */
function ga_handle_callback(array $cb): bool {
  return ga_callback_router($cb);
}