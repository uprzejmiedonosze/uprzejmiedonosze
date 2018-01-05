$(document).on('pageinit pageshow', function() {
	if($("#address").length){
		//$("a#geo").on('touchstart click', getAddress);
		//getAddress();
		$('#address').on('change', function(){
			$('#address').removeClass('error');
		});
		$('#plateid').on('change', function(){
			$('#plateid').removeClass('error');
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
	}
	if($('#form-submit').length){
		$('#form-submit').click(function(){
			if(validateForm()){
				$('#form').submit();
			}
		});
	}
});

function validateForm(){
	var ret = check($('#plateid'), 6, false);
	ret = check($('#address'), 10, false) && ret;
	ret = check($('#carImage'), 0, true) && ret;
	ret = check($('#contextImage'), 0, true) && ret;
	ret = check($('#name'), 5, false) && ret;
	ret = check($('#email'), 5, false) && ret;
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

function getAddress(latlng){
	$('a#geo').buttonMarkup({ icon: "clock" });
	$('#address').val("Pobieram adres...");

	$.get("https://maps.googleapis.com/maps/api/geocode/json?latlng=" 
		+ latlng + "&key=AIzaSyAsVCGVrc7Zph5Ka3Gh2SGUqDrwCd8C3DU&language=pl&result_type=street_address", function(data){
			if(data.results){
				$('#address').val(data.results[0].formatted_address.replace(', Polska', ''));
				$('a#geo').buttonMarkup({ icon: "refresh" });
				$('#latlng').val(latlng);
			}
		});
}

function checkFile(file, id){
	$('#' + id).parent().parent().removeClass('error');
	$('img#' + id + '-img').attr("src", 'img/loading.gif');
	if(id == 'carImage'){
		$('#plateImage-img').attr("src", "");
	}
	if (file) {
		if (/^image\//i.test(file.type)) {
			readFile(file, id);
		} else {
			$('#' + id).textinput('enable');
			$('img#' + id + '-img').attr("src", 'img/camera.png');
		}
	}
}

function readFile(file, id) {
	var reader = new FileReader();

	reader.onloadend = function () {
		processFile(reader.result, file.type, id);
	}

	reader.onerror = function () {
		$('#' + id).textinput('enable');
		$('img#' + id + '-img').attr("src", 'img/camera.png');
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
		$('img#' + id + '-img').attr("src", 'img/camera.png');
		$('#' + id).textinput('enable');
	};
}

function sendFile(fileData, id) {
	var formData = new FormData();

	formData.append('image_data', fileData);
	formData.append('id', id);

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
			if(json.address.lng){ 
				getAddress(json.address.lat + ',' + json.address.lng);
			}
			if(id == 'carImage' && json.carInfo.plateId) {
				$('#plateid').val(json.carInfo.plateId);
			} 
			if(id == 'carImage' && json.carInfo.plateImage){
				$('#imgpic2Plate').attr("src", json.carInfo.plateImage);
			}
		},
		error: function (data) {
			$('img#' + id + '-img').attr("src", 'img/camera.png');
			$('#' + id).textinput('enable');
		}
	});
}
