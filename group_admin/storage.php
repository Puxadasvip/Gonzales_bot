<?php
declare(strict_types=1);

function ga_store_file(): string {
  return GA_DATA_DIR . '/groups.json';
}

function ga_load_all(): array {
  $f = ga_store_file();
  if (!file_exists($f)) return ['groups'=>[], 'pending'=>[]];
  $raw = @file_get_contents($f);
  $j = json_decode($raw ?: '{}', true);
  if (!is_array($j)) $j = [];
  if (!isset($j['groups']) || !is_array($j['groups'])) $j['groups'] = [];
  if (!isset($j['pending']) || !is_array($j['pending'])) $j['pending'] = [];
  return $j;
}

function ga_save_all(array $data): void {
  $f = ga_store_file();
  @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ga_group_get(int $chatId): array {
  $all = ga_load_all();
  $k = (string)$chatId;

  if (!isset($all['groups'][$k])) {
    $all['groups'][$k] = [
      'chat_id' => $chatId,
      'title' => '',
      'owner_id' => 0,

      // regras
      'block_links' => false,
      'block_bots_usernames' => false,

      // punição
      'punish_mode' => 'delete',     // delete | mute | ban
      'punish_seconds' => 300,       // 5 min

      // boas-vindas
      'welcome_enabled' => false,
      'welcome_text' => "👋 Bem-vindo {user}!\n\n{rules}",

      // botões boas-vindas
      'welcome_buttons' => [],
      'welcome_buttons_per_row' => 1,

      // regras texto
      'rules_text' => "🚫 Sem links\n🚫 Sem spam\n✅ Respeito acima de tudo",

      // auto-delete
      'autodelete_enabled' => false,
      'autodelete_seconds' => 60,

      // anti-spam
      'antispam_enabled' => false,
    ];
    ga_save_all($all);
  }

  $g = $all['groups'][$k];

  // defaults/migrações
  if (!isset($g['welcome_buttons']) || !is_array($g['welcome_buttons'])) $g['welcome_buttons'] = [];
  if (!isset($g['welcome_buttons_per_row'])) $g['welcome_buttons_per_row'] = 1;
  $g['welcome_buttons_per_row'] = ((int)$g['welcome_buttons_per_row'] === 2) ? 2 : 1;

  if (!isset($g['rules_text'])) $g['rules_text'] = "🚫 Sem links\n🚫 Sem spam\n✅ Respeito acima de tudo";
  if (!isset($g['welcome_text'])) $g['welcome_text'] = "👋 Bem-vindo {user}!\n\n{rules}";
  if (!isset($g['welcome_enabled'])) $g['welcome_enabled'] = false;
  $g['welcome_enabled'] = (bool)$g['welcome_enabled'];

  if (!isset($g['autodelete_enabled'])) $g['autodelete_enabled'] = false;
  if (!isset($g['autodelete_seconds'])) $g['autodelete_seconds'] = 60;
  $g['autodelete_enabled'] = (bool)$g['autodelete_enabled'];
  $g['autodelete_seconds'] = max(10, (int)$g['autodelete_seconds']);

  if (!isset($g['antispam_enabled'])) $g['antispam_enabled'] = false;
  $g['antispam_enabled'] = (bool)$g['antispam_enabled'];

  // sanitiza botões
  $clean = [];
  foreach ($g['welcome_buttons'] as $b) {
    if (!is_array($b)) continue;

    $type = (string)($b['type'] ?? '');
    $text = trim((string)($b['text'] ?? ''));

    if ($text === '') continue;

    if ($type === 'url') {
      $url = trim((string)($b['url'] ?? ''));
      if ($url === '') continue;
      $clean[] = ['type'=>'url','text'=>$text,'url'=>$url];
    } elseif ($type === 'msg') {
      $msg = trim((string)($b['message'] ?? ''));
      if ($msg === '') continue;
      $clean[] = ['type'=>'msg','text'=>$text,'message'=>$msg];
    }
  }
  $g['welcome_buttons'] = array_slice($clean, 0, 10);

  $all['groups'][$k] = $g;
  ga_save_all($all);

  return $g;
}

function ga_group_set(int $chatId, array $patch): array {
  $all = ga_load_all();
  $k = (string)$chatId;
  $curr = ga_group_get($chatId);

  $new = array_merge($curr, $patch);

  // layout botões
  if (!isset($new['welcome_buttons_per_row'])) $new['welcome_buttons_per_row'] = 1;
  $new['welcome_buttons_per_row'] = ((int)$new['welcome_buttons_per_row'] === 2) ? 2 : 1;

  // auto-delete
  if (!isset($new['autodelete_enabled'])) $new['autodelete_enabled'] = false;
  if (!isset($new['autodelete_seconds'])) $new['autodelete_seconds'] = 60;
  $new['autodelete_enabled'] = (bool)$new['autodelete_enabled'];
  $new['autodelete_seconds'] = max(10, (int)$new['autodelete_seconds']);

  // anti-spam
  if (!isset($new['antispam_enabled'])) $new['antispam_enabled'] = false;
  $new['antispam_enabled'] = (bool)$new['antispam_enabled'];

  // sanitiza botões
  if (!isset($new['welcome_buttons']) || !is_array($new['welcome_buttons'])) $new['welcome_buttons'] = [];
  $clean = [];
  foreach ($new['welcome_buttons'] as $b) {
    if (!is_array($b)) continue;

    $type = (string)($b['type'] ?? '');
    $text = trim((string)($b['text'] ?? ''));

    if ($text === '') continue;

    if ($type === 'url') {
      $url = trim((string)($b['url'] ?? ''));
      if ($url === '') continue;
      $clean[] = ['type'=>'url','text'=>$text,'url'=>$url];
    } elseif ($type === 'msg') {
      $msg = trim((string)($b['message'] ?? ''));
      if ($msg === '') continue;
      $clean[] = ['type'=>'msg','text'=>$text,'message'=>$msg];
    }
  }
  $new['welcome_buttons'] = array_slice($clean, 0, 10);

  $all['groups'][$k] = $new;
  ga_save_all($all);
  return $new;
}

function ga_set_owner(int $chatId, int $ownerId, string $title = ''): void {
  $patch = ['owner_id'=>$ownerId];
  if ($title !== '') $patch['title'] = $title;
  ga_group_set($chatId, $patch);
}

function ga_pending_set(int $userId, array $payload): void {
  $all = ga_load_all();
  $all['pending'][(string)$userId] = $payload;
  ga_save_all($all);
}

function ga_pending_get(int $userId): ?array {
  $all = ga_load_all();
  $k = (string)$userId;
  return isset($all['pending'][$k]) && is_array($all['pending'][$k]) ? $all['pending'][$k] : null;
}

function ga_pending_clear(int $userId): void {
  $all = ga_load_all();
  $k = (string)$userId;
  if (isset($all['pending'][$k])) unset($all['pending'][$k]);
  ga_save_all($all);
}