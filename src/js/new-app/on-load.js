import { checkFile } from "./images";
import { validateForm } from "./validate-form";
import { bindSoftCommentValidation } from "../lib/validation";
import { IMAGE_SOURCES } from "../lib/constants";
import { getCachedElement, setElementStyles, batchUIUpdates } from "../lib/dom-utils";
import { FORM_FIELDS } from "../lib/constants";

import { error } from "../lib/toast";

// Constants for section class names
const THIRD_IMAGE_SECTION_CLASS = 'thirdImageSection';

export const initHandlers = (map) => {
  // Set the multi-image selection hint only for Windows users
  const isWindows = navigator.userAgent.includes('Windows');
  let dragHintInserted = false;
  let dragHintEl = null;
  let dragCounter = 0;
  
  if (isWindows) {
    const imagesGrid = document.querySelector('.grid.images');
    if (imagesGrid) {
      function showDragHint() {
        if (!dragHintInserted) {
          dragHintEl = document.createElement('div');
          dragHintEl.className = 'smInfoHint';
          dragHintEl.textContent = 'Użyj Shift, aby zaznaczyć wiele zdjęć';
          imagesGrid.parentNode.insertBefore(dragHintEl, imagesGrid);
          dragHintInserted = true;
        }
      }
      function hideDragHint() {
        if (dragHintInserted && dragHintEl) {
          dragHintEl.remove();
          dragHintInserted = false;
          dragHintEl = null;
        }
      }
      imagesGrid.addEventListener('dragenter', function(e) {
        dragCounter++;
        if (dragCounter === 1) {
          showDragHint();
        }
      });
      imagesGrid.addEventListener('dragleave', function(e) {
        dragCounter--;
        if (dragCounter === 0) {
          hideDragHint();
        }
      });
      imagesGrid.addEventListener('dragover', function(e) {
        e.preventDefault();
      });
      imagesGrid.addEventListener('drop', function(e) {
        dragCounter = 0;
        hideDragHint();
      });
    }
  }

  // Combined change handler for multiple fields
  ["msisdn", "plateId", "comment", "datetime", "category"].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener("change", function (e) {
        this.classList.remove("error");
        if (this.id === "plateId") {
          const plateImage = document.getElementById("plateImage");
          const recydywa = document.getElementById("recydywa");
          if (plateImage) setElementStyles(plateImage, { display: "none" });
          if (recydywa) setElementStyles(recydywa, { display: "none" });
        }
        if (this.id === "category") {
          const contextLabel = document.querySelector(".contextImageSection label b");
          if (contextLabel) contextLabel.textContent = e.target.getAttribute("data-contextImage-hint");
          const carLabel = document.querySelector(".carImageSection label b");
          if (carLabel) carLabel.textContent = e.target.getAttribute("data-carImage-hint");
          validateExtensions();
        }
      });
    }
  });

  validateExtensions();
  bindSoftCommentValidation();

  // Reusable function to process multiple files with proper slot ordering
  function processImageFiles(files, startId) {
    const slotOrder = ["contextImage", "carImage", "thirdImage"];
    const startIdx = slotOrder.indexOf(startId) !== -1 ? slotOrder.indexOf(startId) : 0;
    
    for (let i = 0; i < Math.min(files.length, slotOrder.length - startIdx); i++) {
      checkFile(files[i], slotOrder[startIdx + i]);
    }
  }

  // File input change handler (event delegation)
  document.addEventListener("change", function (e) {
    const target = e.target;
    if (target && target.matches && target.matches(".image-upload input")) {
      processImageFiles(target.files, target.id);
    }
  });

  // Utility function to get the closest .image-upload ancestor
  function getClosestDropZone(target) {
    return target.closest && target.closest(".image-upload");
  }

  // Consolidated drag event handlers
  ["dragover", "dragenter"].forEach(eventType => {
    document.addEventListener(eventType, function (e) {
      const dropZone = getClosestDropZone(e.target);
      if (dropZone) {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.add("dragover");
      }
    });
  });

  ["dragleave", "dragend", "drop"].forEach(eventType => {
    document.addEventListener(eventType, function (e) {
      const dropZone = getClosestDropZone(e.target);
      if (dropZone) {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.remove("dragover");
      }
    });
  });

  document.addEventListener("drop", function (e) {
    const dropZone = getClosestDropZone(e.target);
    if (dropZone) {
      e.preventDefault();
      e.stopPropagation();
      dropZone.classList.remove("dragover");
      const dt = e.dataTransfer;
      if (!dt || !dt.files || dt.files.length === 0) return;
      const id = getDropTargetInputId(dropZone);
      processImageFiles(dt.files, id);
    }
  });

  const resizeMap = document.getElementById("resizeMap");
  if (resizeMap) {
    resizeMap.addEventListener("click", function () {
      const locationPicker = document.getElementById("locationPicker");
      if (locationPicker) locationPicker.classList.toggle("locationPickerBig");
      resizeMap.classList.toggle("ui-icon-arrowsin");
      map.resize();
    });
  }

  const formSubmit = document.getElementById("form-submit");
  if (formSubmit) {
    formSubmit.addEventListener("click", function () {
      const form = document.getElementById("form");
      if (validateForm()) {
        formSubmit.classList.add('disabled');
        if (form) form.submit();
      }
    });
  }

  document.querySelectorAll("a.changeDatetime").forEach(link => {
    link.addEventListener("click", function () {
      document.querySelectorAll("a.changeDatetime").forEach(l => setElementStyles(l, { display: "none" }));
      document.getElementById("datetime")?.removeAttribute('readonly');
    });
  });

  document.querySelectorAll(".image-upload img").forEach(img => {
    img.addEventListener("load", function () {
      const defaultSrc = IMAGE_SOURCES.DEFAULT || "/img/default.png";
      const isDefault = img.src.includes(defaultSrc) || window.location.href.includes(img.src);
      setElementStyles(img, { display: isDefault ? "none" : "" });
    });
  });

  // Dedicated event listener for #thirdImagePreview
  const thirdImage = document.querySelector("#thirdImagePreview");
  if (thirdImage) {
    thirdImage.addEventListener("load", function () {
      const defaultSrc = IMAGE_SOURCES.DEFAULT || "/img/default.png";
      const isDefault = thirdImage.src.includes(defaultSrc) || window.location.href.includes(thirdImage.src);

      const container = document.querySelector('.imageContainer.image-upload.thirdImageSection');
      const button = document.querySelector('.imageButton.image-upload.thirdImageSection');

      if (container) setElementStyles(container, { display: isDefault ? "none" : "block" });
      if (button) setElementStyles(button, { display: isDefault ? "block" : "none" });
    });
  }

  // Allow clicking the third image preview to trigger file input for replacement
  document.querySelectorAll('.imageContainer.image-upload.' + THIRD_IMAGE_SECTION_CLASS).forEach(container => {
    container.addEventListener('click', function (e) {
      if (e.target.closest('a.button.remove')) {
        return;
      }
      const siblingButton = container.parentElement.querySelector('.imageButton.image-upload.' + THIRD_IMAGE_SECTION_CLASS);
      if (siblingButton) {
        const fileInput = siblingButton.querySelector('input[type="file"]');
        if (fileInput) {
          fileInput.click();
        }
      }
    });
  });
  
  // Allow clicking the car image preview to trigger file input for replacement
  document.querySelectorAll('.imageContainer.image-upload.carImageSection').forEach(container => {
    container.addEventListener('click', function (e) {
      // If clicking on the label, let the browser handle it naturally
      if (e.target.closest('label')) {
        return;
      }
      
      e.preventDefault();
      e.stopPropagation();
      
      if (e.target.closest('a.button.remove')) {
        return;
      }
      
      const fileInput = container.querySelector('input[type="file"]');
      if (fileInput) {
        fileInput.click();
      }
    });
  });

  updateThirdImageDisplay();
};

