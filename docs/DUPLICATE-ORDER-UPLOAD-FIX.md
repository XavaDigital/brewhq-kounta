# Duplicate Order Upload Fix

## Problem

Orders were being uploaded to Kounta **twice**, resulting in duplicate order notes:

```
Upload attempted. Order already exists. Order#:2778587132
November 19, 2025 at 12:33 pm

Order uploaded to Kounta. Order#:2778587132
November 19, 2025 at 12:32 pm
```

## Root Cause

Two WooCommerce hooks were both triggering the order upload function:

1. **`woocommerce_thankyou`** - Fires when "Thank You" page is displayed after checkout
2. **`woocommerce_order_status_on-hold`** - Fires when order status changes to "on-hold"

### Timeline of Events

```
Customer completes checkout
    ↓
Order status changes to "on-hold"
    ↓
Hook: woocommerce_order_status_on-hold fires
    ↓
First upload starts (successful)
    ↓
Hook: woocommerce_thankyou fires (almost simultaneously)
    ↓
Second upload starts
    ↓
First upload completes, saves _kounta_id meta
    ↓
Second upload checks for _kounta_id
    ↓
Second upload finds existing ID, adds "already exists" note
```

### Race Condition

Even though the code checked if `_kounta_id` exists before uploading, there was a **race condition** where both hooks could fire before the meta was saved, causing both to proceed with the upload.

## Solution

### 1. Removed Duplicate Hook

**Before:**
```php
add_action('woocommerce_thankyou', array($this, 'xwcpos_add_order_to_kounta'), 9999);
add_action('woocommerce_order_status_on-hold', array($this, 'xwcpos_add_order_to_kounta'), 9999);
```

**After:**
```php
// Upload orders to Kounta when status changes to on-hold or processing
// Note: Only using status change hooks to prevent duplicate uploads
add_action('woocommerce_order_status_on-hold', array($this, 'xwcpos_add_order_to_kounta'), 9999);
add_action('woocommerce_order_status_processing', array($this, 'xwcpos_add_order_to_kounta'), 9999);
```

**Why this is better:**
- ✅ Status change hooks only fire **once** per status change
- ✅ More reliable than `woocommerce_thankyou` (which can fire multiple times if user refreshes)
- ✅ Covers both common payment gateway scenarios:
  - **On-hold** - For manual payment methods (bank transfer, check, etc.)
  - **Processing** - For automatic payment methods (credit card, PayPal, etc.)

### 2. Added Transient Lock

Added a transient-based mutex lock to prevent race conditions:

**At the start of upload:**
```php
// Check if order is already being uploaded (prevent race condition)
$upload_lock = get_transient('xwcpos_uploading_order_' . $order_id);
if ($upload_lock) {
    $this->plugin_log('Order ' . $order_id . ' is already being uploaded, skipping duplicate attempt');
    return array('error' => true, 'error_type' => 'duplicate_upload_attempt');
}

// Set upload lock (30 second timeout)
set_transient('xwcpos_uploading_order_' . $order_id, true, 30);
```

**After upload completes (success or failure):**
```php
// Clear upload lock
delete_transient('xwcpos_uploading_order_' . $order_id);
```

**How it works:**
1. Before uploading, check if a lock exists for this order
2. If lock exists, another upload is in progress → skip
3. If no lock, create one with 30-second timeout
4. Upload the order
5. Clear the lock when done (success or failure)
6. If something crashes, lock auto-expires after 30 seconds

## Benefits

✅ **No more duplicate uploads** - Each order is only uploaded once
✅ **No more "already exists" notes** - Second attempt is prevented before it starts
✅ **Race condition protection** - Transient lock prevents simultaneous uploads
✅ **Better hook selection** - Status change hooks are more reliable than `thankyou` hook
✅ **Covers all payment types** - Both on-hold and processing statuses are handled

## Testing

After deploying, verify:

- [ ] Orders with manual payment methods (bank transfer) upload once when status changes to "on-hold"
- [ ] Orders with automatic payment methods (credit card) upload once when status changes to "processing"
- [ ] No duplicate "Upload attempted. Order already exists" notes appear
- [ ] Only one "Order uploaded to Kounta" note appears per order
- [ ] Log file shows no duplicate upload attempts

## Files Modified

1. **brewhq-kounta.php**
   - Removed `woocommerce_thankyou` hook (line 145)
   - Added `woocommerce_order_status_processing` hook (line 147)
   - Added transient lock check at start of `xwcpos_add_order_to_kounta()` (lines 1902-1907)
   - Added transient lock creation (line 1912)
   - Added transient lock cleanup on success (line 1972)
   - Added transient lock cleanup on failure (line 1989)
   - Added transient lock cleanup on loop exit (line 1993)

## Related Issues

This fix is similar to the duplicate product sync fix implemented earlier, which also used transient locks to prevent concurrent operations.

## Future Enhancements

Potential improvements:
- Add admin notice if duplicate upload is detected
- Track duplicate upload attempts in order logs
- Add setting to choose which order statuses trigger upload
- Add bulk order upload with duplicate detection

