<?php
$pageTitle='Intern list';$activePage='intern_list.php';require __DIR__.'/../includes/admin_layout.php';
$users=$pdo->query("SELECT first_name,last_name,email,created_at FROM users WHERE pathway='internship' ORDER BY created_at DESC")->fetchAll();
require __DIR__.'/list_view.php';