// Remove jQuery dependency - use native DOM APIs
import { DateTime } from "luxon";

import { setAddressByLatLng } from "../lib/geolocation";
import Api from '../lib/Api'

import * as Sentry from "@sentry/browser";
import { error } from "../lib/toast";
import { updateRecydywa } from "./recydywa";
import workerManager from '../lib/worker-manager';

// Import reusable utilities
import { 
  getCachedElement, 
  batchUIUpdates, 
  setElementStyles, 
  setupImageLoadHandler,
  $,
  $$
} from '../lib/dom-utils';

import { 
  parseDate, 
  formatDateForInput
} from '../lib/date-utils';

// Import constants
import {
  IMAGE_TYPES,
  FORM_FIELDS,
  API_ENDPOINTS,
  IMAGE_SOURCES,
  REQUEST_KEYS,
  RESPONSE_KEYS,
  EXIF_KEYS,
  GEOLOCATION_SOURCES,
  ERROR_MESSAGES,
  SUCCESS_MESSAGES,
  DEFAULT_VALUES
} from '../lib/constants';

let uploadInProgress = 0;

// AbortController map for cancelling fetch requests
const fetchControllers = new Map();

// Simplified setDateTime function using date-utils
function setDateTime(dateTime, fromPicture = true) {
  // Parse and format the date using date-utils
  const formattedDateTime = formatDateForInput(dateTime);
  const isValidDate = formattedDateTime !== null;
  const fromPictureValid = isValidDate && fromPicture;
  
  // Update UI based on whether we have a valid date from picture
  updateDateTimeUI(fromPictureValid, formattedDateTime);
  
  // Update hidden field
  const dtFromPicture = getCachedElement(`#${FORM_FIELDS.DT_FROM_PICTURE}`);
  if (dtFromPicture) {
    dtFromPicture.value = fromPictureValid ? 1 : 0;
  }
  
  return formattedDateTime;
}

// Separate UI update function for better separation of concerns
function updateDateTimeUI(fromPicture, formattedDateTime) {
  const dateHint = getCachedElement(`#${FORM_FIELDS.DATE_HINT}`);
  const datetime = getCachedElement(`#${FORM_FIELDS.DATETIME}`);
  
  if (fromPicture && formattedDateTime) {
    // Date from picture - show success state
    batchUIUpdates([
      () => {
        if (dateHint) {
          dateHint.textContent = SUCCESS_MESSAGES.DATE_FROM_PICTURE;
          dateHint.classList.add('hint');
        }
      },
      () => {
        if (datetime) {
          datetime.setAttribute('readonly', 'true');
          datetime.value = formattedDateTime;
          datetime.classList.remove('error');
        }
      }
    ]);
    
    // Show change datetime links
    $$('a.changeDatetime').forEach(el => {
      setElementStyles(el, { display: 'block' });
    });
  } else {
    // Manual date entry - show manual state
    batchUIUpdates([
      () => {
        if (dateHint) {
          dateHint.textContent = SUCCESS_MESSAGES.DATE_MANUAL;
          dateHint.classList.add('hint');
        }
      },
      () => {
        if (datetime) {
          datetime.removeAttribute('readonly');
        }
      }
    ]);
    
    // Hide change datetime links
    $$('a.changeDatetime').forEach(el => {
      setElementStyles(el, { display: 'none' });
    });
  }
}

/**
 * @param {File} file
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id
 * @returns void
 */
