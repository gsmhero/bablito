<?php
declare(strict_types=1);

if (embed_platform() !== '') {
    // inside the VK / OK frame the person only leaves the account: the frame stays and the login page opens
    unset($_SESSION['uid']);
    session_regenerate_id(true);
    redirect('/login');
}
$_SESSION = [];
session_destroy();
redirect('/');
