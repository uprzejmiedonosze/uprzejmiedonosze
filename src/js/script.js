/* eslint-disable no-undef */
$(document).on('pageshow', function () {
    if ($(".new-application").length) {

        $('#lokalizacja').on('change', function () {
            $('a#geo').buttonMarkup({ icon: "alert" });
        });

        $('#msisdn').on('change', function () {
            $('#msisdn').removeClass('error');
        });

        $('#plateId').on('change', function () {
            $('#plateId').removeClass('error');
            $('#plateImage').hide();
            $('#recydywa').hide();
            $('#brand').hide();
        });

        $('#comment').on('change', function () {
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

        $('#form-submit').click(function () {
            if (validateForm()) {
                $('#form').submit();
            }
        });

        $('#witness').change(function () {
            if($('#witness').prop("checked")){
                $('#witnessY').show();
                $('#witnessN').hide();
            }else{
                $('#witnessY').hide();
                $('#witnessN').show();
            }
        });

        $('.datetime a').click(function () {
            let datetime = luxon.DateTime.fromISO($('#datetime').val()).startOf('hour');
            const offset = (this.id.endsWith('p'))? 1: -1;

            if(this.id.startsWith('d')){
                datetime = datetime.plus({ days: offset });
            }else{
                datetime = datetime.plus({ hours: offset });
            }
            if(luxon.DateTime.local() > datetime){
                setDateTime(datetime, false);
            }
        });

        setDateTime($('#datetime').val(), $('#dtFromPicture').val() == "1");
    }

    if ($("#register-submit").length) {
        $('#name').on('change', function () {
            $('#name').removeClass('error');
        });

        $('#register-submit').click(function () {
            if (validateRegisterForm()) {
                $('#register-form').submit();
            }
        });
    }
});

/* ############################## ADDRESS AUTOCOMPLETE ############################## */

var autocomplete;
let locationP;

// eslint-disable-next-line no-unused-vars
function initAutocompleteOnRegister() {
    initAutocomplete(false, 'address');
}

// eslint-disable-next-line no-unused-vars
function initAutocompleteOnNewApplication() {
    initAutocomplete(true, 'lokalizacja');

    locationP = new locationPicker('locationPicker', {
        setCurrentPosition: false,
        lat: 53.426333778,
        lng: 14.554599583
    }, {
        disableDefaultUI: true,
        scrollwheel: false,
        zoomControl: true,
        controlSize: 25,
        mapTypeId: google.maps.MapTypeId.SATTELITE,
        zoom: 16,
        minZoom: 10,
        maxZoom: 19
    });

    google.maps.event.addListener(locationP.map, 'idle', function () {
        var location = locationP.getMarkerPosition();
        setAddressByLatLng(location.lat, location.lng, 'picker');
    });
}

function initAutocomplete(trigger_change, inputId) {
    autocomplete = new google.maps.places.Autocomplete(
        document.getElementById(inputId),
        {
            types: ['address'],
            componentRestrictions: { country: 'pl' }
        }
    );
    if (trigger_change) {
        autocomplete.addListener('place_changed', function(){
            setAddressByPlace(autocomplete.getPlace());
        });
    }
}

/* ############################## ADDRESS ############################## */

function setAddressByLatLng(lat, lng, from) {
    $('a#geo').buttonMarkup({ icon: "clock" });
    $('#lokalizacja').val("");
    if (from == 'picture') {
        $('#lokalizacja').attr("placeholder", "(pobieram adres ze zdjęcia...)");
    } else {
        $('#lokalizacja').attr("placeholder", "(weryfikuję adres...)");
    }
    $('#administrative_area_level_1').val("");
    $('#country').val("");
    $('#locality').val("");
    $('#latlng').val("");

    if(from !== 'picker'){
        locationP.setLocation(lat, lng);
    }

    $.get("https://maps.googleapis.com/maps/api/geocode/json?latlng="
        + lat + ',' + lng + "&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&language=pl&result_type=street_address", function (data) {
            if (data.results.length) {
                setAddressByPlace(data.results[0]);

                if (from == 'picture') {
                    $('#addressHint').text('Sprawdź automatycznie pobrany adres');
                    $('#addressHint').addClass('hint');
                }else{
                    $('#addressHint').text('Zweryfikuj pobrany adres');
                    $('#addressHint').addClass('hint');
                }
            } else {
                $('#lokalizacja').addClass('error');
                $('a#geo').buttonMarkup({ icon: "location" });
                $('#addressHint').text('(zacznij wpisywać adres)');
                $('#addressHint').removeClass('hint');
            }
        }).fail(function () {
            $('a#geo').buttonMarkup({ icon: "alert" });
            $('#latlng').val("");
            $('#lokalizacja').addClass('error');
        });
    $('#lokalizacja').attr("placeholder", "(zacznij wpisywać adres)");
}

function setAddressByPlace(place){
    const latlng = (typeof place.geometry.location.lat == "function")?
        place.geometry.location.lat() + ',' + place.geometry.location.lng():
        place.geometry.location.lat + ',' + place.geometry.location.lng;;
    const formatted_address = place.formatted_address.replace(', Polska', '').replace(/\d\d-\d\d\d\s/, '');
    const voivodeship = place.address_components.filter(function (e) {
            return e.types.indexOf('administrative_area_level_1') == 0
        })[0].long_name.replace('Województwo ', '');
    const country = place.address_components.filter(function (e) {
            return e.types.indexOf('country') == 0;
        })[0].long_name;
    const city = place.address_components.filter(function (e) {
            return e.types.indexOf('locality') == 0;
        })[0].long_name;
    $('#lokalizacja').val(formatted_address);
    $('#administrative_area_level_1').val(voivodeship);
    $('#country').val(country);
    $('#locality').val(city);
    $('#latlng').val(latlng);

    $('a#geo').buttonMarkup({ icon: "check" });
    $('#lokalizacja').removeClass('error');
}


/* ############################## FORM VALIDATION ############################## */

function validateForm() {
    $('#form-submit').addClass('ui-disabled');
    var ret = check($('#plateId'), 3, false);
    ret = checkAddress($('#lokalizacja')) && ret;
    ret = check($('#carImage'), 0, true) && ret;
    ret = check($('#contextImage'), 0, true) && ret;
    if ($('#0').is(':checked')) { // if category == 0 then comment is mandatory
        ret = check($('#comment'), 10, false) && ret;
    }
    if (!ret) {
        $(window).scrollTop($('.error').offset().top - 100);
    }
    $('#form-submit').removeClass('ui-disabled');
    return ret;
}

function checkAddress(where) {
    var ret = where.val().trim().length > 10;

    // checking this only on new-application page (not on registration where adddress field name differs)
    if (where.selector == '#lokalizacja') {
        ret = ($('#locality').val().trim().length > 2) && ret;
        ret = ($('#latlng').val().trim().length > 5) && ret;

        if (!ret && where.val().trim().length > 0) {
            $('#addressHint').text('Zacznij wpisywać adres, a potem wybierz pasującą pozycję z listy. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej');
            $('#addressHint').addClass('hint');
        }
    }

    !ret && where.addClass('error');

    return ret;
}

function check(item, minLength, grandma) {
    const len = (item.val().trim().length == 0) ?
        ((item.attr('value')) ? item.attr('value').trim().length : 0) :
        item.val().trim().length;
    if (len <= minLength) {
        if (grandma) {
            item.parent().parent().addClass('error');
        } else {
            item.addClass('error');
        }
        return false;
    } else {
        return true;
    }
}

function validateRegisterForm() {
    var ret = check($('#name'), 6, false);
    ret = checkAddress($('#address')) && ret;
    if (!ret) {
        $(window).scrollTop($('.error').offset().top - 100);
    }
    return ret;
}

/* ############################## IMAGES ############################## */

function checkFile(file, id) {
    $('#form-submit').addClass('ui-disabled');
    if (file) {
        if (/^image\//i.test(file.type)) {
            $('.' + id + 'Section').removeClass('error');
            $('.' + id + 'Section img').hide();
            $('.' + id + 'Section .loader').show();

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

function readFile(file, id) {
    loadImage(
        file,
        function (img) {
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
    $('.' + id + 'Section').parent().addClass('error');
    $('.' + id + 'Section input').textinput('enable');
    $('#form-submit').removeClass('ui-disabled');
}

function readGeoDataFromImage(file) {
    loadImage.parseMetaData(
        file,
        function (data) {
            if(data.exif){
                const dateTime = data.exif.getText("DateTimeOriginal");
                if (dateTime && dateTime !== 'undefined') {
                    setDateTime(dateTime, true);
                    $('#dtFromPicture').val(1);
                }else{
                    setDateTime('', false);
                    $('#dtFromPicture').val(0);
                }
            }else{
                setDateTime('', false);
                $('#dtFromPicture').val(0);
            }

            if(!data.exif || data.exif.getText("GPSLatitude") === 'undefined'){
                noGeoDataInImage();
                return;
            }
            var lat = data.exif.getText("GPSLatitude").split(',');
            var lon = data.exif.getText("GPSLongitude").split(',');
            var latRef = data.exif.getText("GPSLatitudeRef") || "N";
            var lonRef = data.exif.getText("GPSLongitudeRef") || "W";
            if (lat) {
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
        $('#addressHint').html('Twoje zdjęcie nie ma znaczników geolokacji, <a rel="external" href="https://www.google.com/search?q=kamera+gps+geotagging">włącz je a będzie Ci znacznie wygodniej</a>.');
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
            if (json.carImage || json.contextImage){
                $('.' + id + 'Section input').textinput('disable');
                $('.' + id + 'Section .loader').hide();
                $('.' + id + 'Section img').css('height', '100%');
                $('.' + id + 'Section img').attr("src", json[id].thumb + '?v=' + Math.random().toString());
                $('.' + id + 'Section img').show();
            }
            if (id == 'carImage' && json.carInfo) {
                if (json.carInfo.plateId) {
                    $('#plateId').val(json.carInfo.plateId);
                    if (json.carInfo.brand) {
                        $('#plateHint').text('Sprawdź automatycznie pobrany numer rejestracyjny pojazdu '
                            + json.carInfo.brand);
                    }else{
                        $('#plateHint').text('Sprawdź automatycznie pobrany numer rejestracyjny');
                    }
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
            $('#form-submit').removeClass('ui-disabled');
        },
        // eslint-disable-next-line no-unused-vars
        error: function (data) {
            imageError(id);
        }
    });
}


/* ############################## DATETIME ############################## */

function setDateTime(dateTime, fromPicture = true) {
    dt = dateTime;
    if(typeof dateTime === 'string'){
        dt = luxon.DateTime.fromFormat(dateTime, 'yyyy:MM:dd HH:mm:ss');
        if(!!dt.invalid){
            dt = luxon.DateTime.fromFormat(dateTime, "yyyy-MM-dd'T'HH:mm:ss");
        }
        if(!!dt.invalid){
            dt = luxon.DateTime.local();
        }
    }
    if(fromPicture){
        $('#dtPrecise').text('o');
        if(dateTime !== ''){
            $('#dateHint').text('Data i godzina pobrana ze zdjęcia');
            $('#dateHint').addClass('hint');
        }
        $('div.datetime a').hide();
    }else{
        dt = dt.startOf('hour');
        $('#dtPrecise').text('około');
        $('#dateHint').text('Podaj datę i godzinę zgłoszenia');
        $('#dateHint').addClass('hint');
        $('div.datetime a').show();

        if(luxon.DateTime.local() > dt.plus({ hours: 1 })){
            $('#hp').removeClass('ui-state-disabled');
        }else{
            $('#hp').addClass('ui-state-disabled');
        }
        if(luxon.DateTime.local() > dt.plus({ days: 1 })){
            $('#dp').removeClass('ui-state-disabled');
        }else{
            $('#dp').addClass('ui-state-disabled');
        }
    }

    $('#datetime').val(dt.toFormat("yyyy-LL-dd'T'TT"));
    $('#date').text(dt.setLocale('pl').toFormat("cccc d LLL"));
    $('#time').text(dt.toFormat("H:mm"));
}