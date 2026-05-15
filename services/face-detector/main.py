import io

import cv2
from starlette.responses import StreamingResponse
from fastapi import FastAPI, HTTPException
from src.logger import logger
from src.detector import read_image, scan_image

app = FastAPI()


@app.get("/detect/{filename:path}")
def detect(filename: str):
    try:
        image = read_image(filename)
        faces_array = scan_image(image)
        return {"count": len(faces_array), "faces": faces_array}
    except Exception as e:
        logger.error(e)
        raise HTTPException(status_code=500, detail=repr(e)) from e

@app.get("/blur/{filename:path}")
def blur(filename: str):
    try:
        image = read_image(filename)
        faces_array = scan_image(image)

        for face in faces_array:
            roi = image[face.y1:face.y2, face.x1:face.x2]
            blur_image = cv2.GaussianBlur(roi,(51,51),0)
            image[face.y1:face.y2, face.x1:face.x2] = blur_image

        _res, im_png = cv2.imencode(".jpg", image)
        return StreamingResponse(io.BytesIO(im_png.tobytes()), media_type="image/jpeg")
    except Exception as e:
        logger.error(e)
        raise HTTPException(status_code=500, detail=repr(e)) from e
