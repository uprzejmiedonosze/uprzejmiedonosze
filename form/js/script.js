$(document).on('pageshow', function() {
	if($("#address").length){
		$('#address').on('change', function(){
			$('#address').removeClass('error');
		});
	}
	if($("#msisdn").length){
		$('#msisdn').on('change', function(){
			$('#msisdn').removeClass('error');
		});
	}
	if($("#name").length){
		$('#name').on('change', function(){
			$('#name').removeClass('error');
		});
	}
	if($('#plateId').length){
		$('#plateId').on('change', function(){
			$('#plateId').removeClass('error');
		});
	}
	if($('#contextImage').length){
		if (window.File && window.FileReader && window.FormData) {
			$(document).on('change', "#contextImage", function (e) {
				checkFile(e.target.files[0], this.id);
			});
			$(document).on('change', "#carImage", function (e) {
				checkFile(e.target.files[0], this.id);
			});
		}
		$('#plateImage').hide();
	}
	if($('#form-submit').length){
		$('#form-submit').click(function(){
			if(validateForm()){
				$('#form').submit();
			}
		});
	}
	if($('#register-submit').length){
		$('#register-submit').click(function(){
			if(validateRegisterForm()){
				$('#register-form').submit();
			}
		});		
	}
	if($('#geocomplete').length){
		$('#geocomplete').click(function(){
			if (navigator.geolocation) {
				navigator.geolocation.getCurrentPosition(function(position) {
				  setAddress(position.coords.latitude, position.coords.longitude, false);
				});
			  }
		});
	}
});

var placeSearch, autocomplete;
var componentForm = {
  street_number: 'short_name',
  route: 'long_name',
  locality: 'long_name',
  administrative_area_level_1: 'short_name',
  country: 'long_name',
  postal_code: 'short_name'
};

function initAutocompleteOnRegister() {
	initAutocomplete(false);
}

function initAutocompleteOnNewApplication(){
	initAutocomplete(true);
}

function initAutocomplete(trigger_change) {
	autocomplete = new google.maps.places.Autocomplete(
		/** @type {!HTMLInputElement} */(document.getElementById('address')),
		{types: ['geocode']});
	if(trigger_change){
		autocomplete.addListener('place_changed', fillInAddress);
	}
}

function fillInAddress() {
	var place = autocomplete.getPlace();
	setAddress(place.geometry.location.lat(), place.geometry.location.lng(), false);
}
  
function validateForm(){
	var ret = check($('#plateId'), 6, false);
	ret = check($('#address'), 10, false) && ret;
	ret = check($('#carImage'), 0, true) && ret;
	ret = check($('#contextImage'), 0, true) && ret;
	if(!ret){
		$(window).scrollTop($('.error').offset().top - 100);
	}
	return ret;
}
function validateRegisterForm(){
	var ret = check($('#name'), 6, false);
	ret = check($('#address'), 10, false) && ret;
	ret = check($('#msisdn'), 8, false) && ret;
	if(!ret){
		$(window).scrollTop($('.error').offset().top - 100);
	}
	return ret;
}

function check(item, length, grandma){
	if(item.val().trim().length <= length){
		if(grandma){
			item.parent().parent().addClass('error');
		}else{
			item.addClass('error');
		}
		return false;
	}else{
		return true;
	}
}

function setAddress(lat, lng, fromPicture){
	$('a#geo').buttonMarkup({ icon: "clock" });
	$('#address').val("");
	if(fromPicture){
		$('#address').attr("placeholder","(pobieram adres ze zdjęcia...)");
	}else{
		$('#address').attr("placeholder","(weryfikuję address...)");
	}
	$('#administrative_area_level_1').val("");
	$('#country').val("");
	$('#locality').val("");
	$('#latlng').val("");

	$.get("https://maps.googleapis.com/maps/api/geocode/json?latlng=" 
		+ lat + "," + lng + "&key=AIzaSyAsVCGVrc7Zph5Ka3Gh2SGUqDrwCd8C3DU&language=pl&result_type=street_address", function(data){
			if(data.results){
				formatted_address = data.results[0].formatted_address.replace(', Polska', '');
				voivodeship = data.results[0].address_components.filter(function(e){ return e.types.indexOf('administrative_area_level_1') == 0; })[0].long_name.replace('Województwo ', '');
				country = data.results[0].address_components.filter(function(e){ return e.types.indexOf('country') == 0; })[0].long_name;
				city = data.results[0].address_components.filter(function(e){ return e.types.indexOf('locality') == 0; })[0].long_name;
				$('#address').val(formatted_address);
				$('#administrative_area_level_1').val(voivodeship);
				$('#country').val(country);
				$('#locality').val(city);
				$('#latlng').val(lat + "," + lng);

				$('a#geo').buttonMarkup({ icon: "check" });
			}else{
				$('a#geo').buttonMarkup({ icon: "location" });
			}
			$('#address').attr("placeholder","Miejsce zgłoszenia");
		}).fail(function() {
			$('a#geo').buttonMarkup({ icon: "alert" });
			$('#latlng').val("");		
		});
}

