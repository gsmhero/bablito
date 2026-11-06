<?php
declare(strict_types=1);

// "Send the work log by e-mail": a participant sends the stopwatch log to their own e-mail, the contractor also to the
// client (contract author). When it goes to the author's address the author gets a notification as well.
$u = require_login();
$c = load_contract((int)$args[0]);
if (!is_participant($c)) {
    forbidden();
}
$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$back = '/contract/' . (int)$c['id'];
$reply = function (bool $ok, string $msg) use ($wantsJson, $back): void {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'msg' => $msg]);
        exit;
    }
    flash($msg, $ok ? 'ok' : 'err');
    redirect($back);
};

$to = (string)($_POST['to'] ?? 'me');
$isContractor = (int)($c['contractor_id'] ?? 0) === (int)$u['id'];
if ($to === 'client') {
    if (!$isContractor) {
        forbidden();
    }
    $rcpt = row('SELECT * FROM users WHERE id = ?', [$c['user_id']]);
} else {
    $to = 'me';
    $rcpt = $u;
}
if (!$rcpt || str_ends_with(strtolower((string)$rcpt['email']), '.invalid') || (string)$rcpt['email'] === '') {
    $reply(false, $to === 'me' ? 'Your account has no e-mail address.' : 'The client has no e-mail address.');
}
if (time() - (int)($_SESSION['logmail_t'] ?? 0) < 15) {
    $reply(false, 'Please wait a few seconds before sending again.');
}
$_SESSION['logmail_t'] = time();

$log = rows('SELECT l.*, IF(l.stopped_at IS NULL, TIMESTAMPDIFF(SECOND, l.started_at, NOW()), l.seconds) AS secs FROM timer_log l WHERE l.contract_id = ? ORDER BY l.id', [$c['id']]);
if (!$log) {
    $reply(false, 'Nothing logged yet.');
}
$total = array_sum(array_map(fn($r) => (int)$r['secs'], $log));
$rate = hour_rate($c);
$sym = cur_symbol($c);
$hms = fn(int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$cost = fn(int $s): string => $rate === null ? '' : ' / ' . $sym . ' ' . money2($s / 3600 * $rate);

$ru = user_lang($rcpt) === 'ru';
$site = site_base_url();
$url = $site . '/contract/' . (int)$c['id'] . '/timelog';
$subject = ($ru ? 'Журнал работы: ' : 'Work log: ') . $c['title'];
$rowsHtml = [];
$lines = [$subject, ''];
foreach (array_slice($log, -200) as $r) {
    $task = $r['todo_name'] !== '' ? $r['todo_name'] : '-';
    $when = fdatetime($r['started_at']);
    $val = $hms((int)$r['secs']) . $cost((int)$r['secs']);
    $rowsHtml[] = [$when . ' - ' . $task, $val];
    $lines[] = $when . '  ' . $task . '  ' . $val;
}
$totalLabel = $ru ? 'Итого' : 'Total';
$rowsHtml[] = [$totalLabel, $hms($total) . $cost($total)];
$lines[] = '';
$lines[] = $totalLabel . ': ' . $hms($total) . $cost($total);
$lines[] = '';
$lines[] = ($ru ? 'Открыть: ' : 'Open: ') . $url;

$html = email_html([
    'lang' => $ru ? 'ru' : 'en', 'title' => $subject, 'preheader' => $totalLabel . ': ' . $hms($total),
    'tag' => $ru ? 'Контракт' : 'Contract', 'who' => display_name($u), 'when' => date('d.m.Y H:i'),
    'headline' => $subject, 'rows' => $rowsHtml,
    'button' => $ru ? 'Открыть журнал' : 'Open the log', 'url' => $url,
    'link_hint' => $ru ? 'Если кнопка не работает, откройте ссылку:' : 'If the button does not work, open this link:',
    'footer' => $ru ? 'Вы получили это письмо, потому что вы участник этого контракта.' : 'You get this message because you take part in this contract.',
    'settings_label' => $ru ? 'Настройки профиля' : 'Profile settings',
    'settings_url' => $site . '/profile', 'site_url' => $site,
]);
queue_mail((string)$rcpt['email'], 'bablito: ' . $subject, implode("\n", $lines), $html);
if ($to === 'client') {
    notify('log_shared', $c, ['to' => [(int)$rcpt['id']], 'url' => '/contract/' . (int)$c['id'], 'extra' => ['en' => 'Total: ' . $hms($total), 'ru' => 'Итого: ' . $hms($total)]]);
}
$reply(true, $to === 'client' ? 'The log was sent to the client.' : 'The log was sent to your e-mail.');
