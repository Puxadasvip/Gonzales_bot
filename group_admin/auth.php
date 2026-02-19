<?php
declare(strict_types=1);

/**
 * Permissão:
 * - Dono salvo do grupo (owner_id)
 * - ou BOT_OWNER_ID (admin master)
 */
function ga_user_can_manage(int $groupId, int $userId): bool {
  if (defined('BOT_OWNER_ID') && (int)BOT_OWNER_ID === $userId) return true;

  $cfg = ga_group_get($groupId);
  $owner = (int)($cfg['owner_id'] ?? 0);
  return ($owner !== 0 && $owner === $userId);
}

/**
 * Opcional: ignorar admins no motor de regras
 * Se você quiser checar admin de verdade, faça aqui depois.
 * Por enquanto, só deixa o BOT_OWNER_ID livre.
 */
function ga_is_admin_or_creator(int $chatId, int $userId): bool {
  if (defined('BOT_OWNER_ID') && (int)BOT_OWNER_ID === $userId) return true;
  return false;
}