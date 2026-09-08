import os
import re
import threading
import cv2
import dlib
import numpy as np
import httpx
from fastapi import HTTPException
from .logger import logger
from .face import Face

detector = dlib.cnn_face_detection_model_v1('./models/mmod_human_face_detector.dat')

_LOG_MAX = 300

# Hardening: bound memory used per request. Incident 2026-09-08 (ram_in_use
# WARNING at 91%): a single inference spiked this service 0.45 -> 1.9 GB.
_MAX_BYTES = 25 * 1024 * 1024
_MAX_PIXELS = 50_000_000
_CHUNK_SIZE = 65536
_infer_lock = threading.Lock()


def _sanitize(value: str) -> str:
    """Strip newlines and limit length to prevent log injection."""
    return re.sub(r'[\r\n]', ' ', str(value))[:_LOG_MAX]


def _too_large(source: str) -> HTTPException:
    detail = f"Image from '{_sanitize(source)}' exceeds limits " \
        f"({_MAX_BYTES} bytes / {_MAX_PIXELS} pixels)"
    logger.error(detail)
    return HTTPException(status_code=413, detail=detail)


def _decode_image(content: bytes, source: str):
    nparr = np.frombuffer(content, np.uint8)
    image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
    if image is None:
        detail = f"Could not decode image from '{_sanitize(source)}'"
        logger.error(detail)
        raise HTTPException(status_code=422, detail=detail)
    if image.shape[0] * image.shape[1] > _MAX_PIXELS:
        raise _too_large(source)
    return image


def _download(url: str) -> bytes:
    try:
        with httpx.stream("GET", url, follow_redirects=True, timeout=10.0) as response:
            response.raise_for_status()
            length = response.headers.get("content-length")
            if length is not None and int(length) > _MAX_BYTES:
                raise _too_large(url)
            content = bytearray()
            for chunk in response.iter_bytes(_CHUNK_SIZE):
                content += chunk
                if len(content) > _MAX_BYTES:
                    raise _too_large(url)
            return bytes(content)
    except httpx.HTTPStatusError as e:
        detail = f"HTTP {e.response.status_code} fetching '{_sanitize(url)}'"
        logger.error(detail)
        raise HTTPException(status_code=502, detail=detail) from e
    except HTTPException:
        raise
    except Exception as e:
        logger.error(_sanitize(repr(e)))
        raise HTTPException(status_code=502, detail=repr(e)) from e


def read_image(filename: str):
    if filename.startswith('http://') or filename.startswith('https://'):
        return _decode_image(_download(filename), filename)

    if not os.path.isfile(filename):
        detail = f"File '{_sanitize(filename)}' not found"
        logger.error(detail)
        raise HTTPException(status_code=404, detail=detail)
    with open(filename, 'rb') as f:
        content = f.read(_MAX_BYTES + 1)
    if len(content) > _MAX_BYTES:
        raise _too_large(filename)
    return _decode_image(content, filename)


def scan_image(image: cv2.typing.MatLike):
    shape = image.shape
    max_image_size = 700
    resize = max_image_size / max(shape) if max(shape) > max_image_size else 1

    if max(shape) > max_image_size:
        image = dlib.resize_image(image, int(round(shape[0] * resize)), int(round(shape[1] * resize)))

    rgb_small_frame = image[:, :, ::-1]
    # dlib CNN allocates hundreds of MB per inference; serialize so
    # concurrent requests cannot stack and exhaust host memory.
    with _infer_lock:
        faces = detector(rgb_small_frame, 1)

    faces_array = []
    for f in faces:
        faces_array.append(Face(f.rect, resize))

    return faces_array
