<?php
declare(strict_types=1);

// Notifications about what happens in a contract: every other participant gets an entry in the
// notification center (bell in the header) and an e-mail with who did what, the comment text
// (attachments are never sent, only the status "Comment has attachments") and a direct link.

const NOTIFY_TAGS = [
    'en' => ['offer' => 'Offer', 'comment' => 'Comment', 'step' => 'Step', 'contract' => 'Contract', 'invoice' => 'Invoice', 'review' => 'Review'],
    'ru' => ['offer' => 'Предложение', 'comment' => 'Комментарий', 'step' => 'Шаг', 'contract' => 'Контракт', 'invoice' => 'Счёт', 'review' => 'Отзыв'],
];

function notify_tag_key(string $kind): string
{
    if (strpos($kind, 'offer') === 0) {
        return 'offer';
    }
    if (substr($kind, -8) === '_comment' || $kind === 'thread_comment') {
        return 'comment';
    }
    if (strpos($kind, 'todo') === 0) {
        return 'step';
    }
    if (strpos($kind, 'invoice') === 0) {
        return 'invoice';
    }
    return $kind === 'review' ? 'review' : 'contract';
}

const NOTIFY_PHRASES = [
    'offer_new'          => ['en' => 'made an offer on your contract', 'ru' => 'сделал(а) предложение по вашему контракту'],
    'offer_edit'         => ['en' => 'changed their offer', 'ru' => 'изменил(а) своё предложение'],
    'offer_withdrawn'    => ['en' => 'withdrew their offer', 'ru' => 'отозвал(а) своё предложение'],
    'offer_comment'      => ['en' => 'wrote a comment on the offer', 'ru' => 'написал(а) комментарий к предложению'],
    'thread_comment'     => ['en' => 'wrote a message about the contract', 'ru' => 'написал(а) сообщение по контракту'],
    'offer_accepted'     => ['en' => 'accepted your offer, the contract is now active', 'ru' => 'принял(а) ваше предложение, контракт стал активным'],
    'offer_rejected'     => ['en' => 'rejected your offer', 'ru' => 'отклонил(а) ваше предложение'],
    'offer_lost'         => ['en' => 'accepted another offer, your offer was closed', 'ru' => 'принял(а) другое предложение, ваше предложение закрыто'],
    'work_comment'       => ['en' => 'posted a comment in the contract', 'ru' => 'написал(а) комментарий в контракте'],
    'todo_comment'       => ['en' => 'commented on a step', 'ru' => 'прокомментировал(а) шаг'],
    'todo_done'          => ['en' => 'marked a step as done', 'ru' => 'отметил(а) шаг выполненным'],
    'todo_undone'        => ['en' => 'took back the "done" mark of a step', 'ru' => 'снял(а) отметку «выполнено» с шага'],
    'todo_accepted'      => ['en' => 'accepted a step', 'ru' => 'принял(а) шаг'],
    'todo_unaccepted'    => ['en' => 'withdrew the acceptance of a step', 'ru' => 'отозвал(а) принятие шага'],
    'contract_fulfilled' => ['en' => 'accepted the last step, the contract is fulfilled', 'ru' => 'принял(а) последний шаг, контракт выполнен'],
    'paused'             => ['en' => 'paused the contract', 'ru' => 'приостановил(а) контракт'],
    'resumed'            => ['en' => 'resumed the contract', 'ru' => 'возобновил(а) контракт'],
    'fired'              => ['en' => 'dismissed you from the contract', 'ru' => 'уволил(а) вас с контракта'],
    'prepaid'            => ['en' => 'marked the prepayment as paid', 'ru' => 'отметил(а) предоплату как оплаченную'],
    'upload'             => ['en' => 'uploaded a file to the contract', 'ru' => 'загрузил(а) файл в контракт'],
    'invoice_paid'       => ['en' => 'sent a payment, please confirm it', 'ru' => 'отправил(а) оплату, подтвердите её'],
    'invoice_confirmed'  => ['en' => 'confirmed your payment', 'ru' => 'подтвердил(а) вашу оплату'],
    'review'             => ['en' => 'left a review about you', 'ru' => 'оставил(а) отзыв о вас'],
];

function site_base_url(): string
{
    $b = rtrim(env('SITE_URL'), '/');
    return $b !== '' ? $b : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'bablito.ru');
}

/** The other participant of a contract (author <-> contractor), or null. */
function other_participant(array $c, int $actorId): ?int
{
    $author = (int)$c['user_id'];
    $contractor = (int)($c['contractor_id'] ?? 0);
    if ($actorId === $author) {
        return $contractor ?: null;
    }
    return $actorId === $contractor ? $author : null;
}

/**
 * Tells other users about an action.
 * $opts: to (user ids), url (site path), comment (['body' => text, 'files' => bool] from log_comment),
 *        step (step name), extra (details line). The acting user never gets his own notification.
 */
