<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// waitlist-handler.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['fullname'] ?? '';
    $email = $_POST['email'] ?? '';
    $country = $_POST['country'] ?? '';
    $wallet = $_POST['primary_wallet'] ?? '';
    
    // Save to database or CSV or email
    // For now, just redirect with success
    header('Location: index.php?waitlist=success');
    exit;
}
?>
