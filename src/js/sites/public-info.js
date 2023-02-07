function copyToClipboard() {
    var txt = $('dd#copy').text().trim()
    navigator.clipboard.writeText(txt)
    $('a#copyBtn').text('Tekst skopiowany')
  }


window.copyToClipboard = copyToClipboard
