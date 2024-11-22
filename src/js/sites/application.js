import $ from "jquery"

import isIOS from "../lib/isIOS";

$(document).on("pageshow", function () {
  if (!$(".aplikacja").length) return;

  if (isIOS()) {
    $("#tabs ul a:nth-child(1)").trigger( "click" );
  } else {
    $("#tabs ul a:first").trigger( "click" );
  }
});