export async function checkFile(file, id) {
  if (!file) return

  // Early validation - check MIME type first to avoid unnecessary UI operations
  if (!/^image\//i.test(file.type)) {
    return imageError(id, ERROR_MESSAGES.INVALID_IMAGE_TYPE.replace('{type}', file.type));
  }

  // Clear previous plate detection results for car images
  if (id === IMAGE_TYPES.CAR) {
    // Remove yellow vehicle box
    const existingVehicleBox = $('.vehicleBox');
    if (existingVehicleBox) {
      existingVehicleBox.remove();
    }
    // Hide plate image
    const plateImage = getCachedElement(`#${FORM_FIELDS.PLATE_IMAGE}`);
    if (plateImage) {
          setElementStyles(plateImage, { display: 'none' });
    plateImage.src = '';
  }
    // Clear plate field
    const plateIdField = getCachedElement(`#${FORM_FIELDS.PLATE_ID}`);
    if (plateIdField) {
      plateIdField.value = '';
    }
    // Don't show indicator yet - wait until image is actually loaded
  }

  uploadStarted(id);

    try {
    // Process image and EXIF in parallel for better performance
    const [resizedImage, exifData] = await Promise.all([
      processImageWithWorker(file),
      (id === IMAGE_TYPES.CAR) ? parseExifWithWorker(file) : Promise.resolve(null)
    ]);

        // Update UI with resized image
    const previewElement = $(`#${id}Preview`);
    if (previewElement) {
      batchUIUpdates([
        () => previewElement.src = resizedImage,
        () => previewElement.alt = `Local preview for ${id}`
      ]);
      
      // For third images, wait for the image to load before updating display
      if (id === IMAGE_TYPES.THIRD) {
        // Clear any existing load handlers first
        previewElement.onload = null;
        
        // Set up the load handler
        previewElement.onload = () => {
          console.log('Third image loaded, updating display');
          // Update third image display after image is fully loaded
          if (window.updateThirdImageDisplay) {
            window.updateThirdImageDisplay();
          }
        };
        
        // If image is already loaded, trigger the handler immediately
        if (previewElement.complete && previewElement.naturalWidth > 1) {
          previewElement.onload();
        }
      }
      

      
      // For car images, wait for the image to load before updating third image button
      if (id === IMAGE_TYPES.CAR) {
        // Clear any existing load handlers first
        previewElement.onload = null;
        
        // Set up the load handler
        previewElement.onload = () => {
          console.log('Car image loaded, checking third image state');
          showPlateDetectionIndicator();
          
          // Check if third image is already loaded before clearing it
          const thirdImagePreview = document.getElementById(FORM_FIELDS.THIRD_IMAGE_PREVIEW);
          if (thirdImagePreview) {
            const isThirdImageLoaded = thirdImagePreview.src && 
              thirdImagePreview.naturalWidth > 1 && 
              !thirdImagePreview.src.includes('default') && 
              !thirdImagePreview.src.includes('fff-1.png');
            
            console.log('Car image loaded, third image state:', {
              thirdImageSrc: thirdImagePreview.src,
              thirdImageNaturalWidth: thirdImagePreview.naturalWidth,
              isThirdImageLoaded: isThirdImageLoaded
            });
            
            // Only clear third image if it's not already loaded
            if (!isThirdImageLoaded) {
              thirdImagePreview.src = '';
              // Hide the image element completely to prevent broken image icon
              setElementStyles(thirdImagePreview, { display: 'none' });
            }
          }
          
          // Update third image button state after car image is fully loaded
          if (window.updateThirdImageDisplay) {
            window.updateThirdImageDisplay();
          }
        };
        
        // If image is already loaded, trigger the handler immediately
        if (previewElement.complete && previewElement.naturalWidth > 1) {
          previewElement.onload();
        }
      }
    }
    
    // Handle third image container visibility after image is set
    if (id === IMAGE_TYPES.THIRD) {
      // Don't set default image - let updateThirdImageDisplay handle the visibility
      // The third image preview will be set by the server response
      console.log('Third image uploaded, preview state:', {
        src: previewElement.src,
        naturalWidth: previewElement.naturalWidth,
        complete: previewElement.complete
      });
    }

    if (id === IMAGE_TYPES.CAR && exifData) {
      const [lat, lng] = [exifData[EXIF_KEYS.LAT], exifData[EXIF_KEYS.LNG]];
      const dateTime = exifData[EXIF_KEYS.DATE_TIME];

      // Use setDateTime with original dateTime value - it has its own parsing logic
      const formattedDateTime = setDateTime(dateTime, !!dateTime);
      
      if (lat) {
        setAddressByLatLng(lat, lng, GEOLOCATION_SOURCES.PICTURE)
      } else {
        noGeoDataInImage()
      }

      const plateImage = $(`#${FORM_FIELDS.PLATE_IMAGE}`);
      if (plateImage) {
        plateImage.src = "";
        setElementStyles(plateImage, { display: 'none' });
      }
      await sendFile(resizedImage, id, {
        [REQUEST_KEYS.DATE_TIME]: formattedDateTime,
        [REQUEST_KEYS.DT_FROM_PICTURE]: !!formattedDateTime,
        [REQUEST_KEYS.LAT_LNG]: `${lat},${lng}`
      });
    } else {
      await sendFile(resizedImage, id);
    }

  } catch (err) {
    imageError(id, err.message);
    Sentry.captureException(err, {
      extra: Object.prototype.toString.call(file)
    });
  }
}

