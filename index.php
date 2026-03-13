<?php
require_once 'functions.php';

if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
} else {
    header('Location: dashboard.php');
    exit;
}
?>
