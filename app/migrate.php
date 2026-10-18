<?php
declare(strict_types=1);

const SCHEMA_VERSION = 1;
const ADMIN_EMAIL = 'admin@bablito.ru';

function migrate_if_needed(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_meta (id TINYINT PRIMARY KEY, version INT NOT NULL) ENGINE=InnoDB');
    $cur = (int)($pdo->query('SELECT version FROM schema_meta WHERE id=1')->fetchColumn() ?: 0);
    if ($cur >= SCHEMA_VERSION) {
        return;
    }
    $lock = (int)$pdo->query("SELECT GET_LOCK('bablito_migrate', 30)")->fetchColumn();
    if (!$lock) {
        throw new RuntimeException('Could not acquire migration lock');
    }
    try {
        $cur = (int)($pdo->query('SELECT version FROM schema_meta WHERE id=1')->fetchColumn() ?: 0);
        if ($cur < 1) {
            migrate_v1($pdo);
        }
        $pdo->exec('REPLACE INTO schema_meta (id, version) VALUES (1, ' . SCHEMA_VERSION . ')');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bablito_migrate')");
    }
}

function migrate_v1(PDO $pdo): void
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [
"CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL DEFAULT '',
  name VARCHAR(120) NOT NULL DEFAULT '',
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  blocked TINYINT(1) NOT NULL DEFAULT 0,
  about TEXT NULL,
  contact_phone VARCHAR(60) NOT NULL DEFAULT '',
  contact_email VARCHAR(190) NOT NULL DEFAULT '',
  show_name TINYINT(1) NOT NULL DEFAULT 1,
  show_phone TINYINT(1) NOT NULL DEFAULT 0,
  show_email TINYINT(1) NOT NULL DEFAULT 0,
  extra_fields TEXT NULL,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
  rating_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) $t",
"CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, created_at), INDEX (ip, created_at)
) $t",
"CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) $t",
"CREATE TABLE contracts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  category_id INT UNSIGNED NULL,
  location VARCHAR(190) NOT NULL DEFAULT '',
  description TEXT NULL,
  status ENUM('draft','published','active','paused','fulfilled','terminated') NOT NULL DEFAULT 'draft',
  accept_offers TINYINT(1) NOT NULL DEFAULT 1,
  prepay_required TINYINT(1) NOT NULL DEFAULT 0,
  prepaid_at DATETIME NULL,
  deadline DATE NULL,
  contractor_id INT UNSIGNED NULL,
  accepted_offer_id INT UNSIGNED NULL,
  agreed_price DECIMAL(12,2) NULL,
  agreed_days INT UNSIGNED NULL,
  agreed_deadline DATE NULL,
  started_at DATETIME NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status, created_at), INDEX (user_id), INDEX (contractor_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) $t",
"CREATE TABLE todos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  days INT UNSIGNED NOT NULL DEFAULT 1,
  agreed_price DECIMAL(12,2) NULL,
  agreed_days INT UNSIGNED NULL,
  done_at DATETIME NULL,
  accepted_at DATETIME NULL,
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) $t",
"CREATE TABLE offers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  days INT UNSIGNED NOT NULL,
  notes TEXT NULL,
  status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (contract_id, user_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) $t",
"CREATE TABLE offer_todos (
  offer_id INT UNSIGNED NOT NULL,
  todo_id INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  days INT UNSIGNED NOT NULL,
  PRIMARY KEY (offer_id, todo_id),
  FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
  FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE
) $t",
"CREATE TABLE comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  scope ENUM('public','offer','work','todo') NOT NULL,
  offer_id INT UNSIGNED NULL,
  todo_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (contract_id, scope), INDEX (offer_id), INDEX (todo_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) $t",
"CREATE TABLE files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('image','file') NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  by_contractor TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) $t",
"CREATE TABLE reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  reviewer_id INT UNSIGNED NOT NULL,
  reviewed_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  body TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (contract_id, reviewer_id),
  INDEX (reviewed_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) $t",
"CREATE TABLE watch_later (
  user_id INT UNSIGNED NOT NULL,
  contract_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, contract_id),
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) $t",
"CREATE TABLE home_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(190) NOT NULL,
  body TEXT NULL,
  color ENUM('green','blue') NOT NULL DEFAULT 'green'
) $t",
"CREATE TABLE site_settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NULL
) $t",
"CREATE TABLE static_pages (
  slug VARCHAR(60) PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  body MEDIUMTEXT NULL
) $t",
    ];
    foreach ($sql as $s) {
        $pdo->exec($s);
    }

    foreach (['Software', 'Design', 'Repair', 'Construction', 'Writing', 'Marketing', 'Admin & Management', 'Other'] as $c) {
        $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$c]);
    }
    $pdo->prepare('INSERT INTO site_settings (k, v) VALUES (?, ?)')->execute(['home_title', 'Control Your Missions and Money']);
    $blk = $pdo->prepare('INSERT INTO home_blocks (position, title, body, color) VALUES (?, ?, ?, ?)');
    $blk->execute([1, 'Create Custom Contracts', 'Describe the job, split it into steps with a price and a deadline for each one, and publish it for freelancers to make offers.', 'green']);
    $blk->execute([2, 'Call to Action for Freelancers', 'Browse open contracts, offer your own price and terms for every step, and get paid step by step as the client accepts your work.', 'blue']);
    $pg = $pdo->prepare('INSERT INTO static_pages (slug, title, body) VALUES (?, ?, ?)');
    foreach (['about' => 'About Site', 'privacy' => 'Privacy Policy', 'disclaimer' => 'Disclaimer', 'offer' => 'Public Offer'] as $slug => $title) {
        $pg->execute([$slug, $title, "Text of \"$title\" will be published here."]);
    }

    // Site admin: no password until the one-time setup link is used.
    $pdo->prepare("INSERT INTO users (email, pass_hash, name, role) VALUES (?, '', 'Administrator', 'admin')")->execute([ADMIN_EMAIL]);
    file_put_contents(ROOT . '/storage/admin_setup_token.txt', bin2hex(random_bytes(24)) . "\n");
}
