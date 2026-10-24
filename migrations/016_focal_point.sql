-- Addendum A6: focal point per image (percent from the left / top), used as
-- object-position wherever an image is cropped (cards, heroes, mobile crops).
ALTER TABLE media
  ADD COLUMN focal_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER height,
  ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER focal_x;
