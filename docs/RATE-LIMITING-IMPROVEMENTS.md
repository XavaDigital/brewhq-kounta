# Rate Limiting Improvements

## 📋 Overview

This document describes improvements made to the rate limiting system to better handle Kounta API rate limits and reduce 429 (Too Many Requests) errors during product sync operations.

## 🐛 Problem Identified

During product sync operations, the system was hitting rate limits (HTTP 429 responses) from the Kounta API:

```
2025-11-19 03:42:30::[API Client] API Response: HTTP 429 (Duration: 0.75s)
2025-11-19 03:42:30::[API Client] WARNING: Rate limit hit (HTTP 429), retrying after: default delay
```

### Root Causes

1. **Excessive Default Wait Time**: When a 429 was hit without a `Retry-After` header, the system waited for the full time window (60 seconds), which was excessive.

2. **Rate Limit Too Aggressive**: The rate limiter was configured for exactly 60 requests/minute, leaving no safety margin for:
   - Network timing variations
   - Burst requests during batch operations
   - Multiple concurrent processes

3. **No Batch Spacing**: Batches of 50 products were processed back-to-back without any delay, causing request bursts.

4. **Poor Retry-After Parsing**: The system didn't properly parse the `Retry-After` header (which can be seconds or an HTTP date).

## ✅ Solutions Implemented

### 1. Improved Rate Limit Handling (`class-kounta-rate-limiter.php`)

**Before:**
```php
public function handle_rate_limit($retry_after = null) {
    $this->tokens = 0;
    $this->save_state();
    
    // Wait for the specified time or default
    $wait_seconds = $retry_after ? (int)$retry_after : $this->time_window; // 60 seconds!
    sleep($wait_seconds);
    
    $this->refill_tokens();
}
```

**After:**
```php
public function handle_rate_limit($retry_after = null) {
    $this->tokens = 0;
    $this->save_state();
    
    // Determine wait time
    if ($retry_after) {
        $wait_seconds = (int)$retry_after;
    } else {
        // Default to 2 seconds instead of full 60 second window
        $wait_seconds = 2;
    }
    
    error_log(sprintf(
        '[Kounta Rate Limiter] Rate limit hit, waiting %d seconds before retry',
        $wait_seconds
    ));
    
    sleep($wait_seconds);
    $this->refill_tokens();
}
```

**Impact**: Reduced default wait time from 60 seconds to 2 seconds when no `Retry-After` header is provided.

---

### 2. Better Retry-After Header Parsing (`class-kounta-api-client.php`)

**Before:**
```php
if ($status_code === 429) {
    $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
    $this->log('WARNING: Rate limit hit (HTTP 429), retrying after: ' . ($retry_after ?: 'default delay'));
    $this->rate_limiter->handle_rate_limit($retry_after);
    return $this->make_request($endpoint, $method, $params, $data);
}
```

**After:**
```php
if ($status_code === 429) {
    $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
    
    // Parse Retry-After header (can be seconds or HTTP date)
    $wait_seconds = null;
    if ($retry_after) {
        if (is_numeric($retry_after)) {
            $wait_seconds = (int)$retry_after;
        } else {
            // Try to parse as HTTP date
            $retry_time = strtotime($retry_after);
            if ($retry_time !== false) {
                $wait_seconds = max(0, $retry_time - time());
            }
        }
    }
    
    $this->log(sprintf(
        'WARNING: Rate limit hit (HTTP 429), waiting %d seconds before retry',
        $wait_seconds ?: 2
    ));
    
    $this->rate_limiter->handle_rate_limit($wait_seconds);
    return $this->make_request($endpoint, $method, $params, $data);
}
```

**Impact**: Properly handles both numeric (seconds) and HTTP date formats for `Retry-After` header.

---

### 3. More Conservative Rate Limit (`class-kounta-api-client.php`)

**Before:**
```php
// Initialize rate limiter (60 requests per minute as default)
$this->rate_limiter = new Kounta_Rate_Limiter(60, 60);
```

