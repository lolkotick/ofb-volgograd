-- Таблица обращений с сайта ВРОО «ОФБ».
-- Создаётся скриптом api/install.php; этот файл — для ручного запуска
-- через phpMyAdmin, если так удобнее.

CREATE TABLE IF NOT EXISTS submissions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at    DATETIME     NOT NULL,
    topic         VARCHAR(40)  NOT NULL,
    topic_label   VARCHAR(80)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    contact       VARCHAR(190) NOT NULL,
    contact_kind  ENUM('email','phone','other') NOT NULL DEFAULT 'other',
    message       TEXT         NOT NULL,
    consent_version VARCHAR(40) NOT NULL,
    consent_at    DATETIME     NOT NULL,
    ip            VARBINARY(16) DEFAULT NULL,
    user_agent    VARCHAR(255)  DEFAULT NULL,
    status        ENUM('new','in_progress','done','spam') NOT NULL DEFAULT 'new',
    admin_note    TEXT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_status (status),
    KEY idx_ip_created (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
