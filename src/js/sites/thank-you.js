    $(document).on('pageshow', function () {
        if (!$('.dziekujemy').length) return;
        ga('send', 'event', { eventCategory: 'pageshow', eventAction: 'dziekujemy'});
    });