function uploadStarted(id) {
  uploadInProgress++;
  const previewElement = $(`#${id}Preview`);
  if (previewElement) {
            setElementStyles(previewElement, {
          opacity: '0.5',
          display: 'block'
        });
  }
  
  // Show loader for this specific image
      const loaderElement = previewElement?.closest('.imageContainer')?.querySelector('.loader');
  if (loaderElement) {
            setElementStyles(loaderElement, { display: 'block' });
  }
}

function uploadFinished() {
  uploadInProgress--;
  if (uploadInProgress === 0) {
    // Reset all preview opacities
    $$(`#${FORM_FIELDS.CONTEXT_IMAGE_PREVIEW}, #${FORM_FIELDS.CAR_IMAGE_PREVIEW}, #${FORM_FIELDS.THIRD_IMAGE_PREVIEW}`).forEach(el => {
      if (el) setElementStyles(el, { opacity: '1' });
    });
    
    // Hide all loaders
    $$('.loader').forEach(loader => {
      if (loader) setElementStyles(loader, { display: 'none' });
    });
  }
}

function imageError(id, errorMsg) {
  uploadFinished();
  const previewElement = $(`#${id}Preview`);
  if (previewElement) {
          setElementStyles(previewElement, {
        display: 'none',
        opacity: '1'
      });
  }
  error(errorMsg);
}

function noGeoDataInImage() {
  const addressHint = $(`#${FORM_FIELDS.ADDRESS_HINT}`);
  if (!addressHint) return;

  batchUIUpdates([
    () => {
      addressHint.textContent = ERROR_MESSAGES.NO_GEO_DATA;
      addressHint.classList.add('hint');
    }
  ]);
}

/**
 * @param {*} vehicleBox {x, y, width, height} of box in which the car is located
 * @param {number} imageWidth real image file width
 * @param {number} imageHeight real image file height
 */
export function repositionCarImage(vehicleBox, imageWidth, imageHeight) {
  const width = parseInt(imageWidth) || DEFAULT_VALUES.MAX_WIDTH
  const height = parseInt(imageHeight) || DEFAULT_VALUES.MAX_HEIGHT
  
  if (!vehicleBox.width || !width || width <= 0) {
    return;
  }

  const carImagePreview = $('img#carImagePreview')
  if (!carImagePreview) {
    return;
  }

  const carImageContainer = carImagePreview.closest('.carImageSection');
  if (!carImageContainer) {
    return;
  }

  const trimBoxWidth = carImagePreview.offsetWidth
  const trimBoxHeight = 200

  // Remove any existing vehicle box
  const existingVehicleBox = $('.vehicleBox')
  if (existingVehicleBox) {
    existingVehicleBox.remove()
  }
  
  // Create vehicle box div (similar to plate-box)
  const vehicleBoxElement = document.createElement('div')
  vehicleBoxElement.className = 'vehicleBox'
  
  // Calculate position and size in viewport coordinates
  const vehicleX = parseInt(vehicleBox.x)
  const vehicleY = parseInt(vehicleBox.y)
  const vehicleWidth = parseInt(vehicleBox.width)
  const vehicleHeight = parseInt(vehicleBox.height)
  
  const vehicleBoxX = (vehicleX / width) * trimBoxWidth
  const vehicleBoxY = (vehicleY / height) * trimBoxHeight
  const vehicleBoxWidth = (vehicleWidth / width) * trimBoxWidth
  const vehicleBoxHeight = (vehicleHeight / height) * trimBoxHeight
  
  // Batch all style changes for better performance
  batchUIUpdates([
    () => {
      vehicleBoxElement.style.position = 'absolute'
      vehicleBoxElement.style.border = '2px solid yellow'
      vehicleBoxElement.style.backgroundColor = 'transparent'
      vehicleBoxElement.style.pointerEvents = 'none'
      vehicleBoxElement.style.zIndex = '10'
      vehicleBoxElement.style.left = vehicleBoxX + 'px'
      vehicleBoxElement.style.top = vehicleBoxY + 'px'
      vehicleBoxElement.style.width = vehicleBoxWidth + 'px'
      vehicleBoxElement.style.height = vehicleBoxHeight + 'px'
      carImageContainer.style.position = 'relative'
    }
  ]);
  
  carImageContainer.appendChild(vehicleBoxElement)
}

