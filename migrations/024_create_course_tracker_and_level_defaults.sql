-- Course tracker and level defaults
-- Table 1: level default timers (admin-configurable)
CREATE TABLE IF NOT EXISTS course_level_defaults (
  level ENUM('first','second','third','fourth','fifth','sixth') PRIMARY KEY,
  duration_minutes INT NOT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO course_level_defaults (level, duration_minutes) VALUES
  ('first', 60),
  ('second', 360),
  ('third', 480),
  ('fourth', 600),
  ('fifth', 300),
  ('sixth', 720)
ON DUPLICATE KEY UPDATE duration_minutes = VALUES(duration_minutes);

-- Table 2: per-user course attempts and timer
CREATE TABLE IF NOT EXISTS course_tracker (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uni_id VARCHAR(36) NOT NULL,
  level ENUM('first','second','third','fourth','fifth','sixth') NOT NULL,
  status ENUM('in_progress','finished') NOT NULL DEFAULT 'in_progress',
  duration_minutes INT NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  INDEX idx_uni_id (uni_id),
  INDEX idx_uni_level_status (uni_id, level, status)
);
