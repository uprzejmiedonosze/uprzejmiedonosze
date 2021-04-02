import { check, checkAddress } from "../lib/validation";

export function validateForm() {
  $("#form-submit").addClass("ui-disabled");
  var ret = check($("#plateId"), 3, false);
  ret = checkAddress($("#lokalizacja")) && ret;
  ret = check($("#carImage"), 0, true) && ret;
  ret = check($("#contextImage"), 0, true) && ret;
  if ($("#0").is(":checked")) {

    // if category == 0 then comment is mandatory
    ret = check($("#comment"), 10, false) && ret;
  }
  if (!ret) {
    $(window).scrollTop($(".error").offset().top - 100);
  }
  $("#form-submit").removeClass("ui-disabled");
  return ret;
}