// --- ASYNC PLATE DETECTION IMPROVEMENT START ---

function showPlateDetectionIndicator() {
  const indicator = document.querySelector('.plate-detection-indicator');
  if (!indicator) {
    const newIndicator = document.createElement('div');
    newIndicator.className = 'plate-detection-indicator';
    newIndicator.textContent = 'Rozpoznawanie tablicy...';
    const carImageContainer = document.querySelector('.carImageSection');
    if (carImageContainer) {
      carImageContainer.appendChild(newIndicator);
    }
    return newIndicator;
  }
  return indicator;
}

function hidePlateDetectionIndicator() {
  const indicator = document.querySelector('.plate-detection-indicator');
  if (indicator && indicator.parentNode) {
    indicator.parentNode.removeChild(indicator);
  }
}

function updatePlateField(plateId) {
  const plateIdField = getCachedElement(`#${FORM_FIELDS.PLATE_ID}`);
  if (plateIdField) {
    plateIdField.value = plateId;
    
    // Batch all DOM manipulations for better performance
    batchUIUpdates([
      () => {
        plateIdField.classList.remove('error');
        plateIdField.classList.add('plate-detected');
      }
    ]);
    
    setTimeout(() => plateIdField.classList.remove('plate-detected'), 2000);
    
    // Update plate hint
    const plateHint = document.getElementById('plateHint');
    if (plateHint) {
      batchUIUpdates([
        () => {
          plateHint.removeAttribute('class');
          plateHint.classList.add('hint');
          plateHint.textContent = "Sprawdź automatycznie pobrany numer rejestracyjny";
        }
      ]);
    }
    
    // Update recydywa
    const appId = getCachedElement(`#${FORM_FIELDS.APPLICATION_ID}`)?.value;
    if (appId) {
      updateRecydywa(appId);
    }
  }
}

function updatePlateImage(plateImageSrc) {
  const plateImage = getCachedElement(`#${FORM_FIELDS.PLATE_IMAGE}`);
  if (plateImage) {
    // Add cache-busting parameter to force browser to reload the image
    const cacheBuster = '?t=' + Date.now();
    const imageUrlWithCacheBuster = plateImageSrc + cacheBuster;
    
    batchUIUpdates([
      () => plateImage.src = imageUrlWithCacheBuster,
      () => setElementStyles(plateImage, { display: 'block' })
    ]);
  }
}

function updateVehicleBox(vehicleBox, carImage) {
  const imageWidth = carImage[RESPONSE_KEYS.WIDTH] || DEFAULT_VALUES.MAX_WIDTH;
  const imageHeight = carImage[RESPONSE_KEYS.HEIGHT] || DEFAULT_VALUES.MAX_HEIGHT;
  const carImagePreview = $('img#carImagePreview');
  if (carImagePreview) {
    const currentSrc = carImagePreview.src;
    const isDraftApplication = currentSrc.includes(IMAGE_SOURCES.CDN) || 
      (currentSrc.includes(IMAGE_SOURCES.IMG) && !currentSrc.includes(IMAGE_SOURCES.DEFAULT));
    if (!isDraftApplication) {
      setupImageLoadHandler(carImagePreview, () => {
        repositionCarImage(vehicleBox, imageWidth, imageHeight);
      });
    }
  }
}

