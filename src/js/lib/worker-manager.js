// Worker Manager for handling Web Workers efficiently

// Import constants
import {
  WORKER_TYPES,
  WORKER_MESSAGE_TYPES,
  ERROR_MESSAGES,
  DEFAULT_VALUES
} from './constants';

// Pre-compiled worker code for better performance
const IMAGE_WORKER_CODE = `
  self.onmessage = async (e) => {
    const { type, imageData, options, id } = e.data;
    
    if (type === '${WORKER_TYPES.RESIZE}') {
      try {
        const result = await resizeImage(imageData, options);
        self.postMessage({ 
          type: '${WORKER_MESSAGE_TYPES.RESIZE_COMPLETE}', 
          result: result,
          id: id 
        });
      } catch (error) {
        self.postMessage({ 
          type: '${WORKER_MESSAGE_TYPES.RESIZE_ERROR}', 
          error: error.message,
          id: id 
        });
      }
    }
  };

  async function resizeImage(file, options = {}) {
    // Create image data from file inside the worker
    const imageBitmap = await createImageBitmap(file);
    const canvas = new OffscreenCanvas(imageBitmap.width, imageBitmap.height);
    const context = canvas.getContext("2d");
    
    context.drawImage(imageBitmap, 0, 0);
    const imgData = context.getImageData(0, 0, canvas.width, canvas.height);

    const MAX_WIDTH = options.maxWidth || ${DEFAULT_VALUES.MAX_WIDTH};
    const MAX_HEIGHT = options.maxHeight || ${DEFAULT_VALUES.MAX_HEIGHT};
    let canvasWidth = imgData.width;
    let canvasHeight = imgData.height;

    if (canvasWidth > canvasHeight) {
      if (canvasWidth > MAX_WIDTH) {
        canvasHeight *= MAX_WIDTH / canvasWidth;
        canvasWidth = MAX_WIDTH;
      }
    } else {
      if (canvasHeight > MAX_HEIGHT) {
        canvasWidth *= MAX_HEIGHT / canvasHeight;
        canvasHeight = MAX_HEIGHT;
      }
    }

    canvas.width = canvasWidth;
    canvas.height = canvasHeight;

    const bitmap = await createImageBitmap(imgData);
    context.drawImage(bitmap, 0, 0, canvasWidth, canvasHeight);
    
    const blob = await canvas.convertToBlob({ 
      type: "${DEFAULT_VALUES.IMAGE_TYPE}", 
      quality: options.quality || ${DEFAULT_VALUES.IMAGE_QUALITY} 
    });
    
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.readAsDataURL(blob);
    });
  }
`;

const EXIF_WORKER_CODE = `
  importScripts('https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.min.js');

  self.onmessage = async (e) => {
    const { type, fileData, id } = e.data;
    if (type === '${WORKER_TYPES.PARSE_EXIF}') {
      try {
        const exifData = await exifr.parse(fileData, {
          gps: true,
          datetime: true,
          orientation: false,
          thumbnail: false
        });
        
        let dateTime = null;
        if (exifData?.DateTimeOriginal || exifData?.CreateDate || exifData?.DateTime) {
          const dateObj = exifData.DateTimeOriginal || exifData.CreateDate || exifData.DateTime;
          // Return raw date object - let main thread handle formatting
          dateTime = dateObj;
        }
        
        const result = {
          lat: exifData?.latitude || null,
          lng: exifData?.longitude || null,
          dateTime: dateTime
        };
        
        self.postMessage({ type: '${WORKER_MESSAGE_TYPES.EXIF_COMPLETE}', result: result, id: id });
      } catch (err) {
        self.postMessage({ type: '${WORKER_MESSAGE_TYPES.EXIF_ERROR}', error: err.message, id: id });
      }
    }
  };
`;

// Pre-compile worker blobs for faster instantiation
const IMAGE_WORKER_BLOB = new Blob([IMAGE_WORKER_CODE], { type: 'application/javascript' });
const EXIF_WORKER_BLOB = new Blob([EXIF_WORKER_CODE], { type: 'application/javascript' });

class WorkerManager {
  constructor() {
    this.isSupported = typeof Worker !== 'undefined';
    this.imageWorkerPool = [];
    this.exifWorkerPool = [];
    // Optimize worker counts based on device capabilities
    this.maxImageWorkers = this.getOptimalWorkerCount(3, 2);
    this.maxExifWorkers = 1;  // Keep single EXIF worker for sequential processing
    this.activeRequests = new Map();
    this.workerURLs = new Set(); // Track URLs for cleanup
    
    // Pre-spawn workers for instant availability
    this.preSpawnWorkers();
  }
  
  // Determine optimal worker count based on device capabilities
  getOptimalWorkerCount(defaultCount, minCount) {
    const cores = navigator.hardwareConcurrency || 4;
    const memory = navigator.deviceMemory || 4;
    
    // Scale workers based on available cores and memory
    let optimalCount = Math.min(defaultCount, Math.floor(cores / 2));
    
    // Reduce workers on low-memory devices
    if (memory < 4) {
      optimalCount = Math.max(minCount, Math.floor(optimalCount / 2));
    }
    
    return Math.max(minCount, optimalCount);
  }
  
  // Pre-spawn all workers immediately for instant availability
  preSpawnWorkers() {
    if (!this.isSupported) return;
    
    // Create maximum number of image workers
    for (let i = 0; i < this.maxImageWorkers; i++) {
      const worker = this.createImageWorker();
      this.imageWorkerPool.push(worker);
    }
    
    // Create maximum number of EXIF workers
    for (let i = 0; i < this.maxExifWorkers; i++) {
      const worker = this.createExifWorker();
      this.exifWorkerPool.push(worker);
    }
    
    console.log(`[WorkerManager] Pre-spawned ${this.maxImageWorkers} image workers and ${this.maxExifWorkers} EXIF workers`);
  }
  
