import $ from "jquery"

function copyToClipboard(selector) {
    selector = selector || 'dd#copy'
    var txt = $(selector).text().trim()
    navigator.clipboard.writeText(txt)
    $('a#copyBtn').text('Tekst skopiowany')
  }


window.copyToClipboard = copyToClipboard
