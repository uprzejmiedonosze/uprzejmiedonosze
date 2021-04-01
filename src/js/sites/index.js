$(document).on('pageshow', function () {
  if (!$('.mainPage').length) return;
  ga('send', 'event', { eventCategory: 'pageshow', eventAction: 'index'});
});