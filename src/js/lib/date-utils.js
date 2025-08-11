// Date Utilities - Reusable date parsing and formatting

import { DateTime } from "luxon";

// Date parsing cache for better performance
const DATE_CACHE = new Map();
const CACHE_SIZE_LIMIT = 100;

/**
 * Parse date from various formats (EXIF, ISO, etc.)
 * @param {string|Date} dateInput - Date to parse
 * @returns {DateTime|null} - Parsed Luxon DateTime or null
 */
export function parseDate(dateInput) {
  if (!dateInput) return null;
  
  // Check cache first
  const cacheKey = typeof dateInput === 'string' ? dateInput : dateInput.toISOString();
  if (DATE_CACHE.has(cacheKey)) {
    return DATE_CACHE.get(cacheKey);
  }
  
  let dt = null;
  
  if (typeof dateInput === "string") {
    // Try different date formats
    dt = DateTime.fromISO(dateInput);
    if (dt.invalid) {
      dt = DateTime.fromFormat(dateInput, "yyyy:MM:dd HH:mm:ss");
    }
    if (dt.invalid) {
      dt = DateTime.fromFormat(dateInput, "yyyy:MM:dd HH:mm");
    }
    if (dt.invalid) {
      dt = DateTime.fromFormat(dateInput, "yyyy-MM-dd'T'HH:mm:ss");
    }
    if (dt.invalid) {
      dt = DateTime.fromFormat(dateInput, "yyyy-MM-dd HH:mm:ss");
    }
    if (dt.invalid) {
      return null;
    }
  } else if (dateInput instanceof Date) {
    // Convert Date object to Luxon
    dt = DateTime.fromJSDate(dateInput);
    if (!dt.isValid) {
      return null;
    }
  } else {
    return null;
  }
  
  // Cache the result
  if (DATE_CACHE.size >= CACHE_SIZE_LIMIT) {
    // Remove oldest entry
    const firstKey = DATE_CACHE.keys().next().value;
    DATE_CACHE.delete(firstKey);
  }
  DATE_CACHE.set(cacheKey, dt);
  
  return dt;
}

/**
 * Format date for datetime input field
 * @param {DateTime|string|Date} dateInput - Date to format
 * @returns {string|null} - Formatted date string or null
 */
export function formatDateForInput(dateInput) {
  const dt = parseDate(dateInput);
  if (!dt) return null;
  
  return dt.toFormat("yyyy-LL-dd'T'HH:mm");
}

/**
 * Parse EXIF date and format for UI
 * @param {string|Date} exifDate - EXIF date from image
 * @returns {string|null} - Formatted date string or null
 */
export function parseExifDate(exifDate) {
  if (!exifDate) return null;
  
  // If it's already a string, try to parse it
  if (typeof exifDate === 'string') {
    return formatDateForInput(exifDate);
  } else if (exifDate instanceof Date) {
    return formatDateForInput(exifDate);
  }
  
  return null;
}

/**
 * Check if date is valid
 * @param {string|Date|DateTime} dateInput - Date to validate
 * @returns {boolean} - Whether date is valid
 */
export function isValidDate(dateInput) {
  const dt = parseDate(dateInput);
  return dt !== null && dt.isValid;
}

/**
 * Get current date in input format
 * @returns {string} - Current date formatted for input
 */
export function getCurrentDate() {
  return DateTime.now().toFormat("yyyy-LL-dd'T'HH:mm");
}

/**
 * Clear date cache when needed
 */
export function clearDateCache() {
  DATE_CACHE.clear();
} 