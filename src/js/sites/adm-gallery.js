function _moderateApp(appId, decision) {
  $.post("/api/api.html", {
    action: "moderateApp",
    id: appId,
    decision: decision
  })
    .done(function () {
      $("#" + appId)
        .removeClass("decisiontrue decisionfalse")
        .addClass("decision" + decision);
    })
    .fail(function (e) {
      $.mobile.loading("show", {
        text: e.statusText,
        textVisible: true,
        textonly: true
      });
      return false;
    });
}

window._moderateApp = _moderateApp;
