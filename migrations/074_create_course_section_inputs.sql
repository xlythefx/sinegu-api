-- Per-section reflection/journal inputs for paginated courses.
-- One row per (uni_id, level, section_id). `inputs` JSON stores arbitrary key/value pairs
-- because Mind Freedom course has heterogeneous prompts (word/emotion/dream + per-page reflections + journal grids).
CREATE TABLE IF NOT EXISTS course_section_inputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uni_id VARCHAR(36) NOT NULL,
  level ENUM('first','second','third','fourth','fifth','sixth') NOT NULL,
  section_id VARCHAR(64) NOT NULL,
  inputs JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_level_section (uni_id, level, section_id),
  INDEX idx_uni_level (uni_id, level)
);
