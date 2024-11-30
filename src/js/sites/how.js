import $ from "jquery"
import filterable from "../lib/filterable"

$(function () {
  scrollTo(window.location.hash)
})

function scrollTo(hash) {
  const $hash = $(hash)
  window.location.hash = hash
  const scrollTop = (($hash.offset() ?? {}).top ?? 0) - 60
  if (scrollTop > 0)
    $("html, body").animate({scrollTop})
  $('.howto').removeClass('highlight')
  $hash.addClass('highlight')
}

// @ts-ignore
window._scrollToHowTo = scrollTo

filterable('filterable', 'filterable-list')

