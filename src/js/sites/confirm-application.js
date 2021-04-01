$(document).on('pageshow', function () {
    if (!$('.confirm-application').length) return;
    ga('send', 'event', { eventCategory: 'pageshow', eventAction: 'potwierdz'});
});