**After:**
```php
// Initialize rate limiter
// Use 50 requests per minute to be conservative and avoid hitting limits
// Kounta's actual limit is 60/min, but we stay under to account for:
// - Multiple concurrent processes
// - Burst requests during batch operations
// - Network timing variations
$this->rate_limiter = new Kounta_Rate_Limiter(50, 60);
```

**Impact**: Reduced from 60 to 50 requests per minute, providing a 16% safety margin.

---

### 4. Batch Spacing (`class-kounta-sync-service.php`)

**Before:**
```php
foreach ($batches as $batch) {
    $batch_result = $this->process_product_batch($batch, $refresh_time, $site_id);
    $updated_count += $batch_result['updated'];
    $skipped_count += $batch_result['skipped'];
    $error_count += $batch_result['errors'];
}
```

**After:**
```php
foreach ($batches as $batch_index => $batch) {
    $this->log(sprintf(
        'Processing batch %d of %d (%d products)',
        $batch_index + 1,
        $batch_count,
        count($batch)
    ));
    
    $batch_result = $this->process_product_batch($batch, $refresh_time, $site_id);
    $updated_count += $batch_result['updated'];
    $skipped_count += $batch_result['skipped'];
    $error_count += $batch_result['errors'];
    
    // Add a small delay between batches to help with rate limiting
    if ($batch_index < $batch_count - 1) {
        usleep(500000); // 0.5 second delay between batches
    }
}
```

**Impact**: Adds 0.5 second pause between batches to prevent request bursts.

---

## 📊 Expected Results

### Before Improvements
- **Rate Limit**: 60 requests/minute (no safety margin)
- **429 Response Wait**: 60 seconds (excessive)
- **Batch Processing**: Continuous (burst requests)
- **Retry-After Parsing**: Basic (numeric only)

### After Improvements
- **Rate Limit**: 50 requests/minute (16% safety margin)
- **429 Response Wait**: 2 seconds default, or actual `Retry-After` value
- **Batch Processing**: 0.5 second pause between batches
- **Retry-After Parsing**: Handles both numeric and HTTP date formats

### Performance Impact
- **Slightly slower** overall sync time due to conservative rate limiting
- **Significantly fewer 429 errors** and retries
- **More predictable** sync performance
- **Better logging** for debugging rate limit issues

---

## 🔍 Monitoring

### Log Messages to Watch For

**Rate Limit Hit:**
```
[Kounta Rate Limiter] Rate limit hit, waiting 2 seconds before retry
[API Client] WARNING: Rate limit hit (HTTP 429), waiting 2 seconds before retry
```

**Batch Processing:**
```
[Optimized Sync] Processing batch 1 of 10 (50 products)
[Optimized Sync] Processing batch 2 of 10 (50 products)
```

### Success Indicators
- Fewer 429 errors in logs
- Consistent sync times
- No excessive wait times

---

## 🛠️ Configuration

If you need to adjust the rate limiting:

### Change Rate Limit
Edit `includes/class-kounta-api-client.php`:
```php
// Adjust first parameter (requests per minute)
$this->rate_limiter = new Kounta_Rate_Limiter(40, 60); // Even more conservative
```

### Change Batch Delay
Edit `includes/class-kounta-sync-service.php`:
```php
usleep(1000000); // 1 second delay instead of 0.5
```

### Change Default 429 Wait Time
Edit `includes/class-kounta-rate-limiter.php`:
```php
$wait_seconds = 3; // 3 seconds instead of 2
```

---

## 📝 Files Modified

1. **`includes/class-kounta-rate-limiter.php`**
   - Reduced default wait time from 60s to 2s
   - Added logging for rate limit events

2. **`includes/class-kounta-api-client.php`**
   - Improved Retry-After header parsing
   - Reduced rate limit from 60 to 50 requests/minute
   - Better logging for 429 responses

3. **`includes/class-kounta-sync-service.php`**
   - Added 0.5 second delay between batches
   - Added batch progress logging

---

**Last Updated:** 2024-11-19  
**Version:** 2.0.1

