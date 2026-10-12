"""Render the mini-program's transparent outline tab icons at 64 px."""

from pathlib import Path

from PIL import Image, ImageDraw


SCALE = 4
SIZE = 64
INK = (23, 25, 28, 255)
STROKE = round(4.2 * SCALE)
OUTPUT = Path(__file__).resolve().parents[2] / "miniprogram-native/static/tabbar"


def point(x, y):
    return round(x * SCALE), round(y * SCALE)


def stroke(draw, points):
    scaled = [point(x, y) for x, y in points]
    draw.line(scaled, fill=INK, width=STROKE, joint="curve")
    radius = STROKE // 2
    for x, y in (scaled[0], scaled[-1]):
        draw.ellipse((x - radius, y - radius, x + radius, y + radius), fill=INK)


def render(name, paint):
    image = Image.new("RGBA", (SIZE * SCALE, SIZE * SCALE), (0, 0, 0, 0))
    paint(ImageDraw.Draw(image))
    image.resize((SIZE, SIZE), Image.LANCZOS).save(OUTPUT / f"{name}-outline.png", optimize=True)


def home(draw):
    stroke(draw, [(8, 29), (32, 10), (56, 29)])
    stroke(draw, [(15, 24), (15, 53), (28, 53), (28, 40), (36, 40), (36, 53), (49, 53), (49, 24)])


def category(draw):
    for x in (9, 37):
        for y in (9, 37):
            draw.rectangle((*point(x, y), *point(x + 18, y + 18)), outline=INK, width=STROKE)


def bezier(start, control1, control2, end, steps=20):
    result = []
    for index in range(steps + 1):
        t = index / steps
        u = 1 - t
        result.append((
            u**3 * start[0] + 3 * u**2 * t * control1[0] + 3 * u * t**2 * control2[0] + t**3 * end[0],
            u**3 * start[1] + 3 * u**2 * t * control1[1] + 3 * u * t**2 * control2[1] + t**3 * end[1]
        ))
    return result


def my(draw):
    draw.ellipse((*point(23, 12), *point(41, 30)), outline=INK, width=STROKE)
    left = bezier((12, 53), (13, 43), (20, 38), (32, 38))
    right = bezier((32, 38), (44, 38), (51, 43), (52, 53))
    stroke(draw, left + right[1:])


if __name__ == "__main__":
    OUTPUT.mkdir(parents=True, exist_ok=True)
    render("home", home)
    render("category", category)
    render("my", my)
