-- Office sender (@Veranda_aibot -> Vera /office/v1). НЕ применять на проде без решения:
-- те же ALTER делает MysqlOfficeTaskRepository::ensureOfficeColumns(), но только когда заданы
-- OFFICE_API_URL и OFFICE_SERVICE_TOKEN (с заглушкой-отправителем схема не трогается).
-- Префикс таблиц (Database::t) подставьте при ручном применении.

ALTER TABLE tg_office_tasks
    ADD COLUMN office_task_id VARCHAR(128) NULL,
    ADD COLUMN office_revision BIGINT NULL,
    ADD COLUMN office_delivered_revision BIGINT NULL,
    ADD COLUMN lease_live TINYINT NULL,
    ADD UNIQUE KEY uq_tot_office (office_task_id);

-- tg_office_outbox: next_attempt_at / attempts / last_error уже есть.

CREATE TABLE IF NOT EXISTS tg_office_state (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
