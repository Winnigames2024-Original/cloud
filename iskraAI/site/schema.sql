-- Схема БД Искры. Безопасна для повторного запуска.
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL UNIQUE,
  pass_hash  VARCHAR(255) NOT NULL,
  nick       VARCHAR(64)  NOT NULL,
  verified   TINYINT(1)   NOT NULL DEFAULT 1,
  api_key    CHAR(48)     NOT NULL UNIQUE,
  role       ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token      CHAR(64) NOT NULL UNIQUE,
  kind       ENUM('verify','reset') NOT NULL,
  expires_at DATETIME NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chats (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  chat_id     CHAR(36) NOT NULL UNIQUE,
  user_id     INT NOT NULL,
  title       VARCHAR(120) NOT NULL DEFAULT 'Новый чат',
  allow_anger TINYINT(1) NOT NULL DEFAULT 0,
  persona     VARCHAR(500) NOT NULL DEFAULT '',
  mode        VARCHAR(16) NOT NULL DEFAULT 'auto',
  model       VARCHAR(16) NOT NULL DEFAULT 'smart',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, updated_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  chat_id    CHAR(36) NOT NULL,
  role       ENUM('user','ai') NOT NULL,
  content    MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, chat_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedback (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT NOT NULL,
  user_id    INT NOT NULL,
  vote       TINYINT NOT NULL,
  correction TEXT NULL,
  status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status),
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limit (
  id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip     VARBINARY(16) NOT NULL,
  action VARCHAR(32) NOT NULL,
  at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, action, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Догоняющие колонки для уже существующих баз (безопасно повторно):
ALTER TABLE chats ADD COLUMN IF NOT EXISTS mode  VARCHAR(16) NOT NULL DEFAULT 'auto';
ALTER TABLE chats ADD COLUMN IF NOT EXISTS model VARCHAR(16) NOT NULL DEFAULT 'smart';
