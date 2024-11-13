import heic2any from "heic2any"
import ExifReader from 'exifreader'

import { setAddressByLatLng } from "../lib/geolocation";
import { setDateTime } from "./set-datetime";
import Api from '../lib/Api'

import * as Sentry from "@sentry/browser";
import { showError } from "../lib/showMessage";
import isIOS from "../lib/isIOS";

var uploadInProgress = 0;

/**
 * @param {File} file 
 * @param {'contextImage' | 'carImage'} id 
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
      $(`#${id}Preview`)
        .css('opacity', 0.3)
        .attr('src', resizedImage)

      if (id === "carImage") {
        const exif = await ExifReader.load(file)
        const [lat, lng] = readGeoDataFromExif(exif)
        let dateTime = getDateTimeFromExif(exif)

        dateTime = setDateTime(dateTime, !!dateTime)
        if (lat) setAddressByLatLng(lat, lng, "picture")
        else noGeoDataInImage()

        $("#plateImage").attr("src", "");
        $("#plateImage").hide();
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
 * @param {'contextImage' | 'carImage'} id 
 */
function uploadStarted(id) {
  $(`.${id}Section`).removeClass("error");
  $(`.${id}Section img`).hide();
  $(`.${id}Section .loader`).show().addClass("l");
  if (id == "carImage") {
    $("#recydywa").hide()
    $("#plateId").removeClass()
    $('.plate-box').hide()
  }
  uploadInProgress++;
  checkUploadInProgress();
}

function uploadFinished() {
  uploadInProgress--;
  checkUploadInProgress();
}

function checkUploadInProgress() {
  if (uploadInProgress <= 0) {
    uploadInProgress = 0;
    return $("#form-submit").removeClass("ui-disabled");
  }
  $("#form-submit").addClass("ui-disabled");
}

/**
 * 
 * @param {'contextImage' | 'carImage'} id 
 * @param {string} errorMsg
 */
function imageError(id, errorMsg) {
  $(`.${id}Section .loader`).hide();
  $(`.${id}Section`).addClass("error");
  $(`.${id}Section input`).textinput("enable");
  $(`#${id}Preview`).attr('src', 'img/fff-1.png').css('opacity', 1).show();
  if (errorMsg) showError(errorMsg)
  uploadFinished();
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

  context.drawImage(
    imgToResize,
    0,
    0,
    canvasWidth,
    canvasHeight
  );
  return canvas.toDataURL("image/jpeg", 0.95);
}

function noGeoDataInImage() {
  if (isIOS()) {
    $("#addressHint").text(
      "Uprzejmie Donoszę na iOS nie jest w stanie pobrać adresu z twoich zdjęć"
    );
  } else if (/Chrome/.test(navigator.userAgent) &&
    /Android/.test(navigator.userAgent)) {
    $("#addressHint").html(
      'Przeglądarka Chrome na Androidzie zapewne usunęła znaczniki geolokalizacji, <a data-ajax="false" href="/aplikacja.html">zainstaluj Firefox-a</a>.'
    );
  } else {
    $("#addressHint").html(
      'Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.'
    );
  }
  $("#addressHint").addClass("hint");
}

/**
 * @param {*} vehicleBox {x, y, width, height} of box in which the car is located
 * @param {number} imageWidth real image file width
 * @param {number} imageHeight real image file height
 * @returns 
 */
export function repositionCarImage(vehicleBox, imageWidth, imageHeight) {
  if (!vehicleBox.width) return

  const $carImagePreview = $('img#carImagePreview')
  const trimBoxWidth = $carImagePreview.width() // trim box width
  const trimBoxHeight = 200 //$carImagePreview.height() // trim box height
  const ratio = trimBoxWidth / imageWidth // scaling factor of rendered image

  const middleOfCar = parseInt(vehicleBox.y) + parseInt(vehicleBox.height) / 2
  let offsetY = middleOfCar * ratio - trimBoxHeight / 2
  // don't move the image outside of the trim box
  if (offsetY > trimBoxHeight / 2)
    offsetY = trimBoxHeight / 2 - 5

  $carImagePreview.css('object-position', `0% -${offsetY}px`)
  $carImagePreview.css("height", "100%")


  const $plateBox = $('.plate-box')
  $plateBox.css('left', 100 * vehicleBox.x / imageWidth + '%')
  $plateBox.css('width', 100 * vehicleBox.width / imageWidth + '%')
  $plateBox.css('top', vehicleBox.y * ratio - offsetY + 'px')
  $plateBox.css('height', vehicleBox.height * ratio + 'px')
  $plateBox.css('border', '2px solid #e9c200')
  $plateBox.show()
}

