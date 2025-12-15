# Duplicate Order Upload Fix - Version 3.0

## Date

2025-12-15

## Problem

Orders were being uploaded to Kounta **multiple times** due to a flaw in the verification logic when the Kounta API returns `null`.

### Root Cause Analysis

When uploading an order to Kounta:

1. **API call succeeds** - Order is created in Kounta
2. **API returns `null`** - This is normal behavior for the Kounta API (response body is empty/null)
3. **Single verification attempt** - Code waited only 1 second and tried to find the order once
4. **Verification fails** - Due to eventual consistency, the order isn't found yet
5. **Returns `verification_failed` error** - Marked as retryable
6. **Retry logic kicks in** - Attempts to upload the order again
7. **Second/third attempts** - Same pattern repeats
8. **Eventually** - Duplicate check finds the order created by the first attempt

### Evidence from Production Logs

**Order #303005** (uploaded twice at 8:07pm and 8:08pm):

- Entry #35-36: First upload attempt - API call made, response was 4 bytes (null)
- Entry #38-39: Second upload attempt - API call made again, response was 4 bytes (null)
- Entry #41-42: Third upload attempt - Duplicate found (from first attempt)

**Order #303366** (uploaded once at 9:42pm):

- Single upload attempt, no issues

## Solution

### Two-Level Retry Strategy

We now implement a **two-level retry strategy** that handles both eventual consistency and transient API failures:

#### Level 1: Verification Retries (within each upload attempt)

When the API returns `null`, we verify the order was created with **4 verification attempts**:

- Delays: 1s, 2s, 3s, 5s (total 11 seconds)
- Each verification searches Kounta for the order by sale number
- Returns success as soon as order is found
- Returns `verification_failed` only if all 4 attempts fail

#### Level 2: Upload Retries (outer loop)

If verification fails after all attempts, we retry the **entire upload process**:

- **2 upload attempts** maximum
- Each upload attempt includes its own 4 verification attempts
- Special handling for `verification_failed` errors
- 2-second delay between upload retries

This approach:

- ✅ Handles eventual consistency issues (multiple verification attempts)
- ✅ Handles transient API failures (multiple upload attempts)
- ✅ Prevents duplicates (duplicate check before each upload)
- ✅ Prevents missed orders (retries upload if verification fails)
- ✅ Provides detailed logging for troubleshooting

## How It Works

### Flow Diagram

```
Upload Attempt #1
  ├─ Make API call
  ├─ API returns null
  ├─ Verification attempt #1 (1s) → Not found
  ├─ Verification attempt #2 (2s) → Found! ✅
  └─ Return order ID → SUCCESS

OR (if verification fails):

Upload Attempt #1
  ├─ Make API call
  ├─ API returns null
  ├─ Verification attempts #1-4 (11s) → Not found
  └─ Return verification_failed

Upload Attempt #2 (after 2s delay)
  ├─ Duplicate check → Finds order from attempt #1 ✅
  └─ Return existing order ID → SUCCESS

OR (if truly failed):

Upload Attempts #1, #2
  ├─ All fail verification
  └─ Send admin notification → FAILURE
```

## New Log Stages

| Stage                       | Description                                   |
| --------------------------- | --------------------------------------------- |
| `verification_attempt`      | Starting a verification attempt               |
| `verification_success`      | Order found and verified                      |
| `verification_retry`        | Verification failed, will retry               |
| `verification_exhausted`    | All verification attempts failed              |
| `verification_failed_retry` | Verification failed, will retry entire upload |
| `retryable_error`           | Retryable error encountered, will retry       |
| `non_retryable_error`       | Non-retryable error, stopping                 |

## Expected Behavior

### Scenario 1: Normal Upload (API returns null, eventual consistency)

1. **Upload attempt #1** → API returns null
2. Verification attempt #1 (1s) → Not found
3. Verification attempt #2 (2s) → **Found!**
4. **Result: No duplicate, order uploaded once ✅**

### Scenario 2: Slow Eventual Consistency

1. **Upload attempt #1** → API returns null
2. Verification attempts #1-4 (11s) → Not found
3. **Upload attempt #2** (2s delay) → Duplicate check finds order from attempt #1
4. **Result: No duplicate, order uploaded once ✅**

### Scenario 3: Transient API Failure

1. **Upload attempt #1** → API error (timeout)
2. **Upload attempt #2** (2s delay) → API returns null
3. Verification attempt #1 (1s) → **Found!**
4. **Result: No duplicate, order uploaded successfully ✅**

### Scenario 4: Permanent Failure

1. **Upload attempts #1, #2** → All fail verification
2. Admin notification sent
3. **Result: No duplicate, proper error handling ✅**

## Files Modified

- `includes/class-kounta-order-service.php`
  - Added `verify_order_creation_with_retries()` method
  - Replaced retry strategy with custom two-level retry loop
  - Enhanced logging throughout

## Testing Recommendations

1. Monitor new orders for the next 24-48 hours
2. Check event logs for new verification stages
3. Verify no duplicates are created in Kounta
4. Check for any `verification_exhausted` logs (should be rare)

## Related Documentation

- `docs/DUPLICATE-ORDER-UPLOAD-FIX.md` - Original fix (v1.0)
- `docs/DUPLICATE-ORDER-PREVENTION-V2.md` - Enhanced prevention (v2.0)
- `docs/RELIABILITY-IMPROVEMENTS.md` - Overall reliability improvements
