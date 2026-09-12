<?php
define('AULA_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

do_logout();
redirect('login.php');
