-- migrations/add_recipe_stories.sql
-- Adds Recipe Stories (User Blog Posts & Social Sharing) support to The Veggie Vault

CREATE TABLE IF NOT EXISTS recipe_stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    excerpt VARCHAR(320) NOT NULL,
    cover_image VARCHAR(500) NULL DEFAULT NULL,
    content_html MEDIUMTEXT NOT NULL,
    read_time_minutes INT NOT NULL DEFAULT 1,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recipe_stories_recipe_id (recipe_id, status, created_at),
    INDEX idx_recipe_stories_user_id (user_id, status, created_at),
    CONSTRAINT fk_recipe_stories_recipe
        FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_stories_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS story_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_story_user_like (story_id, user_id),
    INDEX idx_story_likes_story_id (story_id),
    CONSTRAINT fk_story_likes_story
        FOREIGN KEY (story_id) REFERENCES recipe_stories(id) ON DELETE CASCADE,
    CONSTRAINT fk_story_likes_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
    ADD COLUMN story_slug VARCHAR(240) NULL DEFAULT NULL AFTER comment_id;
