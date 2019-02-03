$(document).on('pageshow', function() {
	if($(".new-application").length){

		$('#lokalizacja').on('change', function(){
			$('a#geo').buttonMarkup({ icon: "alert" });
		});

		$('#msisdn').on('change', function(){
			$('#msisdn').removeClass('error');
		});

		$('#plateId').on('change', function(){
			$('#plateId').removeClass('error');
		});

		$('#comment').on('change', function(){
			$('#comment').removeClass('error');
		});

		if (window.File && window.FileReader && window.FormData) {
			$(document).on('change', "#contextImage", function (e) {
				checkFile(e.target.files[0], this.id);
			});
			$(document).on('change', "#carImage", function (e) {
				checkFile(e.target.files[0], this.id);
			});
		}
		$('#plateImage').hide();

		$('#form-submit').click(function(){
			if(validateForm()){
				$('#form').submit();
			}
		});
	}

	if($("#register-submit").length){
		$('#name').on('change', function(){
			$('#name').removeClass('error');
		});

		$('#register-submit').click(function(){
			if(validateRegisterForm()){
				$('#register-form').submit();
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
		document.getElementById('lokalizacja'),
		{
			types: ['address'],
			componentRestrictions: {country: 'pl'}
		}
	);
	if(trigger_change){
		autocomplete.addListener('place_changed', fillInAddress);
	}
}

function fillInAddress() {
	var place = autocomplete.getPlace();
	setAddress(place.geometry.location.lat() + ',' + place.geometry.location.lng(), false);
}
  
function validateForm(){
	var ret = check($('#plateId'), 6, false);
	ret = checkAddress($('#lokalizacja')) && ret;
	ret = check($('#carImage'), 0, true) && ret;
	ret = check($('#contextImage'), 0, true) && ret;
	if($('#0').is(':checked')){ // if category == 0 then comment is mandatory
		ret = check($('#comment'), 10, false) && ret;
	}
	if(!ret){
		$(window).scrollTop($('.error').offset().top - 100);
	}
	return ret;
}

function checkAddress(){
    var ret = $('#lokalizacja').val().trim().length > 10;
    ret = ($('#locality').val().trim().length > 2) && ret;
    ret = ($('#latlng').val().trim().length > 5) && ret;

    !ret && $('#lokalizacja').addClass('error');
    
    if(!ret && $('#lokalizacja').val().trim().length > 0){
        $('#addressHint').text('(Zacznij wpisywać adres, a potem wybierz pasującą pozycję z listy. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej)');
    }

    return ret;
}

function validateRegisterForm(){
	var ret = check($('#name'), 6, false);
	ret = check($('#lokalizacja'), 10, false) && ret;
	if(!ret){
		$(window).scrollTop($('.error').offset().top - 100);
	}
	return ret;
}

function check(item, length, grandma){
	const val = (item.val().trim().length == 0)?
					((item.attr('value'))? item.attr('value').trim().length: 0):
					item.val().trim().length;
	if(val <= length){
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

function setAddress(latlng, fromPicture){
	$('a#geo').buttonMarkup({ icon: "clock" });
	$('#lokalizacja').val("");
	if(fromPicture){
		$('#lokalizacja').attr("placeholder","(pobieram adres ze zdjęcia...)");
	}else{
		$('#lokalizacja').attr("placeholder","(weryfikuję adres...)");
	}
	$('#administrative_area_level_1').val("");
	$('#country').val("");
	$('#locality').val("");
	$('#latlng').val("");

	$.get("https://maps.googleapis.com/maps/api/geocode/json?latlng=" 
		+ latlng + "&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&language=pl&result_type=street_address", function(data){
			if(data.results.length){
				formatted_address = data.results[0].formatted_address.replace(', Polska', '');
				voivodeship = data.results[0].address_components.filter(function(e){ return e.types.indexOf('administrative_area_level_1') == 0; })[0].long_name.replace('Województwo ', '');
				country = data.results[0].address_components.filter(function(e){ return e.types.indexOf('country') == 0; })[0].long_name;
				city = data.results[0].address_components.filter(function(e){ return e.types.indexOf('locality') == 0; })[0].long_name;
				$('#lokalizacja').val(formatted_address);
				$('#administrative_area_level_1').val(voivodeship);
				$('#country').val(country);
				$('#locality').val(city);
				$('#latlng').val(latlng);

				$('a#geo').buttonMarkup({ icon: "check" });
				$('#lokalizacja').removeClass('error');
			}else{
				$('#lokalizacja').addClass('error');
				$('a#geo').buttonMarkup({ icon: "location" });
			}		
		}).fail(function() {
			$('a#geo').buttonMarkup({ icon: "alert" });
			$('#latlng').val("");
			$('#lokalizacja').addClass('error');
		});
	$('#lokalizacja').attr("placeholder","Miejsce zgłoszenia");
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
	};

	image.onerror = function () {
		imageError(id);
	};
}

function imageError(id){
	$('img#' + id + '-img').attr("src", 'img/cameraContext.png');
	$('#' + id).textinput('enable');
}

function readGeoDataFromImage(file){
	const geoResult = EXIF.getData(file, function() {

		var lat = EXIF.getTag(this, "GPSLatitude");
		var lon = EXIF.getTag(this, "GPSLongitude");
		var latRef = EXIF.getTag(this, "GPSLatitudeRef") || "N";  
		var lonRef = EXIF.getTag(this, "GPSLongitudeRef") || "W";
		if(lat){
			lat = (lat[0] + lat[1]/60 + lat[2]/3600) * (latRef == "N" ? 1 : -1);  
            lon = (lon[0] + lon[1]/60 + lon[2]/3600) * (lonRef == "W" ? -1 : 1); 
            hasGeo = true;
			setAddress(lat + ',' + lon, true);
		}else{
            noGeoDataInImage();
        }

		const dateTime = EXIF.getTag(this, "DateTimeOriginal");
		if(dateTime){
            setDateTime(dateTime);
		}
    });
    if(!geoResult){
        noGeoDataInImage();
    }
}

function noGeoDataInImage(){
    if(/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream){
        $('#addressHint').text('(Uprzejmie Donoszę na iOS nie jest w stanie wyciągnąć adresu z twoich zdjęć)');    
    }else{
        $('#addressHint').html('(Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.)');
    }
}

function setDateTime(dateTime){
	$('#datetime').val(dateTime);
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
			if(json.carImage) {
				$('img#carImage-img').attr("src", json.carImage.thumb);
				$('#carImage').textinput('disable');
			}
			if(json.contextImage) {
				$('img#contextImage-img').attr("src", json.contextImage.thumb);
				$('#contextImage').textinput('disable'); 
			}
			if(id == 'carImage' && json.carInfo){
				if(json.carInfo.plateId) {
					$('#plateId').val(json.carInfo.plateId);
					$('#plateId').removeClass('error');
				}
				if(json.carInfo.plateImage){
					$('#plateImage').attr("src", json.carInfo.plateImage);
					$('#plateImage').show();
				}else{
					$('#plateImage').hide();
				}
				if(json.carInfo.recydywa && json.carInfo.recydywa > 0){
					$('#recydywa').text("Recydywa: " + json.carInfo.recydywa + "");
				}
			}
			// trying if backend returns geo data
			if(id == 'contextImage' && json.address){
				// checking if the address hasn't been set yet
				if($('#latlng').val() === ""){
					setAddress(json.address.latlng, true);
				}
			}
		},
		error: function (data) {
			imageError(id);
		}
	});
}
