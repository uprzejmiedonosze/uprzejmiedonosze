""" Face class that represents position of a face on image"""

from dataclasses import dataclass

@dataclass
class Face:
    """ Represents position of a face on image """
    x: int
    y: int
    w: int
    h: int
    def __init__(self, face_rect, resize):
        self.x = round(face_rect.left() / resize)
        self.y = round(face_rect.top() / resize)
        self.w = round(face_rect.width() / resize)
        self.h = round(face_rect.height() / resize)
        self.slice()

    def slice(self):
        """ Prepare bouding box for blur """
        ratio = 0.2
        self.x1 = round(self.x - self.w * ratio)
        self.x2 = round(self.x + self.w + self.w * ratio * 2)
        self.y1 = round(self.y - self.h * ratio)
        self.y2 = round(self.y + self.h + self.h * ratio * 2)
