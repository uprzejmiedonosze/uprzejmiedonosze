import { checkFile } from "./images";
import { validateForm } from "./validate-form";
import { bindSoftCommentValidation } from "../lib/validation";

// Constants for section class names
const THIRD_IMAGE_SECTION_CLASS = 'thirdImageSection';

export const initHandlers = (map) => {
  // Combined change handler for multiple fields
  ["msisdn", "plateId", "comment", "datetime", "category"].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener("change", function (e) {
        this.classList.remove("error");
        if (this.id === "plateId") {
          const plateImage = document.getElementById("plateImage");
          const recydywa = document.getElementById("recydywa");
          if (plateImage) plateImage.style.display = "none";
          if (recydywa) recydywa.style.display = "none";
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

  // initial validation on load
  validateExtensions();

  bindSoftCommentValidation();

  // File input change handler (event delegation)
  document.addEventListener("change", function (e) {
    const target = e.target;
    if (target && target.matches && target.matches(".image-upload input")) {
      const slotOrder = ["contextImage", "carImage", "thirdImage"];
      let startIdx = slotOrder.indexOf(target.id);
      if (startIdx === -1) startIdx = 0; // fallback
      for (let i = 0; i < Math.min(target.files.length, slotOrder.length - startIdx); i++) {
        checkFile(target.files[i], slotOrder[startIdx + i]);
      }
    }
  });

  // Utility function to get the closest .image-upload ancestor
  function getClosestDropZone(target) {
    return target.closest && target.closest(".image-upload");
  }
  // Consolidated dragover and dragenter support for image upload (all devices)
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
      const slotOrder = ["contextImage", "carImage", "thirdImage"];
      let startIdx = slotOrder.indexOf(id);
      if (startIdx === -1) startIdx = 0; // fallback
      for (let i = 0; i < Math.min(dt.files.length, slotOrder.length - startIdx); i++) {
        checkFile(dt.files[i], slotOrder[startIdx + i]);
      }
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
      const allChangeDatetime = document.querySelectorAll("a.changeDatetime");
      for (const l of allChangeDatetime) {
        l.style.display = "none";
      }
      document.getElementById("datetime")?.removeAttribute('readonly');
    });
  });

  document.querySelectorAll(".image-upload img").forEach(img => {
    img.addEventListener("load", function () {
      img.style.display = "";
    });
  });

  // Allow clicking the third image preview to trigger file input for replacement
  document.querySelectorAll('.imageContainer.image-upload.' + THIRD_IMAGE_SECTION_CLASS).forEach(container => {
    container.addEventListener('click', function (e) {
      // Prevent file dialog if X (remove) button is clicked
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
};


function validateExtensions() {
  // @ts-ignore
  const selectedCategory = document?.querySelector('input[name="category"]:checked')?.value || '0'

  const $extensions = document?.getElementById('extensions')
  const $labels = [... ($extensions?.querySelectorAll('label') || [])]

  $labels.forEach(e => e.classList.remove('disabled'))

  // Cache selectors to avoid repeated DOM queries
  const matchingExtensionSelector = `#ex${selectedCategory}`;
  const matchingExtension = document.querySelector(matchingExtensionSelector);
  const matchingExtensionLabel = matchingExtension ? document.querySelector(`${matchingExtensionSelector} + label`) : null;
  const $comment = document?.getElementById('comment');

  if (matchingExtension) {
    // @ts-ignore
    matchingExtension.checked = false
    matchingExtensionLabel?.classList.add('disabled')
  }

  if (selectedCategory !== '0')
    $comment?.classList.remove('error')
}

// Utility function to get the correct file input id for a drop event
function getDropTargetInputId(dropZone) {
  let fileInput = dropZone.querySelector("input[type='file']");
  let id = fileInput && fileInput.id;
  // If not found, look for a sibling .imageButton.image-upload.thirdImageSection with a file input
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