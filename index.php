<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
if (auth_user()) redirect('dashboard.php');
redirect('login.php');
