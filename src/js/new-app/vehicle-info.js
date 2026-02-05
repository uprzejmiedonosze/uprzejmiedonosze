import * as Sentry from "@sentry/browser";

const VEHICLE_INFO_API = "https://parkowanie.zbiorkom.live/{plateId}";

let debounceTimer = null;
let ocrBrand = null;
let ocrModel = null;
let lastPlate = "";
const autoLines = new Set();

function normalizePlateId(value) {
  if (!value) return "";
  return value.toString().toUpperCase().replace(/\s+/g, "");
}

function buildVehicleInfoUrl(plateId) {
  if (!plateId) return null;
  return VEHICLE_INFO_API.replace("{plateId}", encodeURIComponent(plateId));
}

function toTitleCase(text) {
  return text
      .toLowerCase()
      .split(" ")
      .map(word => word.charAt(0).toUpperCase() + word.slice(1))
      .join(" ");
}

function appendAutoComment(commentElement, text) {
  if (!commentElement || !text) return;

  const line = text.trim();
  if (!line) return;

  const lines = (commentElement.value || "").split("\n");
  if (lines.some(existing => existing.trim() === line)) return;

  const separator = lines.length && lines[lines.length - 1].trim().length ? "\n" : "";
  commentElement.value = `${commentElement.value || ""}${separator}${line}`;
  autoLines.add(line);
}

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

function formatGrossWeightInTons(weightKg) {
  return (weightKg / 1000).toFixed(2).replace(".", ",");
}

function logVehicleInfoError(error, context) {
  try {
    Sentry.captureException(error, {
      tags: { feature: "vehicle-info", context },
    });
  } catch (_) {
    /* silent */
  }
}

function applyVehicleInfo(vehicle) {
  if (!vehicle || typeof vehicle !== "object") return;

  const commentElement =
      /** @type {HTMLTextAreaElement|null} */ (
      document.getElementById("comment")
  );

  const rawBrand = vehicle.brand || ocrBrand;
  const rawModel = vehicle.model || ocrModel;

  const brand = rawBrand ? toTitleCase(rawBrand) : null;
  const model = rawModel ? rawModel.toString().trim() : null;

  const year =
      vehicle.productionYear != null
          ? parseInt(vehicle.productionYear, 10)
          : null;

  if (brand && model && commentElement) {
    const identification = year
        ? `Pojazd marki ${brand} ${model} z roku ${year}.`
        : `Pojazd marki ${brand} ${model}.`;
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

export function triggerVehicleInfoEnrichment(plateId) {
  const normalized = normalizePlateId(plateId);
  const commentElement = document.getElementById("comment");
  const weightWarning = document.getElementById("vehicleWeightWarning");

  if (normalized !== lastPlate) {
    clearAutoVehicleComments(commentElement);
    if (weightWarning) weightWarning.style.display = "none";
  }
  fetchVehicleInfo(normalized);
}

export function setOcrVehicleInfo(info = {}) {
  if (info.brand) ocrBrand = info.brand;
  if (info.model) ocrModel = info.model;
}
