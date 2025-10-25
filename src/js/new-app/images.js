import heic2any from "heic2any"
import ExifReader from 'exifreader'

import { setAddressByLatLng } from "../lib/geolocation";
import { setDateTime } from "./set-datetime";
import Api from '../lib/Api'

import * as Sentry from "@sentry/browser";
import { error } from "../lib/toast";
import isIOS from "../lib/isIOS";
import { updateRecydywa } from "./recydywa";

var uploadInProgress = 0;

/**
 * @param {File} file
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id
 * @returns void
 */
export async function checkFile(file, id) {
  if (!file) return

  uploadStarted(id);
  if (!/^image\//i.test(file.type)) {
    console.error(file.type)
    return imageError(id, `Zdjęcie o niepoprawnym type ${file.type}`);
  }

  const imageToResize = document.createElement('img')

  imageToResize.src = await imageToDataUri(file)
  imageToResize.addEventListener("load", async () => {
    try {
      const resizedImage = resizeImage(imageToResize)
      const previewElement = /** @type {HTMLImageElement} */ (document.getElementById(`${id}Preview`))
      if (previewElement) {
        previewElement.style.opacity = '0.3'
        previewElement.src = resizedImage
      }

      if (id === "carImage") {
        const exif = await ExifReader.load(file)
        const [lat, lng] = readGeoDataFromExif(exif)
        let dateTime = getDateTimeFromExif(exif)

        dateTime = setDateTime(dateTime, !!dateTime)
        if (lat) setAddressByLatLng(lat, lng, "picture")
        else noGeoDataInImage()

        const plateImage = /** @type {HTMLImageElement} */ (document.getElementById("plateImage"))
        if (plateImage) {
          plateImage.src = ""
          plateImage.style.display = 'none'
        }
        await sendFile(resizedImage, id, {
          dateTime,
          dtFromPicture: !!dateTime,
          latLng: `${lat},${lng}`
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
  })

}

/**
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id
 */
function uploadStarted(id) {
  const section = document.querySelector(`.${id}Section`)
  if (section) {
    section.classList.remove("error")
    const img = section.querySelector('img')
    const loader = section.querySelector('.loader')
    if (img) img.style.display = 'none'
    if (loader) {
      loader.style.display = 'block'
      loader.classList.add("l")
    }
  }
  
  if (id == "carImage") {
    const recydywa = document.getElementById("recydywa")
    const plateId = document.getElementById("plateId")
    const plateBox = document.querySelector('.plate-box')
    
    if (recydywa) recydywa.style.display = 'none'
    if (plateId) plateId.className = ''
    if (plateBox) plateBox.style.display = 'none'
  }
  uploadInProgress++;
  checkUploadInProgress();
}

function uploadFinished() {
  uploadInProgress--;
  checkUploadInProgress();
}

function checkUploadInProgress() {
  const formSubmit = document.getElementById("form-submit")
  if (uploadInProgress <= 0) {
    uploadInProgress = 0;
    if (formSubmit) formSubmit.classList.remove("disabled")
    return
  }
  if (formSubmit) formSubmit.classList.add("disabled")
}

/**
 * 
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id 
 * @param {string} errorMsg
 */
function imageError(id, errorMsg) {
  const section = document.querySelector(`.${id}Section`)
  const loader = document.querySelector(`.${id}Section .loader`)
  const preview = /** @type {HTMLImageElement} */ (document.getElementById(`${id}Preview`))
  
  if (loader) loader.style.display = 'none'
  if (section) section.classList.add("error")
  if (preview) {
    preview.src = 'img/fff-1.png'
    preview.style.opacity = '1'
    preview.style.display = 'block'
  }
  if (errorMsg) error(errorMsg)
  uploadFinished()
}

function readGeoDataFromExif(exif) {
  const lat = exif?.GPSLatitude?.description
  const lng = exif?.GPSLongitude?.description
  return [lat, lng]
}

function getDateTimeFromExif(exif) {
  const dateTime = exif.DateTimeOriginal || exif.CreateDate
    || exif.DateTimeDigitized || exif.DateCreated
    || exif.DateTimeCreated || exif.DigitalCreationDateTime
    || exif.DateTime
  return dateTime?.description
}

async function imageToDataUri(img) {
  if (img.type.includes('hei')) {
    const blob = await heic2any({ blob: img, toType: "image/jpeg" })
    return URL.createObjectURL(blob)
  } else {
    return await pngToDataUri(img)
  }
}

function pngToDataUri(field) {
  return new Promise((resolve) => {
    const reader = new FileReader();

    reader.addEventListener("load", () => {
      resolve(reader.result);
    });

    reader.readAsDataURL(field);
  });
}

function resizeImage(imgToResize) {
  const canvas = document.createElement("canvas");
  const context = canvas.getContext("2d");

  const MAX_WIDTH = 1200;
  const MAX_HEIGHT = 1200;
  let canvasWidth = imgToResize.width;
  let canvasHeight = imgToResize.height;

  // Add the resizing logic
  if (canvasWidth > canvasHeight) {
    if (canvasWidth > MAX_WIDTH) {
      canvasHeight *= MAX_WIDTH / canvasWidth;
      canvasWidth = MAX_WIDTH;
    }
  } else {
    if (canvasHeight > MAX_HEIGHT) {
      canvasWidth *= MAX_HEIGHT / canvasHeight;
      canvasHeight = MAX_HEIGHT;
    }
  }

  canvas.width = canvasWidth;
  canvas.height = canvasHeight;

  context?.drawImage(
    imgToResize,
    0,
    0,
    canvasWidth,
    canvasHeight
  );
  return canvas.toDataURL("image/jpeg", 0.95);
}

function noGeoDataInImage() {
  const addressHint = document.getElementById("addressHint")
  if (!addressHint) return
  
  if (isIOS()) {
    addressHint.textContent = "Uprzejmie Donoszę na iOS nie jest w stanie pobrać adresu z twoich zdjęć"
  } else if (/Chrome/.test(navigator.userAgent) &&
    /Android/.test(navigator.userAgent)) {
    addressHint.innerHTML = 'Przeglądarka Chrome na Androidzie zapewne usunęła znaczniki geolokalizacji, <a href="/aplikacja.html">zainstaluj Firefox-a</a>.'
  } else {
    addressHint.innerHTML = 'Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" target="_blank" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.'
  }
  addressHint.classList.add("hint")
}

/**
 * @param {*} vehicleBox {x, y, width, height} of box in which the car is located
 * @param {number} imageWidth real image file width
 * @param {number} imageHeight real image file height
 * @returns 
 */
export function repositionCarImage(vehicleBox, imageWidth, imageHeight) {
  if (!vehicleBox.width) return

  const carImagePreview = /** @type {HTMLImageElement} */ (document.querySelector('img#carImagePreview'))
  if (!carImagePreview) return
  
  const trimBoxWidth = carImagePreview.offsetWidth // trim box width
  const trimBoxHeight = 200 // trim box height
  const ratio = trimBoxWidth / imageWidth // scaling factor of rendered image

  const middleOfCar = parseInt(vehicleBox.y) + parseInt(vehicleBox.height) / 2
  let offsetY = middleOfCar * ratio - trimBoxHeight / 2
  // don't move the image outside of the trim box
  if (offsetY > trimBoxHeight / 2)
    offsetY = trimBoxHeight / 2 - 5

  carImagePreview.style.objectPosition = `0% -${offsetY}px`
  carImagePreview.style.height = "100%"

  const plateBox = /** @type {HTMLElement} */ (document.querySelector('.plate-box'))
  if (plateBox) {
    plateBox.style.left = 100 * vehicleBox.x / imageWidth + '%'
    plateBox.style.width = 100 * vehicleBox.width / imageWidth + '%'
    plateBox.style.top = vehicleBox.y * ratio - offsetY + 'px'
    plateBox.style.height = vehicleBox.height * ratio + 'px'
    plateBox.style.border = '2px solid #e9c200'
    plateBox.style.display = 'block'
  }
}

/**
 * @param {*} fileData 
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id 
 * @param {*} imageMetadata 
 */
async function sendFile(fileData, id, imageMetadata={}) {
  const appIdElement = /** @type {HTMLInputElement} */ (document.querySelector(".new-application #applicationId"))
  const appId = appIdElement?.value
  var data = {
    image_data: fileData,
    pictureType: id
  }

  if (id == "carImage") {
    imageMetadata.dateTime && (data.dateTime = imageMetadata.dateTime)
    imageMetadata.dtFromPicture && (data.dtFromPicture = imageMetadata.dtFromPicture)
    imageMetadata.latLng && (data.latLng = imageMetadata.latLng)
  }
  if (id == "thirdImage") {
    showThirdImage(true)
  }

  const comment = /** @type {HTMLTextAreaElement} */ (document.getElementById("comment"))
  const plateImage = /** @type {HTMLImageElement} */ (document.getElementById("plateImage"))
  const plateHint = document.getElementById("plateHint")
  const plateId = /** @type {HTMLInputElement} */ (document.getElementById("plateId"))


  try {
    const api = new Api(`/api/app/${appId}/image`)
    const app = await api.post(data)
    if (app.carImage || app.contextImage || app.thirdImage) {
      const loader = document.querySelector(`.${id}Section .loader`)
      const preview = /** @type {HTMLImageElement} */ (document.getElementById(`${id}Preview`))
      
      if (loader) loader.classList.remove("l")
      if (preview) {
        preview.style.height = "100%"
        preview.style.opacity = "1"
        preview.src = app[id].thumb + "?v=" + Math.random().toString()
      }
    }

    if (id == "carImage" && app.carInfo) {
      const plateBox = /** @type {HTMLElement} */ (document.querySelector('.plate-box'))
      if (plateBox) plateBox.style.border = 'none'

      if (app.carInfo.plateId) {
        if (plateId) plateId.value = app.carInfo.plateId
        repositionCarImage(app.carInfo.vehicleBox, app.carImage.width, app.carImage.height)
        updateRecydywa(appId)

        if (app.carInfo.brand) {
          if (comment && (comment.value + "").trim().length == 0) {
            if (app.carInfo.brandConfidence > 90) {
              comment.value = "Pojazd prawdopodobnie marki " + app.carInfo.brand + "."
            }
            if (app.carInfo.brandConfidence > 98) {
              comment.value = "Pojazd marki " + app.carInfo.brand + "."
            }
          }
        }
        if (plateHint) {
          plateHint.className = "hint"
          plateHint.textContent = "Sprawdź automatycznie pobrany numer rejestracyjny"
        }
      }
      if (app.carInfo.plateImage) {
        if (plateImage) {
          plateImage.src = app.carInfo.plateImage + "?v=" + Math.random().toString()
          plateImage.style.display = 'block'
        }
      } else {
        if (plateImage) plateImage.style.display = 'none'
      }
    }
    uploadFinished()
  } catch (err) {
    imageError(id, err.toString())
  }
}

export async function removeFile(id) {
  const appIdElement = /** @type {HTMLInputElement} */ (document.querySelector(".new-application #applicationId"))
  const appId = appIdElement?.value
  const api = new Api(`/api/app/${appId}/image/${id}`)
  try {
    await api.delete()
    showThirdImage(false)
  } catch (err) {
    showThirdImage(true)
    imageError(id, err.toString())
  }
}

function showThirdImage(show) {
  const thirdImageSections = document.querySelectorAll(".thirdImageSection")
  const thirdImageButtons = document.querySelectorAll(".thirdImageSection.imageButton")
  
  if (show) {
    thirdImageSections.forEach(section => {
      /** @type {HTMLElement} */ (section).style.display = 'block'
    })
    thirdImageButtons.forEach(button => {
      /** @type {HTMLElement} */ (button).style.display = 'none'
    })
  } else {
    thirdImageSections.forEach(section => {
      /** @type {HTMLElement} */ (section).style.display = 'none'
    })
    thirdImageButtons.forEach(button => {
      /** @type {HTMLElement} */ (button).style.display = 'block'
    })
  }
}