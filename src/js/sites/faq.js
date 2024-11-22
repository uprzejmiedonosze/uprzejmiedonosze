import $ from "jquery"

$(function () {
  if (!$(".faq").length || !$(".how").length) return;
  const hash = window.location.hash;
  if ($(hash).length) {
    $("html, body").animate({
      scrollTop: $(hash).offset().top - 60
    });
    $('h4'+hash).addClass('highlight')
  }
  $('h4').append(' <a class="copyLink" data-toggle="tooltip" title="Skopiuk link do sekcji">(link)</a>')

  $('a.copyLink').click(function (e) {
    e.preventDefault();
    var copyText = `${window.location.origin}${window.location.pathname}#` + $(this).parent().attr('id');

    document.addEventListener('copy', function(e) {
       e.clipboardData.setData('text/plain', copyText);
       e.preventDefault();
    }, true);

    document.execCommand('copy');
  });  
});
