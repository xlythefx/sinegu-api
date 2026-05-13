-- Per-module progress for paginated courses.
-- One row per (uni_id, level, module_index). Drives module unlock rules:
--   * elapsed_seconds >= 3600 → next module unlocks
--   * 7 days since started_at → next module auto-unlocks
-- Modules are derived client-side from section_id prefix (`m1-...`, `m2-...`).
CREATE TABLE IF NOT EXISTS course_module_progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uni_id VARCHAR(36) NOT NULL,
  level ENUM('first','second','third','fourth','fifth','sixth') NOT NULL,
  module_index INT NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  elapsed_seconds INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_level_module (uni_id, level, module_index),
  INDEX idx_uni_level (uni_id, level)
);
