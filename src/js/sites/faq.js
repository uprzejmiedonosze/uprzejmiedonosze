$(function(){
    if (!$('.faq').length) return;
    const hash = window.location.hash;
    if($(hash).length) {
        $('html, body').animate({
            scrollTop: $(hash).offset().top - 60
        });
    }
});