function validateExtensions() {
  const selectedCategory = document?.querySelector('input[name="category"]:checked')?.value || '0'
  const extensions = document?.getElementById('extensions')
  const labels = [...(extensions?.querySelectorAll('label') || [])]

  labels.forEach(e => e.classList.remove('disabled'))

  const matchingExtensionSelector = `#ex${selectedCategory}`;
  const matchingExtension = document.querySelector(matchingExtensionSelector);
  const matchingExtensionLabel = matchingExtension ? document.querySelector(`${matchingExtensionSelector} + label`) : null;
  const comment = document?.getElementById('comment');

  if (matchingExtension) {
    matchingExtension.checked = false
    matchingExtensionLabel?.classList.add('disabled')
  }

  if (selectedCategory !== '0') {
    comment?.classList.remove('error')
  }
}

// Utility function to get the correct file input id for a drop event
function getDropTargetInputId(dropZone) {
  const fileInput = dropZone.querySelector("input[type='file']");
  let id = fileInput && fileInput.id;
  if (!id && dropZone.classList.contains(THIRD_IMAGE_SECTION_CLASS)) {
    const siblingButton = dropZone.parentElement.querySelector('.imageButton.image-upload.' + THIRD_IMAGE_SECTION_CLASS);
    if (siblingButton) {
      const siblingInput = siblingButton.querySelector("input[type='file']");
      if (siblingInput) {
        id = siblingInput.id;
      }
    }
  }
  return id;
}

