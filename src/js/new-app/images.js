import loadImage from 'blueimp-load-image/js/load-image';
import 'blueimp-load-image/js/load-image-orientation';
import 'blueimp-load-image/js/load-image-scale';
import 'blueimp-load-image/js/load-image-meta';
import 'blueimp-load-image/js/load-image-exif';
import 'blueimp-load-image/js/load-image-exif-map';

import { setDateTime } from './set-datetime';
import { setAddressByLatLng } from '../lib/geolocation';

var uploadInProgress = 0;

export function checkFile(file, id) {
  if (file) {
    uploadStarted();
    if (/^image\//i.test(file.type)) {
      $('.' + id + 'Section').removeClass('error');
      $('.' + id + 'Section img').hide();
      $('.' + id + 'Section .loader').show();
      $('.' + id + 'Section .loader').addClass('l');

      if (id == 'carImage') {
        readGeoDataFromImage(file);
        $('#plateImage').attr("src", "");
        $('#plateImage').hide();
      }
      readFile(file, id);
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
    $('#form-submit').removeClass('ui-disabled');
  } else {
    $('#form-submit').addClass('ui-disabled');
  }
}

function readFile(file, id) {
  loadImage(
    file,
    function (img) {
      if (img.type == 'error') {
        imageError(id);
      }
      sendFile(img.toDataURL('image/jpeg', 0.9), id);
    }, {
    maxWidth: 1200,
    maxHeight: 1200,
    orientation: true,
    canvas: true
  }
  );
}

function imageError(id) {
  $('.' + id + 'Section img').show();
  $('.' + id + 'Section .loader').hide();
  $('.' + id + 'Section').addClass('error');
  $('.' + id + 'Section input').textinput('enable');
  uploadFinished();
}

function readGeoDataFromImage(file) {
  loadImage.parseMetaData(
    file,
    function (data) {
      if (data.exif) {
        const dateTime = data.exif.getText("DateTimeOriginal") && data.exif.getText("DateTime");
        if (dateTime && dateTime !== 'undefined') {
          setDateTime(dateTime, true);
        } else {
          setDateTime('', false);
        }
      } else {
        setDateTime('', false);
      }

      var gpsInfo = data.exif && data.exif.get('GPSInfo');
      if (!gpsInfo) {
        noGeoDataInImage();
        return;
      }
      var lat = gpsInfo.get("GPSLatitude");
      var lon = gpsInfo.get("GPSLongitude");
      var latRef = gpsInfo.get("GPSLatitudeRef") || "N";
      var lonRef = gpsInfo.get("GPSLongitudeRef") || "W";
      if (lat && Array.isArray(lat) && lat[0]) {
        lat = (parseFloat(lat[0]) + parseFloat(lat[1]) / 60 + parseFloat(lat[2]) / 3600) * (latRef == "N" ? 1 : -1);
        lon = (parseFloat(lon[0]) + parseFloat(lon[1]) / 60 + parseFloat(lon[2]) / 3600) * (lonRef == "W" ? -1 : 1);
        setAddressByLatLng(lat, lon, 'picture');
      } else {
        noGeoDataInImage();
      }
    }
  );
}

function noGeoDataInImage() {
  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
    $('#addressHint').text('Uprzejmie Donoszę na iOS nie jest w stanie pobrać adresu z twoich zdjęć');
  } else {
    if (/Chrome/.test(navigator.userAgent) && /Android/.test(navigator.userAgent)) {
      $('#addressHint').html('Przeglądarka Chrome na Androidzie zapewne usunęła znaczniki geolokalizacji, <a data-ajax="false" href="/aplikacja.html">zainstaluj Firefox-a</a>.');
    } else {
      $('#addressHint').html('Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.');
    }
  }
  $('#addressHint').addClass('hint');
}

function sendFile(fileData, id) {
  var formData = new FormData();

  formData.append('action', 'upload');
  formData.append('image_data', fileData);
  formData.append('pictureType', id);
  formData.append('applicationId', $('#applicationId').val());

  $.ajax({
    type: 'POST',
    url: '/api/api.html',
    data: formData,
    contentType: false,
    processData: false,
    success: function (json) {
      if (json.carImage || json.contextImage) {
        $('.' + id + 'Section .loader').removeClass('l');
        $('.' + id + 'Section .loader').hide();
        $('.' + id + 'Section img').css('height', '100%');
        $('.' + id + 'Section img').attr("src", json[id].thumb + '?v=' + Math.random().toString());
      }
      if (id == 'carImage' && json.carInfo) {
        if (json.carInfo.plateId) {
          $('#plateId').val(json.carInfo.plateId);
          if (json.carInfo.brand) {
            if ($('#comment').val().trim().length == 0) {
              if (json.carInfo.brandConfidence > 90) {
                $('#comment').val('Pojazd prawdopodobnie marki ' + json.carInfo.brand + '.');
              }
              if (json.carInfo.brandConfidence > 98) {
                $('#comment').val('Pojazd marki ' + json.carInfo.brand + '.');
              }
            }
          }
          $('#plateHint').text('Sprawdź automatycznie pobrany numer rejestracyjny');
          $('#plateHint').addClass('hint');
          $('#plateId').removeClass('error');
        }
        if (json.carInfo.plateImage) {
          $('#plateImage').attr("src", json.carInfo.plateImage + '?v=' + Math.random().toString());
          $('#plateImage').show();
        } else {
          $('#plateImage').hide();
        }
        if (json.carInfo.recydywa && json.carInfo.recydywa > 0) {
          $('#recydywa').text("recydywista, zgłoszeń: " + json.carInfo.recydywa);
          $('#recydywa').show();
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