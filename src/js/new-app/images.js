import heic2any from "heic2any"
import ExifReader from 'exifreader'

import { setAddressByLatLng } from "../lib/geolocation";
import { setDateTime } from "./set-datetime";

import * as Sentry from "@sentry/browser";

var uploadInProgress = 0;

export async function checkFile(file, id) {
  if (file) {
    uploadStarted(id);
    if (!/^image\//i.test(file.type)) {
      console.error(file.type)
      return imageError(id, `Zdjęcie o niepoprawnym type ${file.type}`);
    }

    const imageToResize = new Image()

    imageToResize.onload = async () => {
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
          if(lat) setAddressByLatLng(lat, lng, "picture")
          else noGeoDataInImage()
    
          $("#plateImage").attr("src", "");
          $("#plateImage").hide();
          sendFile(resizedImage, id, {
            dateTime,
            dtFromPicture: !!dateTime,
            latLng: `${lat},${lng}`
          });
        } else {
          sendFile(resizedImage, id);
        }
        
      } catch (err) {
        console.error(err)
        imageError(id, err);
        Sentry.captureException(err, {
          extra: Object.prototype.toString.call(file)
        });
      }
    }

    imageToResize.src = await imageToDataUri(file)
  }
}

function uploadStarted(id) {
  $(`.${id}Section`).removeClass("error");
  $(`.${id}Section img`).hide();
  $(`.${id}Section .loader`).show().addClass("l");;
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

function imageError(id, errorMsg) {
  $(`.${id}Section .loader`).hide();
  $(`.${id}Section`).addClass("error");
  $(`.${id}Section input`).textinput("enable");
  $(`#${id}Preview`).attr('src', 'img/fff-1.png').css('opacity', 1).show();
  if (errorMsg) alert(errorMsg)
  uploadFinished();
}

async function getExif(img) {
  return new Promise(resolve =>
    EXIF.getData(img, function() {
      resolve(EXIF.getAllTags(this)); 
    }
  ))
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
  if (img.type.includes('hei')){
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
  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
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

function sendFile(fileData, id, imageMetadata) {
  var formData = new FormData();

  formData.append("action", "upload");
  formData.append("image_data", fileData);
  formData.append("pictureType", id);
  formData.append("applicationId", $("#applicationId").val());

  if (id == "carImage") {
    imageMetadata.dateTime && formData.append("dateTime", imageMetadata.dateTime);
    imageMetadata.dtFromPicture && formData.append("dtFromPicture", imageMetadata.dtFromPicture);
    imageMetadata.latLng && formData.append("latLng", imageMetadata.latLng);
    $("#recydywa").hide();
    $("#plateId").removeClass();
  }

  $.ajax({
    type: "POST",
    url: "/api/api.html",
    data: formData,
    contentType: false,
    processData: false,
    success: function (app) {
      if (app.carImage || app.contextImage) {
        $(`.${id}Section .loader`).removeClass("l").hide()
        $(`#${id}Preview`)
          .css("height", "100%")
          .css("opacity", 1)
          .attr("src", app[id].thumb + "?v=" + Math.random().toString())
      }
      if (id == "carImage" && app.carInfo) {
        if (app.carInfo.plateId) {
          $("#plateId").val(app.carInfo.plateId);
          if (app.carInfo.brand) {
            if ($("#comment").val().trim().length == 0) {
              if (app.carInfo.brandConfidence > 90) {
                $("#comment").val(
                  "Pojazd prawdopodobnie marki " + app.carInfo.brand + "."
                );
              }
              if (app.carInfo.brandConfidence > 98) {
                $("#comment").val("Pojazd marki " + app.carInfo.brand + ".");
              }
            }
          }
          $("#plateHint").removeClass();
          if (app.alpr === 'paid') {
            $("#plateHint").text(
              "Sprawdź automatycznie pobrany numer rejestracyjny"
            );
            $("#plateHint").addClass("hint");
          } else {
            $("#plateHint").html(
              'Użyto słabszego algorytmu rozpoznawania tablic! Sprawdź automatycznie pobrany' +
              ' numer rejestracyjny <a href="https://patronite.pl/uprzejmiedonosze#goals" target="_blank">(więcej)</a>.'
            );
            $("#plateHint").addClass("warning");
            $("#plateId").addClass("warning");
          }
        }
        if (app.carInfo.plateImage) {
          $("#plateImage").attr(
            "src",
            app.carInfo.plateImage + "?v=" + Math.random().toString()
          );
          $("#plateImage").show();
        } else {
          $("#plateImage").hide();
        }
        if (app.carInfo.recydywa && app.carInfo.recydywa > 0) {
          $("#recydywa").text(
            "recydywista, zgłoszeń: " + app.carInfo.recydywa
          );
          $("#recydywa").show();
        }
      }
      uploadFinished();
    },
    error: function (err) {
      imageError(id, err.responseJSON?.message);
    }
  });
}