  async processImageInWorker(file, options = {}) {
    if (!this.isSupported) {
      throw new Error(ERROR_MESSAGES.WEB_WORKERS_NOT_SUPPORTED);
    }

    // Get available worker from pre-spawned pool
    const worker = this.imageWorkerPool.find(w => !w.busy);
    if (!worker) {
      throw new Error(ERROR_MESSAGES.NO_AVAILABLE_IMAGE_WORKERS);
    }
    
    return this.executeImageResize(worker, file, options);
  }
  
  createImageWorker() {
    const workerUrl = URL.createObjectURL(IMAGE_WORKER_BLOB);
    this.workerURLs.add(workerUrl);
    const worker = new Worker(workerUrl);
    worker.busy = false;
    worker.url = workerUrl; // Store URL for cleanup
    return worker;
  }
  
  async executeImageResize(worker, file, options) {
    return new Promise((resolve, reject) => {
      const requestId = Date.now() + Math.random();
      worker.busy = true;
      
      // Add timeout for hanging operations
      const timeout = setTimeout(() => {
        worker.removeEventListener('message', messageHandler);
        worker.busy = false;
        reject(new Error(ERROR_MESSAGES.IMAGE_RESIZE_TIMEOUT));
      }, DEFAULT_VALUES.TIMEOUT_IMAGE_RESIZE);
      
      const messageHandler = (e) => {
        if (e.data.id === requestId) {
          clearTimeout(timeout);
          worker.removeEventListener('message', messageHandler);
          worker.busy = false;
          
          if (e.data.type === WORKER_MESSAGE_TYPES.RESIZE_COMPLETE) {
            resolve(e.data.result);
          } else if (e.data.type === WORKER_MESSAGE_TYPES.RESIZE_ERROR) {
            reject(new Error(e.data.error));
          }
        }
      };
      
      worker.addEventListener('message', messageHandler);
      worker.postMessage({
        type: WORKER_TYPES.RESIZE,
        imageData: file,
        options: options,
        id: requestId
      });
    });
  }
  
  async parseExifInWorker(file) {
    if (!this.isSupported) {
      throw new Error(ERROR_MESSAGES.WEB_WORKERS_NOT_SUPPORTED);
    }

    // Get available worker from pre-spawned pool
    const worker = this.exifWorkerPool.find(w => !w.busy);
    if (!worker) {
      throw new Error(ERROR_MESSAGES.NO_AVAILABLE_EXIF_WORKERS);
    }
    
    return this.executeExifParse(worker, file);
  }
  
  createExifWorker() {
    const workerUrl = URL.createObjectURL(EXIF_WORKER_BLOB);
    this.workerURLs.add(workerUrl);
    const worker = new Worker(workerUrl);
    worker.busy = false;
    worker.url = workerUrl; // Store URL for cleanup
    return worker;
  }
  
  async executeExifParse(worker, file) {
    return new Promise((resolve, reject) => {
      const requestId = Date.now() + Math.random();
      worker.busy = true;
      
      // Add timeout for hanging operations
      const timeout = setTimeout(() => {
        worker.removeEventListener('message', messageHandler);
        worker.busy = false;
        reject(new Error(ERROR_MESSAGES.EXIF_PARSE_TIMEOUT));
      }, DEFAULT_VALUES.TIMEOUT_EXIF_PARSE);
      
      const messageHandler = (e) => {
        if (e.data.id === requestId) {
          clearTimeout(timeout);
          worker.removeEventListener('message', messageHandler);
          worker.busy = false;
          
          if (e.data.type === WORKER_MESSAGE_TYPES.EXIF_COMPLETE) {
            resolve(e.data.result);
          } else if (e.data.type === WORKER_MESSAGE_TYPES.EXIF_ERROR) {
            reject(new Error(e.data.error));
          }
        }
      };
      
      worker.addEventListener('message', messageHandler);
      worker.postMessage({
        type: WORKER_TYPES.PARSE_EXIF,
        fileData: file,
        id: requestId
      });
    });
  }
  
  // Get worker pool status for monitoring
  getWorkerPoolStatus() {
    const imageWorkers = this.imageWorkerPool.length;
    const busyImageWorkers = this.imageWorkerPool.filter(w => w.busy).length;
    const exifWorkers = this.exifWorkerPool.length;
    const busyExifWorkers = this.exifWorkerPool.filter(w => w.busy).length;
    
    return {
      imageWorkers: { total: imageWorkers, busy: busyImageWorkers, available: imageWorkers - busyImageWorkers },
      exifWorkers: { total: exifWorkers, busy: busyExifWorkers, available: exifWorkers - busyExifWorkers },
      maxImageWorkers: this.maxImageWorkers,
      maxExifWorkers: this.maxExifWorkers
    };
  }
  
  // Clean up workers when no longer needed
  destroy() {
    [...this.imageWorkerPool, ...this.exifWorkerPool].forEach(worker => {
      if (worker) {
        worker.terminate();
        if (worker.url) URL.revokeObjectURL(worker.url);
      }
    });
    this.imageWorkerPool = [];
    this.exifWorkerPool = [];
    this.workerURLs.forEach(url => URL.revokeObjectURL(url)); // Clean up URL objects
    this.workerURLs.clear(); // Clear the set
  }
}

// Create singleton instance
const workerManager = new WorkerManager();

export default workerManager; 