import fitz
import os

pdf_path = os.path.join('src', 'assets', 'brand', 'Brandbook V Medical Spa.pdf')
out_dir = os.path.join('src', 'assets', 'brand', 'extracted')

os.makedirs(out_dir, exist_ok=True)

print('Opening', pdf_path)

doc = fitz.open(pdf_path)
count = 0
for page_index in range(len(doc)):
    page = doc[page_index]
    image_list = page.get_images(full=True)
    if not image_list:
        continue
    print(f'Page {page_index} has {len(image_list)} images')
    for img_index, img in enumerate(image_list, start=1):
        xref = img[0]
        base_image = doc.extract_image(xref)
        image_bytes = base_image['image']
        image_ext = base_image.get('ext', 'png')
        out_path = os.path.join(out_dir, f'page{page_index+1}_img{img_index}.{image_ext}')
        with open(out_path, 'wb') as f:
            f.write(image_bytes)
        print('Saved', out_path)
        count += 1

print('Extracted', count, 'images')
