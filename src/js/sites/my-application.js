
$(document).on('pageshow', function () {
    if (!$('.my-applications').length) return;

    $('div.displayAllApps a').click(function () {
        $('div.displayAllApps').hide();
        $('div.application:not(.status-archived)').show();
    });
    
    updateCounters();
    $('.filter a').click(function(e){
        $('div.application').hide();
        $('div.application.status-' + this.id).show();
        $('.filter a').each(function(idx, item){
            $(item).removeClass('active');
        });
        $(this).addClass('active');
    });
});