/**
 * 
 * @param {*} fileData 
 * @param {'contextImage' | 'carImage'} id 
 * @param {*} imageMetadata 
 */
async function sendFile(fileData, id, imageMetadata={}) {
  const appId = $("#applicationId").val()
  var data = {
    image_data: fileData,
    pictureType: id
  }

  if (id == "carImage") {
    imageMetadata.dateTime && (data.dateTime = imageMetadata.dateTime)
    imageMetadata.dtFromPicture && (data.dtFromPicture = imageMetadata.dtFromPicture)
    imageMetadata.latLng && (data.latLng = imageMetadata.latLng)
  }

  const $comment = $("#comment")
  const $plateImage = $("#plateImage")
  const $plateHint = $("#plateHint")
  const $plateId = $("#plateId")
  const $recydywa = $("#recydywa")

  try {
    const api = new Api(`/api/app/${appId}/image`)
    const app = await api.post(data)
    if (app.carImage || app.contextImage) {
      $(`.${id}Section .loader`).removeClass("l")
      $(`#${id}Preview`)
        .css("height", "100%")
        .css("opacity", 1)
        .attr("src", app[id].thumb + "?v=" + Math.random().toString())
    }
    if (id == "carImage" && app.carInfo) {
      $('.plate-box').css('border', 'none')

      if (app.carInfo.plateId) {
        $plateId.val(app.carInfo.plateId);
        repositionCarImage(app.carInfo.vehicleBox, app.carImage.width, app.carImage.height)

        if (app.carInfo.brand) {
          if (($comment?.val() + "").trim().length == 0) {
            if (app.carInfo.brandConfidence > 90) {
              $comment.val(
                "Pojazd prawdopodobnie marki " + app.carInfo.brand + "."
              );
            }
            if (app.carInfo.brandConfidence > 98) {
              $comment.val("Pojazd marki " + app.carInfo.brand + ".");
            }
          }
        }
        $plateHint.removeClass().addClass("hint").text(
          "Sprawdź automatycznie pobrany numer rejestracyjny"
        );
      }
      if (app.carInfo.plateImage) {
        $plateImage.attr(
          "src",
          app.carInfo.plateImage + "?v=" + Math.random().toString()
        ).show();
      } else {
        $plateImage.hide();
      }
      const recydywa = app.carInfo?.recydywa
      if (recydywa?.appsCnt > 0) {
        $recydywa.find('.recydywa-appscnt').text(num(recydywa.appsCnt, ['wykroczeń', 'wykroczenie', 'wykroczenia']))
        $recydywa.show()

        $recydywa.find('.recydywa-userscnt').hide()
        if (recydywa?.usersCnt > 1) {
          $recydywa.find('.recydywa-userscnt').text(num(recydywa.usersCnt, ['zgłaszających', 'zgłaszający', 'zgłaszających'])).show()
        }
      }
    }
    uploadFinished()
  } catch (err) {
    imageError(id, err.toString())
  }
}

/**
 * @param {Number} value 
 * @param {Array} numerals 
 * @returns 
 */
function num(value, numerals) {
	var t0 = value % 10,
		t1 = value % 100,
		vo = [];
  vo.push(value);
	if (value === 1 && numerals[1])
		vo.push(numerals[1]);
	else if ((value == 0 || (t0 >= 0 && t0 <= 1) || (t0 >= 5 && t0 <= 9) || (t1 > 10 && t1 < 20)) && numerals[0])
		vo.push(numerals[0]);
	else if (((t1 < 10 || t1 > 20) && t0 >= 2 && t0 <= 4) && numerals[2])
		vo.push(numerals[2]);
	return vo.join(' ');
};