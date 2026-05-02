import * as Sentry from "@sentry/browser";

const VEHICLE_INFO_API = "https://parkowanie.zbiorkom.live/{plateId}";

/** @type {NodeJS.Timeout | null} */
let debounceTimer = null;
/** @type {String | null} */
let ocrBrand = null;
/** @type {String | null} */
let ocrModel = null;
/** @type {String} */
let lastPlate = "";
const autoLines = new Set();


/**
 * @param {string} plateId
 */
function normalizePlateId(plateId) {
  if (!plateId) return "";
  return plateId.toString().toUpperCase().replace(/\s+/g, "");
}

/**
 * @param {string} plateId
 */
function buildVehicleInfoUrl(plateId) {
  if (!plateId) return null;
  return VEHICLE_INFO_API.replace("{plateId}", encodeURIComponent(plateId));
}

/**
 * @param {string} text
 * @returns {string}
 */
function formatBrandNames(text) {
    if (!text) return "";

    let titleCased = text.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());

    /** * @type {Record<string, string>} */
    const corrections = {
        "Bmw": "BMW",
        // "Mercedes-Benz": "Mercedes-Benz",
        // "Alfa-Romeo": "Alfa-Romeo",
        // "Land-Rover": "Land-Rover",
        "Fso": "FSO",
        "Ssangyong": "SsangYong"
    };

    /** @type {RegExp} */
    const pattern = new RegExp(Object.keys(corrections).join('|'), 'g');

    return titleCased.replace(pattern, matched => corrections[matched]);
}

/**
 * @param {HTMLTextAreaElement} commentElement
 * @param {string} text
 */
export function appendAutoComment(commentElement, text) {
  if (!commentElement || !text) return;

  const line = text.trim();
  if (!line) return;

  const lines = (commentElement.value || "").split("\n");
  if (lines.some(existing => existing.trim() === line)) return;

  const separator = lines.length && lines[lines.length - 1].trim().length ? "\n" : "";
  commentElement.value = `${commentElement.value || ""}${separator}${line}`;
  autoLines.add(line);
}

/**
 * @param {HTMLInputElement | null} commentElement
 */
function clearAutoVehicleComments(commentElement) {
  if (!commentElement) return;

  if (autoLines.size === 0) return;

  const filtered = (commentElement.value || "")
      .split("\n")
      .filter(line => {
        const trimmed = line.trim();
        return !autoLines.has(trimmed);
      })
      .join("\n");
  commentElement.value = filtered;
  autoLines.clear();
}

/**
 * @param {any[]} value
 */
function getMinGrossVehicleWeight(value) {
  if (Array.isArray(value)) {
    const nums = value
        .map(Number)
        .filter(Number.isFinite)
        .filter(num => num > 0);
    return nums.length ? Math.min(...nums) : null;
  }
  const num = Number(value);
  return Number.isFinite(num) && num > 0 ? num : null;
}

/**
 * @param {number} weightKg
 */
function formatGrossWeightInTons(weightKg) {
  return (weightKg / 1000).toFixed(2).replace(".", ",");
}

/**
 * @param {unknown} error
 * @param {string} context
 */
function logVehicleInfoError(error, context) {
  try {
    Sentry.captureException(error, {
      tags: { feature: "vehicle-info", context },
    });
  } catch (_) {
    /* silent */
  }
}

/**
 * @param {{ brand: any; model: any; productionYear?: any; grossVehicleWeight: any; isHeavyVehicle: any; vehicleType: any; }} vehicle
 */
