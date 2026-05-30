-- 0004_login_attempts.sql
-- Backing store for login rate limiting / lockout (item #2).

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  INDEX idx_la_lookup (email, ip, attempted_at),
  INDEX idx_la_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
