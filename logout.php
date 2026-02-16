<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
auth_logout();
header('Location: login.php'); exit;