function notify(string $kind, array $contract, array $opts): void
{
    try {
        $actor = $opts['actor'] ?? user(); // a payment webhook has no session: it passes the acting user
        if (!$actor || !isset(NOTIFY_PHRASES[$kind])) {
            return;
        }
        $path = (string)($opts['url'] ?? ('/contract/' . (int)$contract['id']));
        $url = site_base_url() . $path;
        $comment = $opts['comment'] ?? null;
        $to = array_unique(array_filter(array_map('intval', (array)($opts['to'] ?? [])), fn($id) => $id > 0 && $id !== (int)$actor['id']));
        foreach ($to as $uid) {
            $r = row('SELECT id, email, lang, notify_email FROM users WHERE id = ? AND blocked = 0', [$uid]);
            if (!$r) {
                continue;
            }
            $ru = user_lang($r) === 'ru';
            $L = $ru ? 'ru' : 'en';
            $who = display_name($actor);
            $what = NOTIFY_PHRASES[$kind][$L];
            $title = (string)$contract['title'];

            $lines = [$who . ' ' . $what . '.', ''];
            $lines[] = ($ru ? 'Контракт: ' : 'Contract: ') . $title;
            if (!empty($opts['step'])) {
                $lines[] = ($ru ? 'Шаг: ' : 'Step: ') . $opts['step'];
            }
            $ex = is_array($opts['extra'] ?? null) ? ($opts['extra'][$L] ?? '') : (string)($opts['extra'] ?? '');
            if ($ex !== '') {
                $lines[] = $ex;
            }
            if ($comment) {
                if ((string)$comment['body'] !== '') {
                    $lines[] = ($ru ? 'Комментарий: ' : 'Comment: ') . $comment['body'];
                }
                if (!empty($comment['files'])) {
                    $lines[] = $ru ? 'К комментарию приложены файлы.' : 'Comment has attachments.';
                }
            }
            $lines[] = '';
            $lines[] = ($ru ? 'Открыть: ' : 'Open: ') . $url;
            $lines[] = '';
            $lines[] = $ru
                ? 'Вы получили это письмо, потому что участвуете в этом контракте. Уведомления по почте можно отключить в профиле.'
                : 'You get this message because you take part in this contract. You can turn e-mail notifications off in your profile.';

            $rowsHtml = [[$ru ? 'Контракт' : 'Contract', $title]];
            if (!empty($opts['step'])) {
                $rowsHtml[] = [$ru ? 'Шаг' : 'Step', (string)$opts['step']];
            }
            if ($ex !== '') {
                $rowsHtml[] = [$ru ? 'Подробности' : 'Details', $ex];
            }
            $html = email_html([
                'lang' => $L,
                'title' => $who . ' ' . $what,
                'preheader' => $who . ' ' . $what . ' - ' . $title,
                'tag' => NOTIFY_TAGS[$L][notify_tag_key($kind)],
                'who' => $who,
                'when' => date('d.m.Y H:i'),
                'headline' => $who . ' ' . $what,
                'rows' => $rowsHtml,
                'comment' => $comment ? ['body' => (string)$comment['body'], 'files' => !empty($comment['files']), 'label' => $ru ? 'Комментарий' : 'Comment', 'files_label' => $ru ? 'К комментарию приложены файлы' : 'Comment has attachments'] : null,
                'button' => $ru ? 'Открыть в bablito' : 'Open in bablito',
                'url' => $url,
                'link_hint' => $ru ? 'Если кнопка не работает, откройте ссылку:' : 'If the button does not work, open this link:',
                'footer' => $ru ? 'Вы получили это письмо, потому что участвуете в этом контракте.' : 'You get this message because you take part in this contract.',
                'settings_label' => $ru ? 'Отключить уведомления по почте' : 'Turn off e-mail notifications',
                'settings_url' => site_base_url() . '/profile',
                'site_url' => site_base_url(),
            ]);

            // short text for the notification center
            $short = $who . ' ' . $what . ': ' . $title;
            if (!empty($opts['step'])) {
                $short .= ' / ' . $opts['step'];
            }
            if ($comment && (string)$comment['body'] !== '') {
                $short .= ' — ' . mb_strimwidth((string)$comment['body'], 0, 140, '…');
            }
            if ($comment && !empty($comment['files'])) {
                $short .= ' (' . ($ru ? 'есть вложения' : 'has attachments') . ')';
            }
            add_notice($uid, $short, $path);

            if ((int)($r['notify_email'] ?? 1) === 1 && $r['email'] !== '') {
                queue_mail((string)$r['email'], 'bablito: ' . $who . ' ' . $what, implode("\n", $lines), $html);
            }
        }
    } catch (Throwable $e) {
        error_log('notify failed: ' . $e);
    }
}

/* ---------- mails are sent after the page has been delivered ---------- */

function queue_mail(string $to, string $subject, string $body, ?string $html = null, array $attachments = []): void
{
    static $registered = false;
    $GLOBALS['mail_queue'][] = [$to, $subject, $body, $html, $attachments];
    if (!$registered) {
        $registered = true;
        register_shutdown_function('flush_mail_queue');
    }
}

function flush_mail_queue(): void
{
    $queue = $GLOBALS['mail_queue'] ?? [];
    if (!$queue) {
        return;
    }
    $GLOBALS['mail_queue'] = [];
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @fastcgi_finish_request();
    }
    foreach ($queue as [$to, $subject, $body, $html, $attachments]) {
        smtp_send($to, $subject, $body, $html, $attachments);
    }
}
