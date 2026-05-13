-- Store "My notes" in DB so they persist across devices and can be large
ALTER TABLE course_tracker ADD COLUMN notes LONGTEXT NULL;
