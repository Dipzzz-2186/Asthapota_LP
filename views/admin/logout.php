<?php
require_once __DIR__ . '/../../app/helpers.php';
ensure_session();
$_SESSION = [];
session_destroy();
redirect('/');

