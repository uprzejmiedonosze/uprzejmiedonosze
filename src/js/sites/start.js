$(document).on('pageshow', function () {
    if (!$('.start-page').length) return;
    ga('send', 'event', { eventCategory: 'pageshow', eventAction: 'start'});
});