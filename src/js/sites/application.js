$(document).on('pageshow', function () {
  if (!$('.aplikacja').length) return;

  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
    $("#tabs ul a:nth-child(1)").click();
  } else {
    $("#tabs ul a:first").click();
  }
});