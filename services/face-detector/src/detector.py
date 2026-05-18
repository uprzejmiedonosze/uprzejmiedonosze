import re
import cv2
import dlib
import numpy as np
import httpx
from fastapi import HTTPException
from .logger import logger
from .face import Face

detector = dlib.cnn_face_detection_model_v1('./models/mmod_human_face_detector.dat')

_LOG_MAX = 300


def _sanitize(value: str) -> str:
    """Strip newlines and limit length to prevent log injection."""
    return re.sub(r'[\r\n]', ' ', str(value))[:_LOG_MAX]


def read_image(url: str):
    if not (url.startswith('https://') or url.startswith('http://')):
        raise HTTPException(status_code=400, detail="Only http(s):// URLs are supported")

    try:
        response = httpx.get(url, follow_redirects=True, timeout=10.0)
        response.raise_for_status()
    except httpx.HTTPStatusError as e:
        detail = f"HTTP {e.response.status_code} fetching '{_sanitize(url)}'"
        logger.error(detail)
        raise HTTPException(status_code=502, detail=detail) from e
    except Exception as e:
        logger.error(_sanitize(repr(e)))
        raise HTTPException(status_code=502, detail=repr(e)) from e

    nparr = np.frombuffer(response.content, np.uint8)
    image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
    if image is None:
        detail = f"Could not decode image from '{_sanitize(url)}'"
        logger.error(detail)
        raise HTTPException(status_code=422, detail=detail)
    return image


def scan_image(image: cv2.typing.MatLike):
    shape = image.shape
    max_image_size = 700
    resize = max_image_size / max(shape) if max(shape) > max_image_size else 1

    if max(shape) > max_image_size:
        image = dlib.resize_image(image, int(round(shape[0] * resize)), int(round(shape[1] * resize)))

    rgb_small_frame = image[:, :, ::-1]
    faces = detector(rgb_small_frame, 1)

    faces_array = []
    for f in faces:
        faces_array.append(Face(f.rect, resize))

    return faces_array
