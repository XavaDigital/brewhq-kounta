# Image and Description Sync Implementation Plan

## Executive Summary

This document outlines the plan to implement robust image and description syncing from Kounta/Lightspeed to WooCommerce products in the BrewHQ Kounta plugin.

## Current State Analysis

### What Exists

#### 1. **Database Schema** ✅
- `wp_xwcpos_items` table has `description` and `image` fields
- `wp_xwcpos_item_images` table exists (commented out) for detailed image tracking
- Description field already populated: `online_description` or fallback to `description`

#### 2. **Image Sync Infrastructure** ⚠️ (Commented Out)
- `xwcpos_update_item_images()` method exists (line 2875-2901)
- `add_kounta_product_image()` method exists in admin class (line 1398+)
- Image sync is **commented out** in multiple places:
  - Line 2791-2792: `// $this->xwcpos_update_item_images($new_item, $old_item);`
  - Line 998-1011: Product image gallery sync (commented)
  - Line 1723-1735: WooCommerce product image update (commented)
  - Line 1784-1797: Matrix item image sync (commented)

#### 3. **Description Sync** ⚠️ (Partially Implemented)
- Description stored in database from Kounta API
- **NOT synced to WooCommerce products** during updates
- Lines 1698-1700 are commented out:
  ```php
  //'post_content' => empty($item->item_e_commerce->long_description) ? null : $item->item_e_commerce->long_description,
  //'post_excerpt' => empty($item->item_e_commerce->short_description) ? null : $item->item_e_commerce->short_description,
  ```

#### 4. **API Integration** ✅
- Kounta API already fetches product data including images
- `xwcpos_fetch_item_inventory()` loads relations: `ItemECommerce`, `Tags`, `Images`
- Image data structure from API: `$item->Images->Image` (can be object or array)

### What's Missing

1. **Active Image Sync**: Code exists but is disabled
2. **Active Description Sync**: Partial - stored but not applied to WooCommerce
3. **Image Download & Attachment**: Need to download images from Kounta URLs and attach to WooCommerce
4. **Comparison Logic**: Need to check if images/descriptions changed before updating
5. **Error Handling**: Robust handling of image download failures
6. **Admin Controls**: UI to enable/disable image and description sync

## Implementation Plan

### Phase 1: Image Sync Service (High Priority)

#### 1.1 Create Image Sync Service Class
**File**: `includes/class-kounta-image-sync-service.php`

**Features**:
- Download images from Kounta URLs
- Upload to WordPress media library
- Attach to WooCommerce products
- Handle multiple images (gallery)
- Compare existing images to avoid duplicates
- Error handling for failed downloads
- Retry logic for transient failures

**Methods**:
```php
class Kounta_Image_Sync_Service {
    public function sync_product_images($product_id, $kounta_images);
    private function download_image($image_url, $product_id);
    private function attach_image_to_product($attachment_id, $product_id, $is_featured);
    private function image_exists($image_url, $product_id);
    private function get_image_from_kounta($kounta_image);
}
```

#### 1.2 Image Data Structure
From Kounta API:
```php
$item->image // Single image URL (string)
$item->Images->Image // Detailed image object(s)
  ->imageID
  ->filename
  ->baseImageURL
  ->publicID
  ->ordering
  ->description
```

#### 1.3 Integration Points
- Uncomment `xwcpos_update_item_images()` call in `update_item_data()`
- Add to optimized sync service
- Add image sync to product import flow

### Phase 2: Description Sync Service (Medium Priority)

#### 2.1 Create Description Sync Service Class
**File**: `includes/class-kounta-description-sync-service.php`

**Features**:
- Sync long description (post_content)
- Sync short description (post_excerpt)
- Compare existing descriptions to avoid unnecessary updates
- Handle HTML formatting
- Sanitize content

**Methods**:
```php
class Kounta_Description_Sync_Service {
    public function sync_product_description($product_id, $kounta_product);
    private function get_long_description($kounta_product);
    private function get_short_description($kounta_product);
    private function description_changed($product_id, $new_description, $type);
    private function sanitize_description($description);
}
```

#### 2.2 Description Data Sources
From Kounta API:
```php
$item->description // Basic description
$item->online_description // Online-specific description (preferred)
$item->item_e_commerce->long_description // Detailed description
$item->item_e_commerce->short_description // Short description
```

Priority order:
1. `online_description` (if available)
2. `item_e_commerce->long_description` (if available)
3. `description` (fallback)

#### 2.3 Integration Points
- Uncomment description sync in `xwcpos_update_woocommerce_product()`
- Add to optimized sync service
- Add description sync to product import flow

### Phase 3: Integration with Optimized Sync (High Priority)

#### 3.1 Extend Sync Service
**File**: `includes/class-kounta-sync-service.php`

Add methods:
```php
private function sync_product_images($item, $k_product);
private function sync_product_description($item, $k_product);
```

