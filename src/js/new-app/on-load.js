import { DateTime } from "luxon";

import { checkFile } from "./images";
import { setDateTime } from "./set-datetime";
import { validateForm } from "./validate-form";

export const initHandlers = () => {
  $("#lokalizacja").on("change", function () {
    $("a#geo").buttonMarkup({ icon: "alert" });
  });

  $("#msisdn").on("change", function () {
    $("#msisdn").removeClass("error");
  });

  $("#plateId").on("change", function () {
    $("#plateId").removeClass("error");
    $("#plateImage").hide();
    $("#recydywa").hide();
  });

  $("#comment").on("change", function () {
    $("#comment").removeClass("error");
  });

  $("#category").on("change", function (e) {
    $(".contextImageSection p.pictureHint").text(
      $(e.target).attr("data-contextImage-hint")
    );
    $(".carImageSection p.pictureHint").text(
      $(e.target).attr("data-carImage-hint")
    );
    $("#extensions div.ui-checkbox").removeClass("ui-state-disabled").prop( "disabled", false );
    $(`#ex${e.target.id}`).attr("checked", false).checkboxradio("refresh").prop( "disabled", true );
    $(`#ex${e.target.id}`).parent().addClass('ui-state-disabled');
  });

  if (window.File && window.FileReader && window.FormData) {
    $(document).on("change", ".image-upload input", function (e) {
      checkFile(e.target.files[0], e.target.id);
      if(e.target.files.length > 1) {
        checkFile(e.target.files[1], e.target.id === 'carImage' ? 'contextImage': 'carImage');
      }
    });
  }

  $("#resizeMap").click(function () {
    $("#locationPicker").toggleClass("locationPickerBig");
    $("#resizeMap").toggleClass("ui-icon-arrowsin");
  });

  $("#form-submit").click(function () {
    if (validateForm()) {
      $("#form").submit();
    }
  });

  $("div.datetime a.ui-btn").click(function () {
    let datetime = DateTime.fromISO($("#datetime").val()).startOf("hour");
    const offset = (this.id && this.id.endsWith("p")) ? 1 : -1;

    if (this.id.startsWith("d")) {
      datetime = datetime.plus({ days: offset });
    } else {
      datetime = datetime.plus({ hours: offset });
    }
    if (DateTime.local() > datetime) {
      setDateTime(datetime, false);
    }
  });

  $("div.datetime a.changeDatetime").click(function () {
    setDateTime($("#datetime").val(), false);
  });

  showHidePictureHints($(".contextImageSection"));
  showHidePictureHints($(".carImageSection"));

  setDateTime($("#datetime").val(), $("#dtFromPicture").val() == "1");

  $(".image-upload img").on("load", function () {
    $(this).show();
    showHidePictureHints($(this).parent());
  });
};

function showHidePictureHints(context) {
  if ((!context.find("img").attr("src")) || context.find("img").attr("src").endsWith(".png")) {
    context.find("p.pictureHint").hide();
  } else {
    context.find("p.pictureHint").show();
  }
}