function applyVehicleInfo(vehicle) {
  if (!vehicle || typeof vehicle !== "object") return;

  const commentElement =
      /** @type {HTMLTextAreaElement|null} */ (
      document.getElementById("comment")
  );

  const rawBrand = vehicle.brand || ocrBrand;
  const rawModel = vehicle.model || ocrModel;

  const brand = rawBrand ? formatBrandNames(rawBrand) : null;
  const model = rawModel ? rawModel.toString().trim() : null;

  if (brand && model && commentElement) {
    if (vehicle.brand) {
      clearAutoVehicleComments(commentElement);
    }
    const identification = `Pojazd marki ${brand} ${model}.`;
    appendAutoComment(commentElement, identification);
  }

  const weightWarning = /** @type {HTMLElement|null} */ (
      document.getElementById("vehicleWeightWarning")
  );

  const isHeavyVehicle =
      vehicle.isHeavyVehicle === true && vehicle.vehicleType === "TRUCK";

  if (isHeavyVehicle) {
    const lines = [
      "Pojazd jest sklasyfikowany jako ciężarowy.",
    ];

    const minGrossVehicleWeight = getMinGrossVehicleWeight(
        vehicle.grossVehicleWeight
    );
    if (minGrossVehicleWeight !== null) {
      lines.push(
          `Dopuszczalna masa całkowita wg danych producenta wynosi minimum ${formatGrossWeightInTons(
              minGrossVehicleWeight
          )} t.`
      );
    }
    lines.push("Może to mieć istotne znaczenie przy kwalifikacji wykroczenia.");

    if (weightWarning) {
      weightWarning.textContent = lines.join(" ");
      weightWarning.style.display = "block";
    }
    return;
  }

  const minGrossVehicleWeight = getMinGrossVehicleWeight(
      vehicle.grossVehicleWeight
  );

  if (minGrossVehicleWeight !== null && minGrossVehicleWeight > 2500) {
    const line =
        `Dopuszczalna masa całkowita wg danych producenta wynosi minimum ${formatGrossWeightInTons(
            minGrossVehicleWeight
        )} t.`;
    if (weightWarning) {
      weightWarning.textContent =
          `${line} Może to mieć znaczenie przy parkowaniu na chodniku.`;
      weightWarning.style.display = "block";
    }
  } else {
    if (weightWarning) weightWarning.style.display = "none";
  }
}

/**
 * @param {string} plateId
 */
async function fetchVehicleInfo(plateId) {
  const normalizedPlate = normalizePlateId(plateId);
  if (!normalizedPlate) return;

  const url = buildVehicleInfoUrl(normalizedPlate);
  if (!url) return;

  try {
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
    });
    if (!response.ok) return;

    const data = await response.json();

    const normalizedVehicleInfo = {
      brand: data?.brand ?? null,
      model: data?.model ?? null,
      productionYear: data?.productionYear ?? null,
      grossVehicleWeight: data?.vehicleInfo?.grossVehicleWeight ?? null,
      isHeavyVehicle: data?.isHeavyVehicle === true,
      vehicleType: data?.vehicleType ?? null,
    };

    applyVehicleInfo(normalizedVehicleInfo);
    lastPlate = normalizedPlate;
  } catch (error) {
    logVehicleInfoError(error, "fetch");
  }
}

export function initVehicleInfoEnrichment() {
  const plateIdInput =
      /** @type {HTMLInputElement|null} */ (
      document.getElementById("plateId")
  );
  if (!plateIdInput) return;

  const scheduleLookup = () => {
    if (debounceTimer) clearTimeout(debounceTimer);

    debounceTimer = setTimeout(() => {
      const normalized = normalizePlateId(plateIdInput.value);
      /** @type {HTMLInputElement|null} */
      const commentElement = document.getElementById("comment");
      const weightWarning = document.getElementById("vehicleWeightWarning");

      if (!normalized || normalized.length < 5) {
        clearAutoVehicleComments(commentElement);
        if (weightWarning) weightWarning.style.display = "none";
        lastPlate = "";
        return;
      }

      if (normalized !== lastPlate) {
        clearAutoVehicleComments(commentElement);
        if (weightWarning) weightWarning.style.display = "none";
      }
      fetchVehicleInfo(normalized);
    }, 600);
  };

  plateIdInput.addEventListener("input", scheduleLookup);
  plateIdInput.addEventListener("change", scheduleLookup);
}

/**
 * @param {string} plateId
 */
export function triggerVehicleInfoEnrichment(plateId) {
  const normalized = normalizePlateId(plateId);
  /** @type {HTMLInputElement|null} */
  const commentElement = document.getElementById("comment");
  const weightWarning = document.getElementById("vehicleWeightWarning");

  if (normalized !== lastPlate) {
    clearAutoVehicleComments(commentElement);
    if (weightWarning) weightWarning.style.display = "none";
  }
  fetchVehicleInfo(normalized);
}

/**
 * @param {{ brand: string | null; model: string | null; } | null} info
 */
export function setOcrVehicleInfo(info = null) {
  if (info?.brand) ocrBrand = info.brand;
  if (info?.model) ocrModel = info.model;
}
