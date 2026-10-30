<?php
declare(strict_types=1);

// The platform gives the page the person's name; it is stored once, only while the profile name is still empty.
$u = require_login();
if (embed_platform() === '') {
    forbidden();
}
$name = trim(mb_substr(strip_tags((string)($_POST['name'] ?? '')), 0, 120));
if ($name !== '' && trim((string)$u['name']) === '') {
    q('UPDATE users SET name = ? WHERE id = ?', [$name, $u['id']]);
}
http_response_code(204);
exit;