function updateThirdImageDisplay() {
  const preview = document.getElementById(FORM_FIELDS.THIRD_IMAGE_PREVIEW);
  const container = document.querySelector('.imageContainer.image-upload.thirdImageSection');
  const button = document.querySelector('.imageButton.image-upload.thirdImageSection');

  if (!preview || !container || !button) return;

  // Debug logging
  console.log('updateThirdImageDisplay called:', {
    previewSrc: preview.src,
    previewNaturalWidth: preview.naturalWidth,
    containerDisplay: container.style.display,
    buttonDisplay: button.style.display
  });

  // Check if there's a valid third image (not default/empty)
  const isThirdImageLoaded = preview.src && 
    preview.naturalWidth > 1 && 
    !preview.src.includes('default') && 
    !preview.src.includes('fff-1.png');
  
  // Check if car image exists (required for third image)
  const carImagePreview = document.getElementById('carImagePreview');
  const hasCarImage = carImagePreview && carImagePreview.src && 
    !carImagePreview.src.includes('default') && 
    !carImagePreview.src.includes('fff-1.png') &&
    carImagePreview.naturalWidth > 1;
  

  
  // Remove inline styles first to avoid conflicts
  container.removeAttribute('style');
  button.removeAttribute('style');
  
  // Force hide container first, then show based on conditions
  // Use !important to override any server-side inline styles
  container.style.setProperty('display', 'none', 'important');
  
  if (isThirdImageLoaded) {
    // Third image is loaded - show container and image, hide button
    batchUIUpdates([
      () => container.style.setProperty('display', 'block', 'important'),
      () => button.style.setProperty('display', 'none', 'important'),
      () => setElementStyles(preview, { display: 'block' })
    ]);
  } else if (hasCarImage) {
    // No third image but car image exists - show button, hide container and image, enable button
    batchUIUpdates([
      () => container.style.setProperty('display', 'none', 'important'),
      () => button.style.setProperty('display', 'inline-block', 'important'),
      () => setElementStyles(preview, { display: 'none' }),
      () => {
        button.disabled = false;
        button.classList.remove('disabled');
      }
    ]);
  } else {
    // No car image - disable and hide button, hide container and image
    batchUIUpdates([
      () => container.style.setProperty('display', 'none', 'important'),
      () => button.style.setProperty('display', 'inline-block', 'important'),
      () => setElementStyles(preview, { display: 'none' }),
      () => {
        button.disabled = true;
        button.classList.add('disabled');
      }
    ]);
  }
}

// Expose globally for external access
window.updateThirdImageDisplay = updateThirdImageDisplay;
window.batchUIUpdates = batchUIUpdates;