function updateCommentWithVehicleInfo(carInfo) {
  const commentField = getCachedElement(`#${FORM_FIELDS.COMMENT}`);
  if (!commentField) return;
  
  // Only update if comment is empty
  const currentComment = commentField.value || '';
  if (currentComment.trim().length > 0) return;
  
  if (carInfo.brand) {
    const commentText = carInfo.brandConfidence > 98 
      ? "Pojazd marki " + carInfo.brand + "."
      : carInfo.brandConfidence > 90 
        ? "Pojazd prawdopodobnie marki " + carInfo.brand + "."
        : '';
    
    if (commentText) {
      commentField.value = commentText;
    }
  }
}

async function handlePlateDetectionResults(response) {
  showPlateDetectionIndicator();
  try {
    const result = await response.json();
    if (result[RESPONSE_KEYS.CAR_INFO]) {
      if (result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.PLATE_ID]) {
        updatePlateField(result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.PLATE_ID]);
      }
      if (result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.PLATE_IMAGE]) {
        updatePlateImage(result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.PLATE_IMAGE]);
      }
      if (result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.VEHICLE_BOX] && result[RESPONSE_KEYS.CAR_IMAGE]) {
        updateVehicleBox(result[RESPONSE_KEYS.CAR_INFO][RESPONSE_KEYS.VEHICLE_BOX], result[RESPONSE_KEYS.CAR_IMAGE]);
      }
      
      // Update comment with brand and color information
      if (result[RESPONSE_KEYS.CAR_INFO].brand || result[RESPONSE_KEYS.CAR_INFO].color) {
        updateCommentWithVehicleInfo(result[RESPONSE_KEYS.CAR_INFO]);
      }
    }
  } catch (error) {
    // Plate detection is optional, do not show error
    console.error('Plate detection failed:', error);
  } finally {
    hidePlateDetectionIndicator();
  }
}

// --- ASYNC PLATE DETECTION IMPROVEMENT END ---

async function sendFile(fileData, id, imageMetadata = {}) {
  try {
    // Get the application ID from the form
    const appId = getCachedElement(`#${FORM_FIELDS.APPLICATION_ID}`)?.value;
    if (!appId) {
      throw new Error(ERROR_MESSAGES.APPLICATION_ID_NOT_FOUND);
    }

    // Create AbortController for this request
    const controller = new AbortController();
    fetchControllers.set(id, controller);

    // fileData is already a base64 data URL string from the worker
    const base64Data = fileData;

    // Prepare the request body
    const requestBody = {
      [REQUEST_KEYS.IMAGE_DATA]: base64Data,
      [REQUEST_KEYS.PICTURE_TYPE]: id
    };

    // Add metadata if provided
    if (imageMetadata[REQUEST_KEYS.DATE_TIME]) {
      requestBody[REQUEST_KEYS.DATE_TIME] = imageMetadata[REQUEST_KEYS.DATE_TIME];
    }
    if (imageMetadata[REQUEST_KEYS.DT_FROM_PICTURE] !== undefined) {
      requestBody[REQUEST_KEYS.DT_FROM_PICTURE] = imageMetadata[REQUEST_KEYS.DT_FROM_PICTURE].toString();
    }
    if (imageMetadata[REQUEST_KEYS.LAT_LNG]) {
      requestBody[REQUEST_KEYS.LAT_LNG] = imageMetadata[REQUEST_KEYS.LAT_LNG];
    }

    // Start upload with AbortController
    const responsePromise = fetch(API_ENDPOINTS.IMAGE.replace('{appId}', appId), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(requestBody),
      signal: controller.signal
    });

    // Show image and finish upload immediately
    uploadFinished();
    
    // Update image opacity to full after upload completes
    const previewElement = $(`#${id}Preview`);
    if (previewElement) {
      setElementStyles(previewElement, {
        display: 'block',
        opacity: '1'
      });
      
          // Ensure the image container is properly positioned to hide button text
    const container = previewElement.closest('.imageContainer');
    if (container) {
      batchUIUpdates([
        () => container.style.position = 'relative'
      ]);
    }
    }
    
    // Handle plate detection results asynchronously (do not block UI)
    if (id === IMAGE_TYPES.CAR) {
      const response = await responsePromise;
      console.log('Car image upload response:', response);
      handlePlateDetectionResults(response);
    }

    // Clean up AbortController
    fetchControllers.delete(id);

    // For other images, just resolve
    return;
  } catch (error) {
    // Clean up AbortController on error
    fetchControllers.delete(id);
    
    // Handle AbortError specifically
    if (error.name === 'AbortError') {
      console.log('Upload cancelled for', id);
      return; // Don't throw error for cancelled requests
    }
    
    console.error('Upload error:', error);
    throw new Error(ERROR_MESSAGES.UPLOAD_FAILED.replace('{id}', id).replace('{error}', error.message));
  }
}

