#!/usr/bin/env python3
import sys
import json
import re
from extract_docx_text import extract_text_from_docx

FIELD_KEYS = [
    ('name', ['Nombre Completo', 'Nombre:', 'Nombre']),
    ('estado_civil', ['Estado Civil']),
    ('nombre_esposo', ['Nombre del Esposo', 'Nombre del Esposo:']),
    ('fecha_nacimiento', ['Fecha de Nacimiento', 'Fecha de Nacimiento:']),
    ('edad', ['Edad']),
    ('lugar_nacimiento', ['Lugar de Nacimiento']),
    ('email', ['E-mail', 'Email', 'E-mail:']),
    ('nacionalidad', ['Nacionalidad']),
    ('dpi', ['DPI', 'Documento']),
    ('tipo_sangre', ['Tipo de Sangre']),
    ('profesion', ['Profesión', 'Profesion']),
    ('lugar_trabajo', ['Lugar de Trabajo']),
    ('direccion', ['Dirección de Domicilio', 'Dirección', 'Direccion de Domicilio']),
    ('telefono_casa', ['No. De Teléfono de casa', 'Teléfono de casa', 'Teléfono']),
    ('celular', ['No. De Celular', 'Celular', 'No. De Celular:']),
    ('referido_por', ['Referido Por']),
    ('motivo_consulta', ['Motivo de la Consulta']),
    ('emitir_factura', ['Emitir Factura a Nombre de']),
    ('nit', ['NIT'])
]


def guess_value_after_label(paragraphs, idx):
    # prefer same paragraph content after ':'
    p = paragraphs[idx]
    if ':' in p:
        after = p.split(':', 1)[1].strip()
        if after and not set(after) <= set('_ '):
            return after
    # else look forward up to 2 paragraphs for non-empty and non-boilerplate
    for j in range(idx+1, min(len(paragraphs), idx+3)):
        v = paragraphs[j].strip()
        if v and not v.startswith('Consentimiento') and len(v) < 400:
            # ignore long boilerplate blocks
            if not set(v) <= set('_ '):
                return v
    return ''


def extract_fields(text):
    paragraphs = [p.strip() for p in text.split('\n\n') if p.strip()]
    data = {}
    lower_paras = [p.lower() for p in paragraphs]

    for key, labels in FIELD_KEYS:
        found = False
        for i, p in enumerate(paragraphs):
            for lab in labels:
                if p.lower().startswith(lab.lower()):
                    val = guess_value_after_label(paragraphs, i)
                    data[key] = val
                    found = True
                    break
            if found:
                break
        if not found:
            # try regex search anywhere like 'Label: value' in text
            for lab in labels:
                m = re.search(re.escape(lab) + r'\s*[:\-]\s*(.+)', text, flags=re.IGNORECASE)
                if m:
                    v = m.group(1).strip()
                    # stop at double newline or long boilerplate
                    v = v.split('\n\n')[0].strip()
                    data[key] = v
                    found = True
                    break
        if not found:
            data[key] = ''
    return data


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print('Usage: parse_patient_docx.py <file.docx> [output.json]')
        sys.exit(2)
    path = sys.argv[1]
    outpath = sys.argv[2] if len(sys.argv) >= 3 else 'parsed_patient.json'

    try:
        text = extract_text_from_docx(path)
    except Exception as e:
        print('ERROR extracting docx:', e)
        sys.exit(1)

    data = extract_fields(text)
    # Normalize some fields
    if data.get('fecha_nacimiento'):
        # try to normalize common formats d/m/Y or Y-m-d
        d = data['fecha_nacimiento']
        d = d.replace('.', '/').replace('-', '/').strip()
        data['fecha_nacimiento_normalized'] = d
    # Map names for DB insertion convenience
    db_record = {
        'name': data.get('name') or data.get('emitir_factura') or '',
        'email': data.get('email') or '',
        'phone': data.get('celular') or data.get('telefono_casa') or '',
        'birthday': data.get('fecha_nacimiento_normalized') or None,
        'address': data.get('direccion') or '',
        'nit': data.get('nit') or ''
    }

    with open(outpath, 'w', encoding='utf-8') as f:
        json.dump({'raw': data, 'db': db_record}, f, ensure_ascii=False, indent=2)

    print('Wrote parsed data to', outpath)
