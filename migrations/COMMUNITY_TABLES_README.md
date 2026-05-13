# Community Forum Database Tables

## Overview
This document describes the database tables created for the community forum feature, allowing users to create posts, comment, and reply to comments (one level deep).

## Tables Created

### 1. `community_posts`
Stores forum posts created by users.

**Columns:**
- `id` (INT UNSIGNED, PRIMARY KEY) - Auto-increment post ID
- `uni_id` (VARCHAR(36), FOREIGN KEY) - User ID from `user_credentials` table (post author)
- `title` (VARCHAR(500)) - Post title
- `content` (TEXT) - Post content/body
- `category` (VARCHAR(100), NULLABLE) - Post category (optional for now)
- `interactions_count` (INT UNSIGNED, DEFAULT 0) - Total number of interactions (comments, likes, etc.)
- `created_at` (TIMESTAMP) - Post creation timestamp
- `updated_at` (TIMESTAMP) - Last update timestamp

**Indexes:**
- Primary key on `id`
- Index on `uni_id` (for user queries)
- Index on `category` (for filtering)
- Index on `created_at` (for sorting)
- Index on `interactions_count` (for sorting by popularity)

**Foreign Keys:**
- `uni_id` → `user_credentials.uni_id` (CASCADE DELETE/UPDATE)

---

### 2. `community_comments`
Stores comments and replies on posts. Supports one level of nesting (replies to comments).

**Columns:**
- `id` (INT UNSIGNED, PRIMARY KEY) - Auto-increment comment ID
- `post_id` (INT UNSIGNED, FOREIGN KEY) - Post ID this comment belongs to
- `uni_id` (VARCHAR(36), FOREIGN KEY) - User ID from `user_credentials` table (comment author)
- `parent_comment_id` (INT UNSIGNED, NULLABLE) - Parent comment ID if this is a reply (one level deep only)
  - `NULL` = Top-level comment on a post
  - `NOT NULL` = Reply to another comment
- `content` (TEXT) - Comment content
- `created_at` (TIMESTAMP) - Comment creation timestamp
- `updated_at` (TIMESTAMP) - Last update timestamp

**Indexes:**
- Primary key on `id`
- Index on `post_id` (for post queries)
- Index on `uni_id` (for user queries)
- Index on `parent_comment_id` (for reply queries)
- Index on `created_at` (for sorting)

**Foreign Keys:**
- `post_id` → `community_posts.id` (CASCADE DELETE/UPDATE)
- `uni_id` → `user_credentials.uni_id` (CASCADE DELETE/UPDATE)
- `parent_comment_id` → `community_comments.id` (CASCADE DELETE/UPDATE)

**Comment Structure:**
- **Top-level comment**: `parent_comment_id = NULL` (direct comment on post)
- **Reply**: `parent_comment_id = <comment_id>` (reply to a comment)
- **Note**: Only one level of nesting is supported. If someone replies to a reply, it should mention the original commenter's name (like Facebook) rather than creating a deeper nest.

---

### 3. `community_post_attachments`
Stores file attachments (images, documents) for posts.

**Columns:**
- `id` (INT UNSIGNED, PRIMARY KEY) - Auto-increment attachment ID
- `post_id` (INT UNSIGNED, FOREIGN KEY) - Post ID this attachment belongs to
- `file_path` (VARCHAR(500)) - Path to uploaded file (server path)
- `file_type` (VARCHAR(50)) - File type category (e.g., 'image', 'document')
- `file_name` (VARCHAR(255)) - Original file name
- `file_size` (INT UNSIGNED, NULLABLE) - File size in bytes
- `mime_type` (VARCHAR(100), NULLABLE) - MIME type of the file (e.g., 'image/jpeg', 'application/pdf')
- `created_at` (TIMESTAMP) - Attachment creation timestamp

**Indexes:**
- Primary key on `id`
- Index on `post_id` (for post queries)
- Index on `file_type` (for filtering)

**Foreign Keys:**
- `post_id` → `community_posts.id` (CASCADE DELETE/UPDATE)

---

## Relationships

```
user_credentials (uni_id)
    ↓
community_posts (uni_id) ←─── community_post_attachments (post_id)
    ↓
community_comments (post_id)
    ↓
community_comments (parent_comment_id) [one level deep only]
```

## Usage Notes

1. **Post Creation**: Users create posts with `uni_id` from their logged-in session (stored in localStorage).

2. **Comments**: Users can comment on posts. Comments have `parent_comment_id = NULL`.

3. **Replies**: Users can reply to comments. Replies have `parent_comment_id = <comment_id>`. Only one level of nesting is supported.

4. **Replies to Replies**: If a user wants to reply to a reply, the system should:
   - Create a new comment with `parent_comment_id = <original_comment_id>` (not the reply's ID)
   - Display it as a mention: "@OriginalCommenterName [reply content]"

5. **Attachments**: Posts can have multiple attachments. Each attachment is linked to a post via `post_id`.

6. **Interactions Count**: The `interactions_count` field in `community_posts` should be updated whenever:
   - A comment is added
   - A comment is deleted
   - (Future: likes, shares, etc.)

## Migration Files

- **SQL Migration**: `migrations/001_create_community_tables.sql`
- **PHP Runner**: `migrations/run_community_migration.php`
- **Verification**: `migrations/verify_community_tables.php`

## Next Steps

1. Create API endpoints for:
   - Creating posts
   - Fetching posts (with pagination, filtering, sorting)
   - Creating comments
   - Fetching comments (with nested replies)
   - Uploading attachments
   - Updating interactions count

2. Update frontend (`Community.tsx`) to:
   - Fetch posts from API
   - Allow post creation
   - Display comments and replies
   - Handle file uploads

