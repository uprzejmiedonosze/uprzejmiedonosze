import { initAutocompleteRegister } from '../lib/geolocation';
import { check, checkAddress } from '../lib/validation';

function validateRegisterForm() {
  var ret = check($('#name'), 6, false);
  ret = checkAddress($('#address')) && ret;
  if (!ret) {
    $(window).scrollTop($('.error').offset().top - 100);
  }
  return ret;
}

$(document).on('pageshow', function () {
  if ($('.register').length) {
    initAutocompleteRegister()
    
    $('#name').on('change', function () {
      $('#name').removeClass('error');
    });

    $('#register-submit').click(function () {
      if (validateRegisterForm()) {
        $('#register-form').submit();
      }
    });
  }
})
