import { checkAddress, checkAttr, checkValue } from "../lib/validation";

export function validateForm() {
  $("#form-submit").addClass("ui-disabled");
  var ret = checkValue($("#plateId"), 3);
  ret = checkAddress($("#lokalizacja")) && ret;
  ret = checkAttr($("#carImage")) && ret;
  ret = checkAttr($("#contextImage")) && ret;
  if ($("#0").is(":checked")) {

    // if category == 0 then comment is mandatory
    ret = checkValue($("#comment"), 10) && ret;
  }
  if (!ret) {
    $(window).scrollTop($(".error").offset().top - 100);
  }
  $("#form-submit").removeClass("ui-disabled");
  return ret;
}
