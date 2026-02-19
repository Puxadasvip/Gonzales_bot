<?php
declare(strict_types=1);

function ga_render_welcome(string $tpl, int $userId, string $firstName, int $chatId, array $cfg): string {
  $user = mention_html($userId, $firstName);
  $rules = htmlspecialchars((string)($cfg['rules_text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $out = str_replace(
    ['{user}', '{rules}', '{chat_id}'],
    [$user, nl2br($rules), (string)$chatId],
    $tpl
  );

  return $out;
}