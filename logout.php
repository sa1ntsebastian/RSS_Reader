<?php
require __DIR__ . '/auth.php';
auth_logout();
header('Location: login.php');
