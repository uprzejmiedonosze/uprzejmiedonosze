import { checkAddress, checkValue } from "../lib/validation";
import { initAutocompleteRegister } from "../lib/geolocation";

function validateRegisterForm() {
  var ret = checkValue($("#name"), 6);
  ret = checkAddress($("#address")) && ret;
  if (!ret) {
    $(window).scrollTop($(".error").offset().top - 100);
  }
  return ret;
}

$(document).on("pageshow", function () {
  if (!$(".register").length) return;

  initAutocompleteRegister();

  $("#name").on("change", function () {
    $("#name").removeClass("error");
  });

  $("#address").on("change", function () {
    $("#address").removeClass("error");
  });

  $("#register-submit").click(function () {
    if (validateRegisterForm()) {
      $("#register-form").submit();
    }
  });
});
