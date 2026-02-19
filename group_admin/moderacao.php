<?php
declare(strict_types=1);

function ga_msg_has_link(array $m): bool {
  $text = (string)($m['text'] ?? $m['caption'] ?? '');
  if ($text === '') return false;

  // pega links comuns, t.me, http, www
  return (bool)preg_match('~(https?://|www\.|t\.me/|telegram\.me/)~i', $text);
}

function ga_msg_is_from_bot(array $m): bool {
  $from = (array)($m['from'] ?? []);
  return (bool)($from['is_bot'] ?? false);
}

function ga_apply_punish(int $chatId, int $userId, string $punish): void {
  if ($punish === 'delete') return;

  if ($punish === 'mute5m' || $punish === 'mute1h') {
    $seconds = ($punish === 'mute5m') ? 300 : 3600;

    tg('restrictChatMember', [
      'chat_id' => $chatId,
      'user_id' => $userId,
      'until_date' => time() + $seconds,
      'permissions' => [
        'can_send_messages' => false,
        'can_send_audios' => false,
        'can_send_documents' => false,
        'can_send_photos' => false,
        'can_send_videos' => false,
        'can_send_video_notes' => false,
        'can_send_voice_notes' => false,
        'can_send_polls' => false,
        'can_send_other_messages' => false,
        'can_add_web_page_previews' => false,
        'can_change_info' => false,
        'can_invite_users' => false,
        'can_pin_messages' => false,
      ]
    ]);
    return;
  }

  if ($punish === 'ban') {
    tg('banChatMember', [
      'chat_id' => $chatId,
      'user_id' => $userId,
    ]);
  }
}

function ga_should_delete_for_rule(array $cfg, array $m): bool {
  // bots
  if (!empty($cfg['block_bots']) && ga_msg_is_from_bot($m)) return true;

  // links
  if (!empty($cfg['block_links']) && ga_msg_has_link($m)) return true;

  // tipos
  if (!empty($cfg['block_photos'])   && isset($m['photo'])) return true;
  if (!empty($cfg['block_videos'])   && (isset($m['video']) || isset($m['video_note']))) return true;
  if (!empty($cfg['block_gifs'])     && (isset($m['animation']))) return true;
  if (!empty($cfg['block_stickers']) && isset($m['sticker'])) return true;
  if (!empty($cfg['block_voice'])    && (isset($m['voice']) || isset($m['audio']))) return true;
  if (!empty($cfg['block_docs'])     && isset($m['document'])) return true;

  return false;
}