export async function removeFile(id) {
  try {
    const appId = getCachedElement(`#${FORM_FIELDS.APPLICATION_ID}`)?.value;
    if (!appId) {
      throw new Error(ERROR_MESSAGES.APPLICATION_ID_NOT_FOUND);
    }

    // Create AbortController for this request
    const controller = new AbortController();
    const deleteKey = `delete_${id}`;
    fetchControllers.set(deleteKey, controller);

    const response = await fetch(API_ENDPOINTS.IMAGE_DELETE.replace('{appId}', appId).replace('{imageId}', id), {
      method: 'DELETE',
      signal: controller.signal
    });
    
    // Check if response is JSON
    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await response.text();
      console.error('Server returned HTML instead of JSON:', text.substring(0, 200));
      throw new Error(ERROR_MESSAGES.SERVER_ERROR_HTML);
    }
    
    const result = await response.json();
    
    // The API returns the application object directly
    const previewElement = $(`#${id}Preview`);
    if (previewElement) {
      previewElement.src = '';
      // Don't hide the preview for 3rd image - let the specific logic handle it
      if (id !== IMAGE_TYPES.THIRD) {
        setElementStyles(previewElement, { display: 'none' });
      }
    }
    
    // Clear the file input so the same file can be uploaded again
    const fileInput = document.getElementById(id);
    if (fileInput) {
      fileInput.value = '';
    }
    
    // Handle third image removal specifically
    if (id === IMAGE_TYPES.THIRD) {
      const preview = document.getElementById(FORM_FIELDS.THIRD_IMAGE_PREVIEW);
      if (preview) {
        preview.src = '';
        // Force hide the preview to prevent broken image icon
        setElementStyles(preview, { display: 'none' });
      }
      
      // Update UI state
      if (window.updateThirdImageDisplay) {
        window.updateThirdImageDisplay();
      }
    }
    
    // Update third image button state when car image is removed
    if (id === IMAGE_TYPES.CAR && window.updateThirdImageDisplay) {
      window.updateThirdImageDisplay();
    }
    
    // Clean up AbortController
    fetchControllers.delete(deleteKey);
    
  } catch (error) {
    // Clean up AbortController on error
    fetchControllers.delete(deleteKey);
    
    // Handle AbortError specifically
    if (error.name === 'AbortError') {
      console.log('Remove cancelled for', id);
      return; // Don't show error for cancelled requests
    }
    
    console.error('Remove error:', error);
    error(ERROR_MESSAGES.REMOVE_FAILED.replace('{id}', id).replace('{error}', error.message));
  }
}

// Web Worker helper functions
async function processImageWithWorker(file) {
    return await workerManager.processImageInWorker(file);
}

async function parseExifWithWorker(file) {
  try {
  return await workerManager.parseExifInWorker(file);
  } catch (error) {
    console.warn('EXIF parsing failed:', error);
    return null;
  }
}

// Utility function to cancel ongoing fetch requests
export function cancelFetchRequest(id) {
  const controller = fetchControllers.get(id);
  if (controller) {
    controller.abort();
    fetchControllers.delete(id);
    console.log('Cancelled fetch request for', id);
  }
}

// Utility function to cancel delete requests
export function cancelDeleteRequest(id) {
  const deleteKey = `delete_${id}`;
  const controller = fetchControllers.get(deleteKey);
  if (controller) {
    controller.abort();
    fetchControllers.delete(deleteKey);
    console.log('Cancelled delete request for', id);
  }
}

