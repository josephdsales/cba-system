<?php
// Shared page shell. Expects $title and $user (nullable).
if (!isset($title)) $title = APP_NAME;
if (!array_key_exists('user', get_defined_vars())) $user = current_user();
$flash_msg = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
<script defer src="assets/app.js"></script>
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= $user ? 'dashboard.php' : 'index.php' ?>">📝 <?= e(APP_NAME) ?></a>
    <nav class="nav">
      <?php if ($user): ?>
        <span class="hello"><?= e($user['fullname']) ?> <small>(<?= e($user['role']) ?>)</small></span>
        <a href="dashboard.php">Home</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="admin_teachers.php">Teachers</a>
          <a href="admin_students.php">Students</a>
          <a href="admin_sections.php">Sections</a>
          <a href="admin_reports.php">Reports</a>
        <?php elseif ($user['role'] === 'teacher'): ?>
          <a href="teacher_exams.php">Exams</a>
          <a href="teacher_sections.php">Sections</a>
          <a href="teacher_results.php">Results</a>
        <?php else: ?>
          <a href="student_exams.php">Exams</a>
          <a href="student_scores.php">My Scores</a>
        <?php endif; ?>
        <a href="logout.php">Logout</a>
      <?php else: ?>
        <a href="index.php">Login</a>
        <a href="register.php" class="btn small">Register</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
  <?php if ($flash_msg): ?><div class="flash"><?= e($flash_msg) ?></div><?php endif; ?>
  <h1 class="page-title"><?= e($title) ?></h1>
