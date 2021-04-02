import Highcharts from "highcharts";
import Data from "highcharts/modules/data";

Data(Highcharts);

document.addEventListener("DOMContentLoaded", function () {
  if (!$(".stats").length) return;

  Highcharts.setOptions({
    lang: {
      months: [
        "Styczeń",
        "Luty",
        "Marzec",
        "Kwiecień",
        "Maj",
        "Czerwiec",
        "Lipiec",
        "Sierpień",
        "Wrzesień",
        "Październik",
        "Listopad",
        "Grudzień"
      ],
      weekdays: [
        "Niedziela",
        "Poniedziałek",
        "Wtorek",
        "Środa",
        "Czwartek",
        "Piątek",
        "Sobota"
      ],
      shortMonths: [
        "Sty",
        "Lut",
        "Mar",
        "Kwi",
        "Maj",
        "Cze",
        "Lip",
        "Sie",
        "Wrz",
        "Paź",
        "Lis",
        "Gru"
      ]
    },
    title: {
      text: null
    },
    legend: {
      enabled: false
    },
    credits: {
      enabled: false
    },
    yAxis: [
      {
        title: {
          text: null
        },
        labels: {
          align: "left",
          x: 0,
          y: 0
        },
        showFirstLabel: false
      }
    ],
    colors: [
      "#009C7F",
      "#e9c200",
      "#ED561B",
      "#DDDF00",
      "#24CBE5",
      "#64E572",
      "#FF9655",
      "#FFF263",
      "#6AF9C4"
    ]
  });

  Highcharts.chart("statsByDay", {
    data: {
      csvURL: window.location.origin + "/api/csv.html?file=statsByDay",
      firstRowAsNames: false
    },
    chart: {
      type: "areaspline",
      backgroundColor: "transparent"
    },
    plotOptions: {
      areaspline: {
        fillColor: {
          linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
          stops: [
            [0, "#009C7F"],
            [1, "#FFFFFF"]
          ]
        }
      }
    },
    xAxis: {
      tickInterval: 7 * 24 * 3600 * 1000, // one day
      tickWidth: 0,
      gridLineWidth: 1
    },
    series: [
      {
        name: "Nowe zgłoszenia",
        lineWidth: 5,
        color: "#009C7F"
      },
      {
        name: "Nowe rejestracje użytkowników",
        lineWidth: 3,
        type: "spline",
        color: "#e9c200"
      }
    ],
    tooltip: {
      shared: true,
      crosshairs: true
    }
  });

  const barWidth = $("#statsByYear").width() / 26;

  Highcharts.chart("statsByYear", {
    data: {
      csvURL: window.location.origin + "/api/csv.html?file=statsByYear",
      firstRowAsNames: false
    },
    chart: {
      type: "column",
      plotBackgroundColor: null,
      backgroundColor: "transparent"
    },
    plotOptions: {
      column: {
        grouping: false,
        shadow: false,
        borderWidth: 0
      },
      series: {
        pointWidth: barWidth
      }
    },
    xAxis: {
      tickWidth: 0,
      gridLineWidth: 1
    },
    series: [
      {
        name: "Nowe zgłoszenia",
        color: "#009C7F"
      },
      {
        name: "Nowe rejestracje użytkowników",
        color: "#e9c200",
        pointPadding: 0.4,
        pointPlacement: 0.2,
        pointWidth: barWidth * 0.7
      }
    ],
    tooltip: {
      shared: true,
      crosshairs: true
    }
  });

  Highcharts.chart("statsAppsByCity", {
    data: {
      csvURL: window.location.origin + "/api/csv.html?file=statsAppsByCity",
      firstRowAsNames: false
    },
    chart: {
      type: "column",
      plotBackgroundColor: null,
      backgroundColor: "transparent"
    },
    series: [
      {
        name: "Ilość zgłoszeń",
        color: "#009C7F"
      }
    ]
  });

  // Make monochrome colors
  var pieColors = (function () {
    var colors = [],
      base = Highcharts.getOptions().colors[0],
      i;

    for (i = 0; i < 10; i += 1) {

      // Start out with a darkened base color (negative brighten), and end
      // up with a much brighter color
      colors.push(
        Highcharts.color(base)
          .brighten((i - 2) / 15)
          .get()
      );
    }
    return colors;
  })();

  Highcharts.chart("statsByCarBrand", {
    data: {
      csvURL: window.location.origin + "/api/csv.html?file=statsByCarBrand",
      firstRowAsNames: false
    },
    chart: {
      plotBackgroundColor: null,
      plotBorderWidth: null,
      plotShadow: false,
      type: "pie",
      backgroundColor: "transparent"
    },
    series: [
      {
        name: "Ilość zgłoszeń",
        color: "#009C7F"
      }
    ],
    tooltip: {
      pointFormat: "{series.name}: <b>{point.percentage:.1f}%</b>"
    },
    plotOptions: {
      pie: {
        allowPointSelect: true,
        cursor: "pointer",
        colors: pieColors,
        dataLabels: {
          enabled: true,
          format: "<b>{point.name}</b><br>{point.percentage:.1f} %"
        },
        startAngle: -90,
        endAngle: 90,
        center: ["50%", "75%"],
        size: "110%"
      }
    }
  });
});
