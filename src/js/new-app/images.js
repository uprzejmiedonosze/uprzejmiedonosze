import ExifReader from 'exifreader'

import { setAddressByLatLng } from "../lib/geolocation";
import { setDateTime } from "./set-datetime";
import Api from '../lib/Api'
import isIOS from "../lib/isIOS"
import * as Sentry from "@sentry/browser";
import { error } from "../lib/toast";
import { updateRecydywa } from "./recydywa";
import { setOcrVehicleInfo, triggerVehicleInfoEnrichment, appendAutoComment } from "./vehicle-info";

// Matches saveImgAndThumb full-size re-encode quality in API.php (95).
const JPEG_QUALITY = 0.95
const MAX_IMAGE_DIM = 1600

var uploadInProgress = 0;

/**
 * @param {File} file
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id
 * @returns void
 */
export async function checkFile(file, id) {
  if (!file) return

  uploadStarted(id);
  if (!/^image\/(jpeg|png|heic|heif|webp)/i.test(file.type)) {
    return imageError(id, file.type
      ? `Nieobsługiwany format pliku (${file.type}). Wybierz zdjęcie JPEG, PNG lub HEIC.`
      : 'Nieobsługiwany format pliku. Wybierz zdjęcie JPEG, PNG lub HEIC.'
    );
  }

  let previewUrl = null
  try {
    const prepared = await prepareUploadBlob(file)
    previewUrl = prepared.previewUrl

    const previewElement = /** @type {HTMLImageElement} */ (document.getElementById(`${id}Preview`))
    if (previewElement) {
      previewElement.style.opacity = '0.3'
      previewElement.src = previewUrl
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
      await sendFile(prepared.blob, id, {
        dateTime,
        dtFromPicture: !!dateTime,
        latLng: `${lat},${lng}`
      }, previewUrl);
    } else {
      await sendFile(prepared.blob, id, {}, previewUrl);
    }
  } catch (err) {
    revokeObjectUrl(previewUrl)
    imageError(id, err.message || String(err));
    Sentry.captureException(err, {
      extra: { fileType: file.type }
    });
  }

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

async function isHeicFile(file) {
  if (/hei(c|f)/i.test(file.type)) return true
  if (file.size < 12) return false
  const buf = await file.slice(4, 12).arrayBuffer()
  const brand = String.fromCharCode(...new Uint8Array(buf))
  return brand.startsWith('ftyp') && /hei[cfx]|mif1|msf1/.test(brand)
}

function revokeObjectUrl(url) {
  if (url?.startsWith('blob:')) URL.revokeObjectURL(url)
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = document.createElement('img')
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('Nie można wczytać zdjęcia'))
    img.src = src
  })
}

async function getImageDimensions(fileOrBlob) {
  if (typeof createImageBitmap === 'function') {
    const bitmap = await createImageBitmap(fileOrBlob)
    const dimensions = { width: bitmap.width, height: bitmap.height }
    bitmap.close()
    return dimensions
  }

  const url = URL.createObjectURL(fileOrBlob)
  try {
    const img = await loadImage(url)
    return { width: img.width, height: img.height }
  } finally {
    revokeObjectUrl(url)
  }
}

function fitsMaxDimensions(width, height) {
  return width <= MAX_IMAGE_DIM && height <= MAX_IMAGE_DIM
}

async function heicToJpegBlob(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file)
      const canvas = document.createElement('canvas')
      canvas.width = bitmap.width
      canvas.height = bitmap.height
      const ctx = canvas.getContext('2d')
      if (!ctx) throw new Error('Canvas not supported')
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, canvas.width, canvas.height)
      ctx.drawImage(bitmap, 0, 0)
      bitmap.close()
      return new Promise((resolve, reject) =>
        canvas.toBlob(b => b ? resolve(b) : reject(new Error('Conversion failed')), 'image/jpeg', JPEG_QUALITY)
      )
    } catch (err) {
      Sentry.captureException(err, { extra: { heicDecoder: 'native' } })
    }
  }

  const { heicTo } = await import('heic-to')
  return heicTo({ blob: file, type: 'image/jpeg', quality: JPEG_QUALITY })
}

