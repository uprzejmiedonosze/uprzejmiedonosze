import $ from "jquery"
import { checkAddress, checkValue, checkCommentvalue, checkDateTimeValue } from "../lib/validation";

export function validateForm() {
  $("#form-submit").addClass("disabled");
  var ret = checkValue($("#plateId"), 3);
  ret = checkDateTimeValue() && ret;
  ret = checkAddress() && ret;
  ret = checkImages() && ret;
  if ($("#0").is(":checked")) {
    // if category == 0 then comment is mandatory
    ret = checkCommentvalue() && ret;
  }
  if (!ret) {
    $(window).scrollTop($(".error").first().offset()?.top || 100 - 100);
  }
  $("#form-submit").removeClass("disabled");
  return ret;
}

function checkImages() {
  let success = true;
  ['contextImage', 'carImage'].forEach(img => {
    if ($(`.${img}Section img`)?.attr('src')?.startsWith('img/')) {
      $(`.${img}Section`).addClass("error");
      success = false;
    }
  })
  return success
}
