// Application Constants - Centralized key management

// Performance Constants
export const PERFORMANCE = {
  CACHE_SIZE_LIMIT: 50,
  DEBOUNCE_DELAY: 16, // ~60fps
  BATCH_UPDATE_DELAY: 16,
  WORKER_TIMEOUT: 30000,
  EXIF_TIMEOUT: 15000,
  IMAGE_QUALITY: 0.95,
  MAX_IMAGE_SIZE: 1200
};

// Image Types
export const IMAGE_TYPES = {
  CONTEXT: 'contextImage',
  CAR: 'carImage', 
  THIRD: 'thirdImage'
};

// Form Field IDs
export const FORM_FIELDS = {
  APPLICATION_ID: 'applicationId',
  PLATE_ID: 'plateId',
  DATETIME: 'datetime',
  COMMENT: 'comment',
  CATEGORY: 'category',
  EXTENSIONS: 'extensions',
  FORM: 'form',
  FORM_SUBMIT: 'form-submit',
  DT_FROM_PICTURE: 'dtFromPicture',
  DATE_HINT: 'dateHint',
  ADDRESS_HINT: 'addressHint',
  PLATE_IMAGE: 'plateImage',
  RECYDYWA: 'recydywa',
  RESIZE_MAP: 'resizeMap',
  LOCATION_PICKER: 'locationPicker',
  CONTEXT_IMAGE_PREVIEW: 'contextImagePreview',
  CAR_IMAGE_PREVIEW: 'carImagePreview',
  THIRD_IMAGE_PREVIEW: 'thirdImagePreview'
};

// API Endpoints
export const API_ENDPOINTS = {
  IMAGE: '/api/app/{appId}/image',
  IMAGE_DELETE: '/api/app/{appId}/image/{imageId}'
};

// Image Sources
export const IMAGE_SOURCES = {
  CDN: '/cdn/',
  IMG: 'img/',
  DEFAULT: 'fff-1.png'
};

// Request Body Keys
export const REQUEST_KEYS = {
  IMAGE_DATA: 'image_data',
  PICTURE_TYPE: 'pictureType',
  DATE_TIME: 'dateTime',
  DT_FROM_PICTURE: 'dtFromPicture',
  LAT_LNG: 'latLng'
};

// Response Keys
export const RESPONSE_KEYS = {
  CAR_INFO: 'carInfo',
  CAR_IMAGE: 'carImage',
  PLATE_ID: 'plateId',
  PLATE_IMAGE: 'plateImage',
  VEHICLE_BOX: 'vehicleBox',
  WIDTH: 'width',
  HEIGHT: 'height'
};

// EXIF Keys
export const EXIF_KEYS = {
  LAT: 'lat',
  LNG: 'lng',
  DATE_TIME: 'dateTime'
};

// Geolocation Sources
export const GEOLOCATION_SOURCES = {
  PICTURE: 'picture'
};

// Error Messages
export const ERROR_MESSAGES = {
  APPLICATION_ID_NOT_FOUND: 'Application ID not found',
  WEB_WORKERS_NOT_SUPPORTED: 'Web Workers not supported',
  NO_AVAILABLE_IMAGE_WORKERS: 'No available image workers - all workers are busy',
  NO_AVAILABLE_EXIF_WORKERS: 'No available EXIF workers - all workers are busy',
  IMAGE_RESIZE_TIMEOUT: 'Image resize timeout - operation took too long',
  EXIF_PARSE_TIMEOUT: 'EXIF parse timeout - operation took too long',
  SERVER_ERROR_HTML: 'Server error: Expected JSON response',
  UPLOAD_FAILED: 'Failed to upload {id}: {error}',
  REMOVE_FAILED: 'Failed to remove {id}: {error}',
  INVALID_IMAGE_TYPE: 'Zdjęcie o niepoprawnym type {type}',
  NO_GEO_DATA: 'Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" target="_blank" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.'
};

// Success Messages
export const SUCCESS_MESSAGES = {
  DATE_FROM_PICTURE: 'Data i godzina pobrana ze zdjęcia',
  DATE_MANUAL: 'Podaj datę i godzinę zgłoszenia'
};

// Default Values
export const DEFAULT_VALUES = {
  MAX_WIDTH: PERFORMANCE.MAX_IMAGE_SIZE,
  MAX_HEIGHT: PERFORMANCE.MAX_IMAGE_SIZE,
  IMAGE_QUALITY: PERFORMANCE.IMAGE_QUALITY,
  IMAGE_TYPE: 'image/jpeg',
  CATEGORY_DEFAULT: '0',
  TIMEOUT_IMAGE_RESIZE: PERFORMANCE.WORKER_TIMEOUT,
  TIMEOUT_EXIF_PARSE: PERFORMANCE.EXIF_TIMEOUT
};

// Worker Types
export const WORKER_TYPES = {
  RESIZE: 'resize',
  PARSE_EXIF: 'parse-exif'
};

// Worker Message Types
export const WORKER_MESSAGE_TYPES = {
  RESIZE_COMPLETE: 'resize-complete',
  RESIZE_ERROR: 'resize-error',
  EXIF_COMPLETE: 'exif-complete',
  EXIF_ERROR: 'exif-error'
}; 