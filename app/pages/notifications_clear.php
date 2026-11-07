<?php
declare(strict_types=1);

$u = require_login();
$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    q('DELETE FROM notices WHERE id = ? AND user_id = ?', [$id, $u['id']]);
} else {
    q('DELETE FROM notices WHERE user_id = ?', [$u['id']]);
}
redirect('/notifications');
