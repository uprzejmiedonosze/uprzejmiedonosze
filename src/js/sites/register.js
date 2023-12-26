import { checkValueRe } from "../lib/validation";
import { initAutocompleteRegister } from "../lib/geolocation";

function validateRegisterForm() {
  ret = checkValueRe($("#name"), /^(\S{2,5}\s)?\S{3,20}\s[\S -]{3,40}$/i)
  ret = checkValueRe($("#address"), /^.{3,50}\d.{3,40}$/i) && ret
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
