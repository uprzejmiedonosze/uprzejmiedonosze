import { DateTime } from 'luxon';

export function setDateTime(dateTime, fromPicture = true) {
  let dt = dateTime;
  if (typeof dateTime === 'string') {
    dt = DateTime.fromFormat(dateTime, 'yyyy:MM:dd HH:mm:ss');
    if (!!dt.invalid) {
      dt = DateTime.fromFormat(dateTime, "yyyy-MM-dd'T'HH:mm:ss");
    }
    if (!!dt.invalid) {
      dt = DateTime.local();
    }
  }
  if (fromPicture) {
    $('#dtPrecise').text('o');
    if (dateTime !== '') {
      $('#dateHint').text('Data i godzina pobrana ze zdjęcia');
      $('#dateHint').addClass('hint');
    }
    $('div.datetime a.ui-btn').hide();
    $('div.datetime a.changeDatetime').show();
  } else {
    dt = dt.startOf('hour');
    $('#dtPrecise').text('około');
    $('#dateHint').text('Podaj datę i godzinę zgłoszenia');
    $('#dateHint').addClass('hint');
    $('div.datetime a.ui-btn').show();
    $('div.datetime a.changeDatetime').hide();

    if (DateTime.local() > dt.plus({ hours: 1 })) {
      $('#hp').removeClass('ui-state-disabled');
    } else {
      $('#hp').addClass('ui-state-disabled');
    }
    if (DateTime.local() > dt.plus({ days: 1 })) {
      $('#dp').removeClass('ui-state-disabled');
    } else {
      $('#dp').addClass('ui-state-disabled');
    }
  }

  $('#datetime').val(dt.toFormat("yyyy-LL-dd'T'HH:mm:ss"));
  $('#date').text(dt.setLocale('pl').toFormat("cccc d LLL"));
  $('#time').text(dt.toFormat("H:mm"));
  $('#dtFromPicture').val(fromPicture ? 1 : 0);
}