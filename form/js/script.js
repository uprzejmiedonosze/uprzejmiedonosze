$(document).on('pageinit pageshow', function() {
	if($("#address").length){
		$("a#geo").on('touchstart click', getAddress);
		getAddress();
		$('#address').on('change', function(){
			$('#address').removeClass('error');
		});
		$('#plateid').on('change', function(){
			$('#plateid').removeClass('error');
		});
	}
	if($('#pic1').length){
		if (window.File && window.FileReader && window.FormData) {
			$(document).on('change', "#pic1", function (e) {
				checkFile(e.target.files[0], this.id);
			});
			$(document).on('change', "#pic2", function (e) {
				checkFile(e.target.files[0], this.id);
			});
		} else {
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
	ret = check($('#pic2'), 0, true) && ret;
	ret = check($('#pic1'), 0, true) && ret;;
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

function getAddress(){
	$('a#geo').buttonMarkup({ icon: "clock" });
	$('#address').val("Pobieram adres...");
	if ("geolocation" in navigator) {

		var geoOptions = {
			enableHighAccuracy: true, 
			maximumAge        : 30000, 
			timeout           : 27000
		};

		navigator.geolocation.getCurrentPosition(function(position) {
			var latlng = position.coords.latitude + ',' + position.coords.longitude;

			$.get("https://maps.googleapis.com/maps/api/geocode/json?latlng=" 
				+ latlng + "&key=AIzaSyAsVCGVrc7Zph5Ka3Gh2SGUqDrwCd8C3DU&language=pl&result_type=street_address", function(data){
					$('#address').val(data.results[0].formatted_address.replace(', Polska', ''));
					$('a#geo').buttonMarkup({ icon: "refresh" });
					$('#latlng').val(latlng);
				});

		}, function(error){
			$('a#geo').buttonMarkup({ icon: "alert" });
			$('#address').val("");
		}, geoOptions);
	} else {
		$('a#geo').buttonMarkup({ icon: "alert" });
	}
}

function checkFile(file, id){
	$('#' + id).parent().parent().removeClass('error');
	$('img#img' + id).attr("src", 'img/loading.gif');
	if(id == 'pic2'){
		$('#imgpic2Plate').attr("src", "");
	}
	if (file) {
		if (/^image\//i.test(file.type)) {
			readFile(file, id);
		} else {
			$('img#img' + id).attr("src", 'img/camera.png');
			$('#' + id).textinput('enable');
		}
	}
}

function readFile(file, id) {
	var reader = new FileReader();

	reader.onloadend = function () {
		processFile(reader.result, file.type, id);
	}

	reader.onerror = function () {
		$('img#img' + id).attr("src", 'img/camera.png');
		$('#' + id).textinput('enable');
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
		$('img#img' + id).attr("src", 'img/camera.png');
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
			if(json.thumbUrl) {
				$('img#img' + id).attr("src", json.thumbUrl);
				$('#' + id).textinput('disable');
				$('#' + id + 'Url').val(json.imageUrl);
				$('#' + id + 'Thumb').val(json.thumbUrl);
			} else {
			}
			if(id == 'pic2' && json.plate) {
				$('#plateid').val(json.plate);
			} else {
			}
			if(id == 'pic2' && json.plateImage){
				$('#imgpic2Plate').attr("src", json.plateImage);
			}else{
			}
		},
		error: function (data) {
			$('img#img' + id).attr("src", 'img/camera.png');
			$('#' + id).textinput('enable');
		}
	});
}
