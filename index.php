<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    ['GET',  '#^/$#',                              'home'],
    ['GET',  '#^/contracts$#',                     'contracts'],
    ['GET',  '#^/contract/new$#',                  'contract_edit'],
    ['POST', '#^/contract/new$#',                  'contract_edit'],
    ['GET',  '#^/contract/(\d+)/edit$#',           'contract_edit'],
    ['POST', '#^/contract/(\d+)/edit$#',           'contract_edit'],
    ['GET',  '#^/contract/(\d+)$#',                'contract_view'],
    ['POST', '#^/contract/(\d+)/(offer|comment|upload|pause|fire|prepay|delete|watch|timer)$#', 'contract_action'],
    ['POST', '#^/todo/(\d+)/(done|accept|comment)$#', 'todo_action'],
    ['GET',  '#^/offer/(\d+)$#',                   'offer_view'],
    ['POST', '#^/offer/(\d+)/(accept|reject|delete|edit|comment)$#', 'offer_action'],
    ['GET',  '#^/my$#',                            'my'],
    ['GET',  '#^/watchlater$#',                    'watchlater'],
    ['GET',  '#^/profile$#',                       'profile_edit'],
    ['POST', '#^/profile$#',                       'profile_edit'],
    ['GET',  '#^/user/(\d+)$#',                    'user_view'],
    ['POST', '#^/user/(\d+)/review$#',             'review'],
    ['GET',  '#^/login$#',                         'login'],
    ['POST', '#^/login$#',                         'login'],
    ['GET',  '#^/register$#',                      'register'],
    ['POST', '#^/register$#',                      'register'],
    ['GET',  '#^/setup-admin$#',                   'setup_admin'],
    ['POST', '#^/setup-admin$#',                   'setup_admin'],
    ['POST', '#^/logout$#',                        'logout'],
    ['GET',  '#^/page/([a-z0-9-]+)$#',             'static_page'],
    ['GET',  '#^/admin(?:/(users|contracts|home|pages))?$#', 'admin'],
    ['POST', '#^/admin/(users|contracts|home|pages)$#',     'admin'],
];

foreach ($routes as [$m, $re, $page]) {
    if ($m === $method && preg_match($re, $path, $match)) {
        array_shift($match);
        $args = $match;
        if ($method === 'POST') {
            csrf_check();
        }
        require __DIR__ . '/app/pages/' . $page . '.php';
        exit;
    }
}
not_found();
