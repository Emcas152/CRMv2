#!/usr/bin/env python3
import sys
import zipfile
import xml.etree.ElementTree as ET
from io import BytesIO

def extract_text_from_docx(path):
    with zipfile.ZipFile(path) as z:
        with z.open('word/document.xml') as f:
            xml = f.read()
    # parse XML
    root = ET.fromstring(xml)
    # WordprocessingML uses namespaces; find it
    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    paragraphs = []
    for p in root.findall('.//w:p', ns):
        texts = []
        for r in p.findall('.//w:t', ns):
            if r.text:
                texts.append(r.text)
        if texts:
            paragraphs.append(''.join(texts))
    return '\n\n'.join(paragraphs)

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print('Usage: extract_docx_text.py <file.docx>')
        sys.exit(2)
    path = sys.argv[1]
    try:
        text = extract_text_from_docx(path)
        print(text)
    except Exception as e:
        print('ERROR:', e)
        sys.exit(1)
