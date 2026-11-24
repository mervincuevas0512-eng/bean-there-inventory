<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/notifications.php';

$errors = [];
$emailTaken = false;
$values = [
  'first_name'  => trim((string)($_POST['first_name'] ?? '')),
  'middle_name' => trim((string)($_POST['middle_name'] ?? '')),
  'last_name'   => trim((string)($_POST['last_name'] ?? '')),
  'suffix'      => trim((string)($_POST['suffix'] ?? '')),
  'email'       => strtolower(trim((string)($_POST['email'] ?? ''))),
];
try {
  $server = db(false);
  $server->exec('CREATE DATABASE IF NOT EXISTS '.DB_NAME.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  $database = db();
  $schema = file_get_contents(__DIR__.'/database/schema.sql');
  foreach (explode(';', $schema) as $statement) {
    if (trim($statement) !== '') $database->exec($statement);
  }
  // Existing installations can receive the optional structured name fields without losing data.
  foreach ([
    'first_name'  => 'VARCHAR(60) NULL',
    'middle_name' => 'VARCHAR(60) NULL',
    'last_name'   => 'VARCHAR(60) NULL',
    'suffix'      => 'VARCHAR(15) NULL',
  ] as $column => $definition) {
    $check = $database->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
    $check->execute([$column]);
    if ((int)$check->fetchColumn() === 0) $database->exec("ALTER TABLE users ADD COLUMN $column $definition");
  }
  if ((int)$database->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() > 0)
    redirect('index.php?page=login');
} catch (Throwable $error) {
  error_log('Owner setup error: '.$error->getMessage());
  $errors[] = 'Database setup failed. Check MySQL and app/config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
  require_csrf();
  $first = preg_replace('/\s+/u', ' ', $values['first_name']) ?? '';
  $middle = preg_replace('/\s+/u', ' ', $values['middle_name']) ?? '';
  $last = preg_replace('/\s+/u', ' ', $values['last_name']) ?? '';
  $suffix = preg_replace('/\s+/u', ' ', $values['suffix']) ?? '';
  $email = $values['email'];
  $password = (string)($_POST['password'] ?? '');
  $confirmation = (string)($_POST['confirm_password'] ?? '');

  foreach (['First name' => [$first, 60], 'Last name' => [$last, 60]] as $label => [$name, $max]) {
    if (mb_strlen($name) < 1 || mb_strlen($name) > $max || preg_match('/[\p{C}<>]/u', $name))
      $errors[] = "$label is required and must not exceed $max characters.";
  }
  if (mb_strlen($middle) > 60 || preg_match('/[\p{C}<>]/u', $middle))
    $errors[] = 'Middle name must not exceed 60 characters.';
  if (mb_strlen($suffix) > 15 || preg_match('/[\p{C}<>]/u', $suffix))
    $errors[] = 'Suffix must not exceed 15 characters.';
  $fullName = implode(' ', array_filter([$first, $middle, $last, $suffix], static fn($s) => $s !== ''));
  if (mb_strlen($fullName) > 120) $errors[] = 'Combined name is too long.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    $errors[] = 'Enter a valid email address.';
  } elseif (one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
    // The unique users.email index also blocks duplicates during concurrent submissions.
    $emailTaken = true;
    $errors[] = 'This email address is already registered. Use a different email.';
  }
  if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))
    $errors[] = 'Password needs at least 8 characters, 1 uppercase letter, and 1 number.';
  if ($password !== $confirmation)
    $errors[] = 'Password and confirmation password do not match.';

  if (!$errors) {
    try {
      // Prevent two simultaneous setup requests from creating separate Owner accounts.
      $lock = (int)(one("SELECT GET_LOCK('bean_there_owner_setup', 5) AS acquired")['acquired'] ?? 0);
      if ($lock !== 1) throw new RuntimeException('Unable to obtain setup lock.');
      try {
        if ((int)(one("SELECT COUNT(*) AS total FROM users WHERE role='owner'")['total'] ?? 0) > 0)
          throw new RuntimeException('Owner account already exists.');
        query(
          "INSERT INTO users (full_name,first_name,middle_name,last_name,suffix,email,password_hash,role)
           VALUES (?,?,?,?,?,?,?,'owner')",
          [$fullName, $first, $middle !== '' ? $middle : null, $last,
           $suffix !== '' ? $suffix : null, $email, password_hash($password, PASSWORD_DEFAULT)]
        );
      } finally {
        query("SELECT RELEASE_LOCK('bean_there_owner_setup')");
      }
      foreach (['Coffee beans', 'Milk & dairy', 'Syrups & sauces', 'Tea & powders',
                'Cups & packaging', 'Food ingredients', 'Other supplies'] as $category)
        query('INSERT IGNORE INTO categories (name) VALUES (?)', [$category]);
      flash('success', 'Owner account created successfully. Sign in to continue.');
      redirect('index.php?page=login');
    } catch (Throwable $error) {
      error_log('Owner registration error: '.$error->getMessage());
      if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
        $emailTaken = true;
        $errors[] = 'This email address is already registered. Use a different email.';
      } else {
        $errors[] = 'Could not create the owner account. Please try again.';
      }
    }
  }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07392f">
  <title>Create Owner Account | Bean There Cafe</title>
  <link rel="stylesheet" href="<?=e(base_url('assets/style.css'))?>">
  <script defer src="<?=e(base_url('assets/app.js'))?>"></script>