#### 3.2 Update `sync_single_product()` Method
Add image and description sync after stock update:
```php
// Update stock (existing)
// ...

// Sync images (new)
if (get_option('xwcpos_sync_images', true)) {
    $this->sync_product_images($item, $k_product);
}

// Sync descriptions (new)
if (get_option('xwcpos_sync_descriptions', true)) {
    $this->sync_product_description($item, $k_product);
}
```

### Phase 4: Admin Controls (Medium Priority)

#### 4.1 Add Settings
Add to plugin settings page:
- ☑ Enable image sync
- ☑ Enable description sync
- ☑ Overwrite existing images
- ☑ Overwrite existing descriptions

#### 4.2 Settings Implementation
```php
// Settings
add_option('xwcpos_sync_images', true);
add_option('xwcpos_sync_descriptions', true);
add_option('xwcpos_overwrite_images', false);
add_option('xwcpos_overwrite_descriptions', false);
```

### Phase 5: Error Handling & Logging

#### 5.1 Image Download Failures
- Log failed image downloads
- Continue sync even if image fails
- Add order note with failure details
- Retry failed images on next sync

#### 5.2 Description Sync Failures
- Log sanitization issues
- Handle malformed HTML
- Preserve existing description on failure

## Technical Considerations

### 1. Performance
- **Image Downloads**: Can be slow, use batch processing
- **Parallel Downloads**: Download multiple images concurrently
- **Caching**: Check if image already exists before downloading
- **Timeouts**: Set reasonable timeouts for image downloads (30s)

### 2. Image Handling
- **File Size**: Validate image size before download
- **File Type**: Validate image type (jpg, png, gif, webp)
- **Duplicates**: Check by URL hash to avoid duplicate downloads
- **Cleanup**: Remove old images when product images change

### 3. Description Handling
- **HTML Sanitization**: Use `wp_kses_post()` for safe HTML
- **Encoding**: Handle UTF-8 characters properly
- **Line Breaks**: Convert `\n` to `<br>` or `<p>` tags
- **Empty Descriptions**: Don't overwrite if Kounta description is empty

### 4. Database Schema
Current schema is sufficient. Optional enhancement:
```sql
ALTER TABLE wp_xwcpos_items 
ADD COLUMN last_image_sync datetime,
ADD COLUMN last_description_sync datetime;
```

## Implementation Priority

### High Priority (Implement First)
1. ✅ Image Sync Service
2. ✅ Integration with Optimized Sync
3. ✅ Basic Error Handling

### Medium Priority (Implement Second)
1. ✅ Description Sync Service
2. ✅ Admin Controls
3. ✅ Comparison Logic (avoid unnecessary updates)

### Low Priority (Future Enhancement)
1. ⏳ Image gallery support (multiple images)
2. ⏳ Image optimization (resize, compress)
3. ⏳ Bulk image sync tool
4. ⏳ Image sync progress tracking

## Success Criteria

1. **Images**: Product images automatically download from Kounta and attach to WooCommerce products
2. **Descriptions**: Product descriptions sync from Kounta to WooCommerce
3. **Performance**: Image sync doesn't significantly slow down product sync
4. **Reliability**: Failed image downloads don't break product sync
5. **Control**: Admin can enable/disable image and description sync
6. **Efficiency**: Existing images/descriptions not re-downloaded unnecessarily

## Risks & Mitigation

### Risk 1: Slow Image Downloads
**Mitigation**: 
- Implement timeout (30s per image)
- Use parallel downloads
- Continue sync even if image fails

### Risk 2: Large Images
**Mitigation**:
- Validate file size before download (max 10MB)
- Implement image optimization
- Use WordPress image resizing

### Risk 3: Broken Image URLs
**Mitigation**:
- Validate URL before download
- Handle 404 errors gracefully
- Log failed URLs for review

### Risk 4: Description Formatting Issues
**Mitigation**:
- Sanitize HTML with `wp_kses_post()`
- Preserve existing description on error
- Log formatting issues

## Testing Plan

1. **Unit Tests**: Test image download, attachment, description sync
2. **Integration Tests**: Test full sync with images and descriptions
3. **Performance Tests**: Measure sync time with/without images
4. **Error Tests**: Test with broken URLs, large files, malformed HTML
5. **Manual Tests**: Verify images appear correctly in WooCommerce

## Timeline Estimate

- **Phase 1 (Image Sync)**: 4-6 hours
- **Phase 2 (Description Sync)**: 2-3 hours
- **Phase 3 (Integration)**: 2-3 hours
- **Phase 4 (Admin Controls)**: 2-3 hours
- **Phase 5 (Error Handling)**: 2-3 hours
- **Testing**: 3-4 hours

**Total**: 15-22 hours

## Next Steps

1. Review and approve this plan
2. Implement Phase 1 (Image Sync Service)
3. Test image sync with sample products
4. Implement Phase 2 (Description Sync)
5. Integrate with optimized sync
6. Add admin controls
7. Comprehensive testing

