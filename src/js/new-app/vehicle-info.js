import * as Sentry from "@sentry/browser";

// Preview of the vehicle make/model (and DMC warning) the backend resolves from
// parkowanie.info for the current plate. The backend stores it on save and
// renders it right after the plate number — the comment is never touched.

/** @type {NodeJS.Timeout | null} */
let debounceTimer = null;
/** @type {String} */
let lastPlate = "";

/**
 * @param {string} plateId
 */
function normalizePlateId(plateId) {
  if (!plateId) return "";
  return plateId.toString().toUpperCase().replace(/\s+/g, "");
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
 * @param {{ brand?: string | null; model?: string | null; warning?: string | null; } | null} vehicle
 */
export function showVehicleInfo(vehicle) {
  const info = /** @type {HTMLElement|null} */ (document.getElementById("vehicleInfo"));
  const weightWarning = /** @type {HTMLElement|null} */ (document.getElementById("vehicleWeightWarning"));

  if (info) {
    const name = vehicle?.brand ? [vehicle.brand, vehicle.model].filter(Boolean).join(" ") : "";
    info.textContent = name ? `Pojazd marki ${name} (wg parkowanie.info)` : "";
    info.style.display = name ? "block" : "none";
  }
  if (weightWarning) {
    weightWarning.textContent = vehicle?.warning || "";
    weightWarning.style.display = vehicle?.warning ? "block" : "none";
  }
}

/**
 * @param {string} plateId
 */
async function fetchVehicleInfo(plateId) {
  const normalizedPlate = normalizePlateId(plateId);
  if (normalizedPlate.length < 5) {
    lastPlate = "";
    showVehicleInfo(null);
    return;
  }
  if (normalizedPlate === lastPlate) return;
  lastPlate = normalizedPlate;

  try {
    const response = await fetch(`/api/vehicle/${encodeURIComponent(normalizedPlate)}`, {
      headers: { Accept: "application/json" },
    });
    if (lastPlate !== normalizedPlate) return; // plate changed meanwhile
    showVehicleInfo(response.ok ? await response.json() : null);
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
    debounceTimer = setTimeout(() => fetchVehicleInfo(plateIdInput.value), 600);
  };

  plateIdInput.addEventListener("input", scheduleLookup);
  plateIdInput.addEventListener("change", scheduleLookup);
  if (plateIdInput.value) fetchVehicleInfo(plateIdInput.value);
}

/**
 * @param {string} plateId
 */
export function triggerVehicleInfoEnrichment(plateId) {
  fetchVehicleInfo(plateId);
}