function checkFile(file, id){
	if(id == "contextImage"){
		readGeoDataFromImage(file);
	}

	$('#' + id).parent().parent().removeClass('error');
	$('img#' + id + '-img').attr("src", 'img/loading.gif');
	if(id == 'carImage'){
		$('#plateImage').attr("src", "");
		$('#plateImage').hide();
	}
	if (file) {
		if (/^image\//i.test(file.type)) {
			readFile(file, id);
		} else {
			imageError(id);
		}
	}
}

function readFile(file, id) {
	var reader = new FileReader();

	reader.onloadend = function () {
		processFile(reader.result, file.type, id);
	}
	reader.onerror = function () {
		imageError(id);
	}
	reader.readAsDataURL(file);
}

function processFile(dataURL, fileType, id) {
	var maxWidth = 1200, maxHeight = 1200, image = new Image();
	image.src = dataURL;

	image.onload = function () {
		var width = image.width, height = image.height;
		var shouldResize = (width > maxWidth) || (height > maxHeight);

		if (!shouldResize) {
			sendFile(dataURL, id);
			//sendFileToFirebase(dataURL, id);
			return;
		}

		var newWidth, newHeight;

		if (width > height) {
			newHeight = height * (maxWidth / width);
			newWidth = maxWidth;
		} else {
			newWidth = width * (maxHeight / height);
			newHeight = maxHeight;
		}

		var canvas = document.createElement('canvas');

		canvas.width = newWidth;
		canvas.height = newHeight;

		var context = canvas.getContext('2d');

		context.drawImage(this, 0, 0, newWidth, newHeight);

		dataURL = canvas.toDataURL(fileType);
		sendFile(dataURL, id);
		//sendFileToFirebase(dataURL, id);
	};

	image.onerror = function () {
		imageError(id);
	};
}

function imageError(id){
	$('img#' + id + '-img').attr("src", 'img/camera.png');
	$('#' + id).textinput('enable');
}

//function sendFileToFirebase(data, id){
//	applicationId = $('#applicationId').val();
//	imageName = 'images/' + applicationId + '-' + id +'.jpg';
//	image = firebase.storage().ref().child(imageName);
//	image.putString(data, 'data_url', {contentType: 'image/jpg'}).then(function(snapshot) {
//		image.getDownloadURL().then(function(url) {
//			$('img#' + id + '-img').attr("src", url);
//			$('#' + id).textinput('disable');
//
//			if(id == 'carImage'){
//				readPlateFromImage(url);
//			}
//
//		}).catch(function(error) {
//			imageError(id);
//		});
//	});
//}

function readPlateFromImage(url){
	$.ajax({
		type: 'POST',
		url: 'https://us-central1-uprzejmiedonosze-1494607701827.cloudfunctions.net/readPlate',
		data: { url: url },
		success: function (data) {
			$('#plateId').val(data.plate);
		}
	});
}

function readGeoDataFromImage(file){
	EXIF.getData(file, function() {

		var lat = EXIF.getTag(this, "GPSLatitude");
		var lon = EXIF.getTag(this, "GPSLongitude");
		var latRef = EXIF.getTag(this, "GPSLatitudeRef") || "N";  
		var lonRef = EXIF.getTag(this, "GPSLongitudeRef") || "W";
		if(lat){
			lat = (lat[0] + lat[1]/60 + lat[2]/3600) * (latRef == "N" ? 1 : -1);  
			lon = (lon[0] + lon[1]/60 + lon[2]/3600) * (lonRef == "W" ? -1 : 1); 
			setAddress(lat, lon, true);
		}
	});
}

function sendFile(fileData, id) {
	var formData = new FormData();

	formData.append('image_data', fileData);
	formData.append('pictureType', id);
	formData.append('applicationId', $('#applicationId').val());

	$.ajax({
		type: 'POST',
		url: '/upload.html',
		data: formData,
		contentType: false,
		processData: false,
		success: function (data) {
			json = $.parseJSON(data);

			if(json.carImage) {
				$('img#' + id + '-img').attr("src", json.carImage.thumb);
				$('#' + id).textinput('disable');
			}
			if(json.contextImage) {
				$('img#' + id + '-img').attr("src", json.contextImage.thumb);
				$('#' + id).textinput('disable');
			}
			if(id == 'carImage' && json.carInfo){
				if(json.carInfo.plateId) {
					$('#plateId').val(json.carInfo.plateId);
				}
				if(json.carInfo.plateImage){
					$('#plateImage').attr("src", json.carInfo.plateImage);
					$('#plateImage').show();
				}
			} 
		},
		error: function (data) {
			imageError(id);
		}
	});
}