</head>
<body class="auth-body setup-body">
  <?php if ($errors) echo notification_html('error', $errors); ?>
  <main class="auth-panel setup-panel">
    <div class="brand-mark brand-mark-large" role="img" aria-label="Bean There Cafe logo"><span>BEAN<br>THERE</span></div>
    <p class="eyebrow">ONE-TIME SETUP</p>
    <h1>Create Owner Account</h1>
    <p class="muted">Set up the account that will manage your inventory.</p>

    <form method="post" class="form-stack setup-form" data-owner-registration>
      <?=csrf_field()?>
      <div class="name-fields">
        <label>First Name <input name="first_name" required maxlength="60" autocomplete="given-name"
            value="<?=e($values['first_name'])?>" placeholder="First name"></label>
        <label><span>Middle Name <span class="optional">(optional)</span></span>
          <input name="middle_name" maxlength="60" autocomplete="additional-name"
            value="<?=e($values['middle_name'])?>" placeholder="Middle name"></label>
        <label>Last Name <input name="last_name" required maxlength="60" autocomplete="family-name"
            value="<?=e($values['last_name'])?>" placeholder="Last name"></label>
        <label><span>Suffix <span class="optional">(optional)</span></span>
          <input name="suffix" maxlength="15" autocomplete="honorific-suffix"
            value="<?=e($values['suffix'])?>" placeholder="Jr., Sr., III"></label>
      </div>

      <label>Email Address
        <input name="email" required type="email" maxlength="190" autocomplete="email"
          value="<?=e($values['email'])?>" placeholder="you@example.com"
          <?= $emailTaken ? 'aria-invalid="true" aria-describedby="email-feedback"' : '' ?>>
        <?php if ($emailTaken): ?><span id="email-feedback" class="sr-only">This email address is already registered.</span><?php endif; ?>
      </label>

      <div class="password-group">
        <label for="owner-password">Password</label>
        <div class="password-field">
          <input id="owner-password" name="password" type="password" required minlength="8"
            autocomplete="new-password" aria-describedby="password-requirements"
            placeholder="Create a password">
          <button type="button" class="show-pass" data-toggle-password="owner-password" aria-label="Show password">Show</button>
        </div>
        <ul id="password-requirements" class="password-requirements" aria-live="polite" hidden>
          <li data-password-rule="length">At least 8 characters</li>
          <li data-password-rule="uppercase">At least 1 uppercase letter (A–Z)</li>
          <li data-password-rule="number">At least 1 number (0–9)</li>
        </ul>
      </div>

      <div class="password-group">
        <label for="owner-password-confirm">Confirm Password</label>
        <div class="password-field">
          <input id="owner-password-confirm" name="confirm_password" type="password" required minlength="8"
            autocomplete="new-password" aria-describedby="password-match-feedback"
            placeholder="Re-enter your password">
          <button type="button" class="show-pass" data-toggle-password="owner-password-confirm" aria-label="Show confirmation password">Show</button>
        </div>
        <p id="password-match-feedback" class="field-error" role="status" hidden>Passwords do not match.</p>
      </div>

      <button class="btn btn-primary btn-full">Create Owner Account</button>
    </form>
    <p class="auth-foot">For authorized cafe personnel only</p>
  </main>
</body>
</html>
