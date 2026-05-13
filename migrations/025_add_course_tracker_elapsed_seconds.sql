-- Add elapsed_seconds to course_tracker so timer progress is stored in DB (persists across devices)
ALTER TABLE course_tracker ADD COLUMN elapsed_seconds INT NOT NULL DEFAULT 0;
