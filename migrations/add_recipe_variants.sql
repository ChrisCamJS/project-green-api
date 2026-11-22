-- migrations/add_recipe_variants.sql
-- Adds Recipe Variants ("Forking") support to The Veggie Vault

ALTER TABLE users
  MODIFY COLUMN generation_tokens DECIMAL(10,2) NULL DEFAULT 0.00;

ALTER TABLE recipes
  ADD COLUMN parent_recipe_id INT NULL DEFAULT NULL AFTER user_id,
  ADD COLUMN root_recipe_id INT NULL DEFAULT NULL AFTER parent_recipe_id,
  ADD COLUMN is_masterpiece TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public,
  ADD COLUMN variant_summary VARCHAR(255) NULL DEFAULT NULL AFTER description,
  ADD INDEX idx_recipes_parent_id (parent_recipe_id),
  ADD INDEX idx_recipes_root_id (root_recipe_id),
  ADD CONSTRAINT fk_recipes_parent_recipe
    FOREIGN KEY (parent_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_recipes_root_recipe
    FOREIGN KEY (root_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL;
