<?php
declare(strict_types=1);

// Proxy for the wall photo of a contract (VK Mini App): the browser cannot post to VK's upload server itself, so the
// picture it drew comes here and is passed on. Only VK's own upload hosts are accepted as the target.
header('Content-Type: application/json; charset=utf-8');
$u = user();
$url = (string)($_POST['upload_url'] ?? '');
$host = strtolower((string)parse_url($url, PHP_URL_HOST));
$f = $_FILES['photo'] ?? null;
if (!$u || embed_platform() !== 'vk' || !preg_match('/^(?:[a-z0-9-]+\.)+(?:vk\.com|vk\.ru|userapi\.com|vkuser\.net)$/', $host) || strncmp($url, 'https://', 8) !== 0
    || !$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024 || !is_uploaded_file($f['tmp_name'])
    || (@getimagesize($f['tmp_name'])['mime'] ?? '') !== 'image/png') {
    http_response_code(400);
    echo '{"error":"bad request"}';
    exit;
}
$bnd = '----bbl' . bin2hex(random_bytes(8));
$body = "--$bnd\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"contract.png\"\r\nContent-Type: image/png\r\n\r\n"
    . file_get_contents($f['tmp_name']) . "\r\n--$bnd--\r\n";
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$bnd\r\n", 'content' => $body, 'timeout' => 20, 'ignore_errors' => true]]);
$res = @file_get_contents($url, false, $ctx);
$j = $res === false ? null : json_decode($res, true);
if (!is_array($j) || empty($j['photo'])) {
    http_response_code(502);
    echo '{"error":"upload failed"}';
    exit;
}
echo json_encode(['server' => $j['server'] ?? null, 'photo' => $j['photo'], 'hash' => $j['hash'] ?? null]);