/** @returns {Promise<{ blob: Blob, previewUrl: string }>} */
async function prepareUploadBlob(file) {
  if (await isHeicFile(file)) {
    const jpegBlob = await heicToJpegBlob(file)
    const { width, height } = await getImageDimensions(jpegBlob)
    if (fitsMaxDimensions(width, height)) {
      return { blob: jpegBlob, previewUrl: URL.createObjectURL(jpegBlob) }
    }

    const blob = await resizeBlob(jpegBlob)
    return { blob, previewUrl: URL.createObjectURL(blob) }
  }

  if (/^image\/jpe?g$/i.test(file.type)) {
    const { width, height } = await getImageDimensions(file)
    if (fitsMaxDimensions(width, height)) {
      return { blob: file, previewUrl: URL.createObjectURL(file) }
    }
  }

  const blob = await resizeBlob(file)
  return { blob, previewUrl: URL.createObjectURL(blob) }
}

async function resizeBlob(fileOrBlob) {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(fileOrBlob)
      try {
        return await resizeSource(bitmap)
      } finally {
        bitmap.close()
      }
    } catch (err) {
      Sentry.captureException(err, { extra: { resizeDecoder: 'bitmap' } })
    }
  }

  const decodeUrl = URL.createObjectURL(fileOrBlob)
  try {
    const img = await loadImage(decodeUrl)
    return await resizeSource(img)
  } finally {
    revokeObjectUrl(decodeUrl)
  }
}

/** @param {CanvasImageSource & { width: number, height: number }} source */
function resizeSource(source) {
  const canvas = document.createElement("canvas");
  const context = canvas.getContext("2d");

  let canvasWidth = source.width;
  let canvasHeight = source.height;

  if (canvasWidth > canvasHeight) {
    if (canvasWidth > MAX_IMAGE_DIM) {
      canvasHeight *= MAX_IMAGE_DIM / canvasWidth;
      canvasWidth = MAX_IMAGE_DIM;
    }
  } else {
    if (canvasHeight > MAX_IMAGE_DIM) {
      canvasWidth *= MAX_IMAGE_DIM / canvasHeight;
      canvasHeight = MAX_IMAGE_DIM;
    }
  }

  canvas.width = canvasWidth;
  canvas.height = canvasHeight;

  context?.drawImage(
    source,
    0,
    0,
    canvasWidth,
    canvasHeight
  );
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      blob => blob ? resolve(blob) : reject(new Error('Nie udało się przetworzyć zdjęcia')),
      'image/jpeg',
      JPEG_QUALITY
    )
  })
}

function noGeoDataInImage() {
  const addressHint = document.getElementById("addressHint")
  if (!addressHint) return

  const lokalizacja = /** @type {HTMLInputElement} */ (document.getElementById("lokalizacja"))
  if (lokalizacja) {
    lokalizacja.value = ""
    lokalizacja.placeholder = "(ustaw mapę na miejsce wykroczenia)"
  }

  let platform = "przeglądarka je usunęła"
  if (/Android/.test(navigator.userAgent))
    platform = "Android je usunął"
  if (isIOS())
    platform = "IOS je usunął"

  addressHint.innerHTML = `Twoje zdjęcie nie ma znaczników geolokacji LUB ${platform}. Być może musisz zgłaszać na komputerze.`
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
 * @param {Blob} fileData
 * @param {'contextImage' | 'carImage' | 'thirdImage'} id
 * @param {*} imageMetadata
 * @param {string|null} previewUrl
 */
async function sendFile(fileData, id, imageMetadata={}, previewUrl=null) {
  const appIdElement = /** @type {HTMLInputElement} */ (document.querySelector(".new-application #applicationId"))
  const appId = appIdElement?.value
  const data = new FormData()
  data.append('image', fileData, 'image.jpg')
  data.append('pictureType', id)

  if (id == "carImage") {
    imageMetadata.dateTime && data.append('dateTime', imageMetadata.dateTime)
    imageMetadata.dtFromPicture && data.append('dtFromPicture', 'true')
    imageMetadata.latLng && data.append('latLng', imageMetadata.latLng)
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
    const app = await api.postForm(data)
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
        triggerVehicleInfoEnrichment(app.carInfo.plateId)

        if (app.carInfo.brand) {
          setOcrVehicleInfo({ brand: app.carInfo.brand })
          if (comment && (comment.value + "").trim().length == 0) {
            let brandLine = null;
            if (app.carInfo.brandConfidence > 98) {
              brandLine = "Pojazd marki " + app.carInfo.brand + "."
            } else if (app.carInfo.brandConfidence > 90) {
              brandLine = "Pojazd prawdopodobnie marki " + app.carInfo.brand + "."
            }
            if (brandLine) appendAutoComment(comment, brandLine);
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
  } finally {
    revokeObjectUrl(previewUrl)
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
