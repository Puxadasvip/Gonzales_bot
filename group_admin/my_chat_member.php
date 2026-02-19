<?php
// group_admin/my_chat_member.php

function ga_on_my_chat_member(array $upd): void {
  $chat = (array)($upd['chat'] ?? []);
  $chatType = (string)($chat['type'] ?? '');
  if ($chatType !== 'group' && $chatType !== 'supergroup') return;

  $chatId = (int)($chat['id'] ?? 0);
  if ($chatId === 0) return;

  $new = (array)($upd['new_chat_member'] ?? []);
  $old = (array)($upd['old_chat_member'] ?? []);

  $newStatus = (string)($new['status'] ?? '');
  $oldStatus = (string)($old['status'] ?? '');

  // quando o bot entra ou é promovido
  $becameActive = in_array($newStatus, ['member','administrator'], true) &&
                  in_array($oldStatus, ['left','kicked'], true);

  $becameAdmin  = ($newStatus === 'administrator' && $oldStatus !== 'administrator');

  if (!$becameActive && !$becameAdmin) return;

  // Manda o painel no PRÓPRIO grupo (não depende do dono ter /start)
  ga_send_group_panel($chatId);
}