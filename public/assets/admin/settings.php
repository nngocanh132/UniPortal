<?php
/**
 * UniPortal – Settings redirect to config.php
 * The admin nav links 'settings' to config.php which has the full settings UI.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: ' . app_url('assets/student/dashboard.php')); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: ' . app_url('assets/lecturer/dashboard.php')); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: ' . app_url('index.php')); exit; }

// Redirect to config.php which has the full settings implementation
header('Location: config.php');
exit;
