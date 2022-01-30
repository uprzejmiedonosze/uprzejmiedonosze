export const checkAddress = function (where) {
  var ret = where.val().trim().length > 10;

  // checking this only on new-application page (not on registration where adddress field name differs)
  if (where.selector == "#lokalizacja") {
    ret = $("#locality").val().trim().length > 2 && ret;
    ret =
      !!$("#latlng")
        .val()
        .trim()
        .match(/\d\d\.\d+,\d\d\.\d+/) && ret;

    if (!ret && where.val().trim().length > 0) {
      $("#addressHint").text(
        "Podaj adres lub wskaż go na mapie. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej"
      );
      $("#addressHint").addClass("hint");
    }
  }

  !ret && where.addClass("error");

  return ret;
};

export const checkValue = function (item, minLength) {
  const len = item.val().trim().length
  if (len <= minLength) {
    item.addClass("error");
    return false;
  }
  return true;
};
