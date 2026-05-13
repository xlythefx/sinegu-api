-- Add post_type to community_posts: general or course_discussion
ALTER TABLE community_posts
ADD COLUMN post_type ENUM('general','course_discussion') NOT NULL DEFAULT 'general'
COMMENT 'general or course discussion'
AFTER status;
