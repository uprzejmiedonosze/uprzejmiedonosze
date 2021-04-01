$(function(){
    if (!$('.przepisy').length) return;
    const hash = window.location.hash;
    if($(hash).length) {
        setTimeout(function (){
            $('html, body').animate({scrollTop: $(hash).offset().top});
        }, 100);
    }
});