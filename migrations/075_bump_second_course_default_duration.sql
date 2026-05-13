-- Mind Freedom replaces the prior light "SINE2GUAL meaning" content for the second-course slot.
-- It includes 5 modules + lessons + multiple 7-day journal exercises + reflection inputs per page.
-- Bump default from 360 (6h) to 600 (10h) to give users realistic time to complete.
UPDATE course_level_defaults SET duration_minutes = 600 WHERE level = 'second';
