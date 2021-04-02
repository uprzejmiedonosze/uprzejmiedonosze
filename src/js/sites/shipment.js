if ($(".shipment").length) {
  $("#download").on("click", () => {
    $(this).parent().addClass("ui-disabled");
    $("#send").parent().removeClass("ui-disabled");
  });

  $("#send, #sendE").on("click", () => {
    $(this).parent().addClass("ui-disabled");
    $("#email, #epuap").parent().removeClass("ui-disabled");
  });
}
