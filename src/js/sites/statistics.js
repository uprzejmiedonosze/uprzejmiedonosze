import Highcharts from "highcharts";
import "highcharts/modules/data";
import "highcharts/modules/accessibility";

document.addEventListener("DOMContentLoaded", function () {
  if (!document.querySelector('.statystyki')) return;

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

  // DRY: Shared chart config objects and helpers
  const transparentBackground = { backgroundColor: "transparent" };
  const sharedEvents = {
    load() { this.showLoading('Pobieram dane...'); },
    redraw() { this.hideLoading(); }
  };
  const sharedTooltip = { shared: true, crosshairs: true };
  const sharedXAxis = { tickWidth: 0, gridLineWidth: 1 };
  const sharedResponsive = {
    rules: [{
      condition: { maxWidth: 600 },
      chartOptions: {
        chart: { spacingTop: 24, height: 340 },
        yAxis: { labels: { align: 'left', x: 0, y: -2 }, title: { text: '' } },
        subtitle: { text: null },
        credits: { enabled: false }
      }
    }]
  };
  const areaSplinePlotOptions = {
    areaspline: {
      fillColor: {
        linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
        stops: [
          [0, "#009C7F"],
          [1, "#FFFFFF"]
        ]
      }
    }
  };
  const columnPlotOptions = (barWidth) => ({
    column: {
      grouping: false,
      shadow: false,
      borderWidth: 0
    },
    series: {
      pointWidth: barWidth
    }
  });

  Highcharts.chart("statsByDay", {
    data: {
      csvURL: window.location.origin + "/stats/statsByDay.csv?sessionless",
      firstRowAsNames: false
    },
    chart: {
      type: "areaspline",
      ...transparentBackground,
      events: sharedEvents
    },
    plotOptions: areaSplinePlotOptions,
    xAxis: sharedXAxis,
    tooltip: sharedTooltip,
    responsive: sharedResponsive
  });

  const statsByYearElem = document.getElementById("statsByYear");
  const barWidth = statsByYearElem ? statsByYearElem.offsetWidth / 26 : 20;

  Highcharts.chart("statsByYear", {
    data: {
      csvURL: window.location.origin + "/stats/byYear.csv?sessionless",
      firstRowAsNames: false
    },
    chart: {
      type: "column",
      plotBackgroundColor: null,
      ...transparentBackground,
      events: sharedEvents
    },
    plotOptions: columnPlotOptions(barWidth),
    xAxis: sharedXAxis,
    tooltip: sharedTooltip,
    responsive: sharedResponsive
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
      csvURL: window.location.origin + "/stats/byCarBrand.csv?sessionless",
      firstRowAsNames: false
    },
    chart: {
      plotBackgroundColor: null,
      plotBorderWidth: null,
      plotShadow: false,
      type: "pie",
      backgroundColor: "transparent",
      events: {
        load() {
          const chart = this;
          chart.showLoading('Pobieram dane...')
        },
        redraw() {
          const chart = this;
          chart.hideLoading()
        }
      }

    },
    series: [
      {
        name: "Liczba zgłoszeń",
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

  // Suppress Highcharts warning #15 in the console (warn only)
  const originalWarn = console.warn;
  console.warn = function(...args) {
    if (
      typeof args[0] === 'string' &&
      args[0].includes('Highcharts warning #15')
    ) {
      return; // Suppress warning #15
    }
    originalWarn.apply(console, args);
  };
});
