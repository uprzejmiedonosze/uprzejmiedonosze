import "blueimp-load-image/js/load-image-orientation";
import "blueimp-load-image/js/load-image-scale";
import "blueimp-load-image/js/load-image-meta";
import "blueimp-load-image/js/load-image-exif";
import "blueimp-load-image/js/load-image-exif-map";
import loadImage from "blueimp-load-image/js/load-image";

import { setAddressByLatLng } from "../lib/geolocation";
import { setDateTime } from "./set-datetime";

import * as Sentry from "@sentry/browser";

var uploadInProgress = 0;

export async function checkFile(file, id) {
  if (file) {
    uploadStarted();
    if (/^image\//i.test(file.type)) {
      $("." + id + "Section").removeClass("error");
      $("." + id + "Section img").hide();
      $("." + id + "Section .loader").show();
      $("." + id + "Section .loader").addClass("l");

      let imageMetadata = {}
      if (id == "carImage") {
        imageMetadata = await readGeoDataFromImage(file);
        $("#plateImage").attr("src", "");
        $("#plateImage").hide();
      }
      readFile(file, id, imageMetadata);
    } else {
      imageError(id);
    }
  }
}

function uploadStarted() {
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

function readFile(file, id, imageMetadata) {
  loadImage(
    file,
    function (img) {
      if (img.type == "error") {
        imageError(id);
      }
      try {
        sendFile(img.toDataURL("image/jpeg", 0.9), id, imageMetadata);
      } catch (err) {
        imageError(id);
        Sentry.captureException(err, {
          extra: (typeof img)
        });
      }
    },
    {
      maxWidth: 1200,
      maxHeight: 1200,
      orientation: true,
      canvas: true
    }
  );
}

function imageError(id) {
  $("." + id + "Section img").show();
  $("." + id + "Section .loader").hide();
  $("." + id + "Section").addClass("error");
  $("." + id + "Section input").textinput("enable");
  uploadFinished();
}

async function readGeoDataFromImage(file) {
  const data = await loadImage.parseMetaData(file);

  let dateTime = ""
  let dtFromPicture = false
  let latLng = ""
  if (data.exif) {
    const DateTimeOriginal = data.exif.getText("DateTimeOriginal")
    const DateTimeOriginal2 = (data.exif[34665] && data.exif[34665][36867]) || 'undefined'
    const DateTime = data.exif.getText("DateTime") || 'undefined'

    dateTime = DateTimeOriginal === 'undefined'
      ? (DateTimeOriginal2 === 'undefined' ? DateTime : DateTimeOriginal2)
      : DateTimeOriginal
    if (dateTime && dateTime !== "undefined") {
      dtFromPicture = true
    } else {
      dateTime = ""
    }
  } else {
    dateTime = ""
  }
  dateTime = setDateTime(dateTime, dtFromPicture);

  var gpsInfo = data.exif && data.exif.get("GPSInfo");
  console.log('gpsInfo', gpsInfo)
  if (!gpsInfo) {
    noGeoDataInImage();
    console.log('return', {
      dateTime,
      dtFromPicture
    })
    return {
      dateTime,
      dtFromPicture
    }
  }
  var lat = gpsInfo.get("GPSLatitude");
  var lng = gpsInfo.get("GPSLongitude");
  var latRef = gpsInfo.get("GPSLatitudeRef") || "N";
  var lonRef = gpsInfo.get("GPSLongitudeRef") || "W";
  if (lat && Array.isArray(lat) && lat[0]) {
    lat =
      (parseFloat(lat[0]) +
        parseFloat(lat[1]) / 60 +
        parseFloat(lat[2]) / 3600) *
      (latRef == "N" ? 1 : -1);
    lng =
      (parseFloat(lng[0]) +
        parseFloat(lng[1]) / 60 +
        parseFloat(lng[2]) / 3600) *
      (lonRef == "W" ? -1 : 1);
    latLng = lat + "," + lng
    setAddressByLatLng(lat, lng, "picture");
  } else {
    noGeoDataInImage();
  }
  return {
    dateTime,
    dtFromPicture,
    latLng
  }
}

function noGeoDataInImage() {
  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
    $("#addressHint").text(
      "Uprzejmie Donoszę na iOS nie jest w stanie pobrać adresu z twoich zdjęć"
    );
  } else if (
    /Chrome/.test(navigator.userAgent) &&
    /Android/.test(navigator.userAgent)
  ) {
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
  }

  $.ajax({
    type: "POST",
    url: "/api/api.html",
    data: formData,
    contentType: false,
    processData: false,
    success: function (json) {
      if (json.carImage || json.contextImage) {
        $("." + id + "Section .loader").removeClass("l");
        $("." + id + "Section .loader").hide();
        $("." + id + "Section img").css("height", "100%");
        $("." + id + "Section img").attr(
          "src",
          json[id].thumb + "?v=" + Math.random().toString()
        );
      }
      if (id == "carImage" && json.carInfo) {
        if (json.carInfo.plateId) {
          $("#plateId").val(json.carInfo.plateId);
          if (json.carInfo.brand) {
            if ($("#comment").val().trim().length == 0) {
              if (json.carInfo.brandConfidence > 90) {
                $("#comment").val(
                  "Pojazd prawdopodobnie marki " + json.carInfo.brand + "."
                );
              }
              if (json.carInfo.brandConfidence > 98) {
                $("#comment").val("Pojazd marki " + json.carInfo.brand + ".");
              }
            }
          }
          $("#plateHint").text(
            "Sprawdź automatycznie pobrany numer rejestracyjny"
          );
          $("#plateHint").addClass("hint");
          $("#plateId").removeClass("error");
        }
        if (json.carInfo.plateImage) {
          $("#plateImage").attr(
            "src",
            json.carInfo.plateImage + "?v=" + Math.random().toString()
          );
          $("#plateImage").show();
        } else {
          $("#plateImage").hide();
        }
        if (json.carInfo.recydywa && json.carInfo.recydywa > 0) {
          $("#recydywa").text(
            "recydywista, zgłoszeń: " + json.carInfo.recydywa
          );
          $("#recydywa").show();
        }
      }
      uploadFinished();
    },
    // eslint-disable-next-line no-unused-vars
    error: function (data) {
      imageError(id);
    }
  });
}
