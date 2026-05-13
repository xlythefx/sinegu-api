-- Add course_level to community_posts: general or first/second/third/fourth/fifth/sixth
-- general = Community feed; course levels = Course discussion (author's level at post time)
ALTER TABLE community_posts
ADD COLUMN course_level VARCHAR(20) NOT NULL DEFAULT 'general'
COMMENT 'general for community feed, or first/second/third/fourth/fifth/sixth for course discussion'
AFTER post_type;
