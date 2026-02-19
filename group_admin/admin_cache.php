<?php
declare(strict_types=1);

function ga_admin_cache_read(): array {
  $file = GROUP_ADMIN_CACHE_FILE;
  if (!file_exists($file)) return [];
  $raw = @file_get_contents($file);
  $data = json_decode($raw ?: '{}', true);
  return is_array($data) ? $data : [];
}

function ga_admin_cache_write(array $data): void {
  @file_put_contents(GROUP_ADMIN_CACHE_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Verifica se user é admin no grupo usando cache.
 * Usa tg() do seu bot (já existe).
 */
function ga_is_admin(int $chatId, int $userId): bool {
  // Dono do bot sempre pode mexer
  if ($userId === BOT_OWNER_ID) return true;

  $cache = ga_admin_cache_read();
  $key = $chatId . ':' . $userId;
  $now = time();

  if (isset($cache[$key]) && ($cache[$key]['exp'] ?? 0) > $now) {
    return (bool)($cache[$key]['is'] ?? false);
  }

  // Checa via Telegram
  $r = tg('getChatMember', [
    'chat_id' => $chatId,
    'user_id' => $userId,
  ]);

  $is = false;
  if (($r['ok'] ?? false) === true) {
    $status = (string)($r['result']['status'] ?? '');
    $is = in_array($status, ['administrator','creator'], true);
  }

  $cache[$key] = ['is' => $is, 'exp' => $now + GROUP_ADMIN_CACHE_TTL];
  ga_admin_cache_write($cache);

  return $is;
}