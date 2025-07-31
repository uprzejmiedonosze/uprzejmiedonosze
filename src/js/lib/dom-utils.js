// DOM Utilities - Reusable DOM manipulation patterns

// DOM caching for better performance
const DOM_CACHE = new Map();
const STYLE_CACHE = new WeakMap(); // Cache computed styles

// Debounce utility for performance
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

/**
 * Get cached DOM element or query and cache it
 * @param {string} selector - CSS selector
 * @returns {Element|null} - Cached element
 */
export function getCachedElement(selector) {
  if (!DOM_CACHE.has(selector)) {
    DOM_CACHE.set(selector, document.querySelector(selector));
  }
  return DOM_CACHE.get(selector);
}

/**
 * Clear DOM cache when DOM changes significantly
 */
export function clearDOMCache() {
  DOM_CACHE.clear();
  STYLE_CACHE.clear();
}

/**
 * Batch UI updates for better performance using requestAnimationFrame
 * @param {Function[]} updates - Array of update functions
 */
export function batchUIUpdates(updates) {
  requestAnimationFrame(() => {
    updates.forEach(update => update());
  });
}

/**
 * Optimized style setting with caching
 * @param {Element} element - Target element
 * @param {Object} styles - Style properties to set
 */
export function setElementStyles(element, styles) {
  if (!element) return;
  
  // Check if styles are already applied
  const cachedStyles = STYLE_CACHE.get(element);
  if (cachedStyles) {
    const hasChanges = Object.entries(styles).some(([key, value]) => 
      cachedStyles[key] !== value
    );
    if (!hasChanges) return; // No changes needed
  }
  
  // Cache the new styles
  STYLE_CACHE.set(element, { ...styles });
  
  batchUIUpdates([
    () => {
      Object.entries(styles).forEach(([property, value]) => {
        element.style[property] = value;
      });
    }
  ]);
}

/**
 * Debounced style setting for frequent updates
 */
export const setElementStylesDebounced = debounce(setElementStyles, 16); // ~60fps


/**
 * Set up image load handler with fallback
 * @param {HTMLImageElement} image - Image element
 * @param {Function} onLoad - Callback when image loads
 * @param {Function} onError - Callback when image fails to load
 */
export function setupImageLoadHandler(image, onLoad, onError = null) {
  if (!image) return;
  
  if (image.complete) {
    // Image already loaded
    onLoad();
  } else {
    // Set up load handler
    image.onload = onLoad;
    if (onError) {
      image.onerror = onError;
    }
  }
}

/**
 * Native DOM selector (jQuery replacement)
 * @param {string} selector - CSS selector
 * @returns {Element|null} - Found element
 */
export function $(selector) {
  return document.querySelector(selector);
}

/**
 * Native DOM selector for multiple elements (jQuery replacement)
 * @param {string} selector - CSS selector
 * @returns {NodeList} - Found elements
 */
export function $$(selector) {
  return document.querySelectorAll(selector);
} 