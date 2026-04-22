# Async Order Upload Fix - Critical Payment Issue Resolution

## Date
2025-12-16

## Problem

**CRITICAL ISSUE**: Customers experiencing double payments and checkout page timeouts.

### Reported Issue
> "Page times out but order goes through, then when customer refreshes it doubles up"

### Root Cause

The Kounta order upload was running **synchronously during payment processing**, causing:

1. **Checkout page timeout** - Upload takes 5-15 seconds (API calls, verification retries)
2. **Customer refreshes** - Thinks payment failed, submits again
3. **Double payment** - Stripe charges twice
4. **Race conditions** - Multiple payment hooks firing simultaneously

### Technical Details

**Before (Synchronous):**
```
Customer clicks "Place Order"
  ↓
Payment Gateway processes payment
  ↓
Order status → "processing"
  ↓
Hook: woocommerce_order_status_processing (Priority 20)
  ↓
⏱️ BLOCKS HERE: Upload to Kounta (5-15 seconds)
  ↓  - Prepare order data
  ↓  - Upload attempt #1
  ↓  - Verification attempt #1 (5s)
  ↓  - Verification attempt #2 (3s)
  ↓  - Verification attempt #3 (3s)
  ↓  - Total: 11-30+ seconds
  ↓
Page finally responds to customer
```

**Problem**: The customer sees a blank/loading page for 10-30 seconds, thinks it failed, refreshes, and pays again.

## Solution

**Decouple order upload from payment processing using WooCommerce Action Scheduler (async background jobs).**

### Implementation

**After (Asynchronous):**
```
Customer clicks "Place Order"
  ↓
Payment Gateway processes payment
  ↓
Order status → "processing"
  ↓
Hook: woocommerce_order_status_processing (Priority 10)
  ↓
✅ INSTANT: Schedule upload for 30 seconds later (<100ms)
  ↓
Page responds to customer immediately
  ↓
Customer sees success page

[30 seconds later, in background]
  ↓
Action Scheduler executes: xwcpos_async_upload_order
  ↓
Upload to Kounta (takes as long as needed)
```

**Benefits:**
- ✅ **Instant checkout response** - Customer sees success immediately
- ✅ **No timeouts** - Upload happens in background
- ✅ **No double payments** - Customer doesn't think it failed
- ✅ **Better reliability** - Background jobs retry automatically if they fail
- ✅ **Better server performance** - Doesn't block PHP process

## Code Changes

### 1. Hook Registration (`brewhq-kounta.php` lines 145-153)

**Before:**
```php
add_action('woocommerce_order_status_processing', array($this, 'xwcpos_order_status_processing_direct'), 20, 2);
add_action('woocommerce_order_status_on-hold', array($this, 'xwcpos_order_status_onhold_direct'), 20, 2);
```

**After:**
```php
// CRITICAL: Using Action Scheduler to DECOUPLE from payment processing
// This prevents order upload from blocking payment completion
add_action('woocommerce_order_status_processing', array($this, 'xwcpos_schedule_order_upload'), 10, 2);
add_action('woocommerce_order_status_on-hold', array($this, 'xwcpos_schedule_order_upload'), 10, 2);

// Handle the actual async upload
add_action('xwcpos_async_upload_order', array($this, 'xwcpos_async_upload_order_handler'), 10, 1);
```

### 2. New Method: `xwcpos_schedule_order_upload()`

This method:
- Runs during payment processing hook (**but doesn't block!**)
- Checks if order already uploaded (prevent duplicates)
- Checks if upload already scheduled (prevent duplicates)
- Schedules upload for 30 seconds later
- Returns immediately (<100ms)

### 3. New Method: `xwcpos_async_upload_order_handler()`

This method:
- Runs in background via Action Scheduler
- Double-checks order not already uploaded
- Performs the actual upload
- Takes as long as needed (no timeout concerns)

### 4. Removed Methods

- `xwcpos_order_status_processing_direct()` - Replaced with scheduling
- `xwcpos_order_status_onhold_direct()` - Unified into single scheduler

## New Log Stages

| Stage | Icon | Description |
|-------|------|-------------|
| `upload_scheduled` | 📅 | Order upload scheduled for async processing |
| `upload_triggered` | 🚀 | Async upload handler executing |

## Expected Behavior

### Normal Checkout Flow

1. **Customer completes payment** (2-3 seconds)
2. **Page responds with success** immediately
3. **Upload scheduled** for 30 seconds later (logged as `upload_scheduled`)
4. **Customer sees order confirmation** page
5. **30 seconds later**: Background job uploads to Kounta (logged as `upload_triggered`)
6. **Upload completes**: Order synced with Kounta

### Why 30 Second Delay?

- Ensures payment is **fully settled** in Stripe
- Prevents any race conditions with payment webhooks
- Gives WooCommerce time to finalize order data
- Still fast enough for operational needs

## Duplicate Prevention

Three layers of protection:

1. **Before scheduling**: Check if order has `_kounta_id` meta
2. **Before scheduling**: Check if upload already scheduled
3. **In async handler**: Re-check if order has `_kounta_id` meta

## Rollback Plan

If issues arise, temporarily disable async upload by commenting out lines 145-152 in `brewhq-kounta.php` and uncommenting the old hooks (backed up in git history).

## Testing Recommendations

1. **Test normal checkout** - Verify upload happens within 30-60 seconds
2. **Test page refresh** - Refresh order confirmation page, verify no duplicate upload
3. **Monitor logs** - Check for `upload_scheduled` and `upload_triggered` entries
4. **Monitor Stripe** - Verify no double charges
5. **Monitor Kounta** - Verify no duplicate orders

## Files Modified

- `brewhq-kounta.php`
  - Lines 145-153: Hook registration
  - Lines 1905-1999: New async scheduling methods
  - Removed: Old direct upload methods
  
- `admin/class-kounta-order-logs-page.php`
  - Added `upload_scheduled` stage icon and class

## Related Issues

- Previous similar issue resolved by moving sync away from payment hooks
- This is the same root cause, now properly fixed with async processing

## Action Scheduler Requirements

WooCommerce includes Action Scheduler by default. No additional dependencies required.

To view scheduled actions: **WooCommerce → Status → Scheduled Actions**
