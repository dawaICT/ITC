from PIL import Image
from collections import Counter
import colorsys

def rgb_to_hex(rgb):
    return '#{:02x}{:02x}{:02x}'.format(rgb[0], rgb[1], rgb[2])

def rgb_to_hsl(rgb):
    r, g, b = rgb[0]/255.0, rgb[1]/255.0, rgb[2]/255.0
    h, l, s = colorsys.rgb_to_hls(r, g, b)
    return f'hsl({int(h*360)}, {int(s*100)}%, {int(l*100)}%)'

def analyze_logo_colors(image_path):
    # Open and analyze the image
    img = Image.open(image_path)
    img = img.convert('RGB')

    # Get all pixels
    pixels = list(img.getdata())

    # Remove white/transparent pixels (assuming they're background)
    filtered_pixels = [pixel for pixel in pixels if not (pixel[0] > 240 and pixel[1] > 240 and pixel[2] > 240)]

    if not filtered_pixels:
        filtered_pixels = pixels

    # Most common colors
    color_counts = Counter(filtered_pixels)
    most_common = color_counts.most_common(15)  # Get top 15 for better analysis

    print(f"Analyzing logo: {image_path}")
    print(f"Image size: {img.size}")
    print(f"Total pixels analyzed: {len(filtered_pixels)}")
    print("\n=== TOP COLORS IN LOGO ===")

    # Filter out very similar colors and get unique dominant colors
    dominant_colors = []
    for (r, g, b), count in most_common:
        # Skip if too similar to existing colors (within 30 units)
        is_unique = True
        for existing_r, existing_g, existing_b in dominant_colors:
            if abs(r - existing_r) < 30 and abs(g - existing_g) < 30 and abs(b - existing_b) < 30:
                is_unique = False
                break

        if is_unique and len(dominant_colors) < 5:  # Limit to top 5 unique colors
            dominant_colors.append((r, g, b))
            hex_color = rgb_to_hex((r, g, b))
            hsl_color = rgb_to_hsl((r, g, b))
            percentage = (count / len(filtered_pixels)) * 100
            print(f"#{hex_color} | RGB({r}, {g}, {b}) | {hsl_color} | {percentage:.1f}%")

# Analyze the logo
analyze_logo_colors('images/LOGO2.jpeg')
