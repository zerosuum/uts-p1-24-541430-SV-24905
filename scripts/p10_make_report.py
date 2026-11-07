#!/usr/bin/env python3
"""Produce the submission PDF exclusively from real local Docker evidence.
Requires: python -m pip install reportlab. Refuses missing/unhealthy proof.
"""
import json
import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape

try:
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_CENTER
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import cm
    from reportlab.platypus import (
        BaseDocTemplate, Frame, KeepTogether, PageTemplate, Paragraph,
        Preformatted, Spacer, Table, TableStyle, PageBreak,
    )
except ImportError:
    raise SystemExit('reportlab belum terpasang. Jalankan: .venv-p10/bin/pip install reportlab')

ROOT = Path(__file__).resolve().parents[1]
EVD = ROOT / 'evidence-p10'
OUT = ROOT / 'P10_24-541430-SV-24905_Della-Nurizki.pdf'
REQUIRED = [
    'healthy-ps.txt', 'healthy-health.json', 'healthy-http-status.txt',
    'unhealthy-ps.txt', 'unhealthy-health.json', 'unhealthy-http-status.txt',
    'restored-ps.txt', 'restored-health.json', 'restored-http-status.txt',
    'structured-logs.txt', 'log-rotation.json', 'demo-events.txt',
]

def read(name):
    return (EVD / name).read_text(encoding='utf-8').strip()

def validate():
    missing = [name for name in REQUIRED if not (EVD / name).is_file()]
    if missing:
        raise ValueError('Bukti belum lengkap: ' + ', '.join(missing)
                         + '\nJalankan dulu: bash scripts/p10_capture_evidence.sh')
    for phase, status in [('healthy', 200), ('unhealthy', 503), ('restored', 200)]:
        actual = read(phase + '-http-status.txt')
        if actual != str(status):
            raise ValueError(f'{phase} HTTP harus {status}, ditemukan {actual}')
        doc = json.loads(read(phase + '-health.json'))
        expected = 'unhealthy' if phase == 'unhealthy' else 'healthy'
        if doc.get('status') != expected:
            raise ValueError(f'{phase} JSON.status seharusnya {expected}')
        if doc.get('checks', {}).get('database', {}).get('status') != expected:
            raise ValueError(f'{phase} checks.database tidak sesuai')
        if phase != 'unhealthy' and doc.get('checks', {}).get('cache', {}).get('status') != 'healthy':
            raise ValueError(f'{phase} checks.cache belum healthy')
        if expected not in read(phase+'-ps.txt').lower():
            raise ValueError(f'{phase} docker compose ps tidak menunjukkan {expected}')
    logs = read('structured-logs.txt')
    for event in ['task.created', 'task.validation_failed', 'health.dependency_failed']:
        if event not in logs:
            raise ValueError(f'Log {event} belum terbukti: jalankan script pengumpulan lagi')
    rotation = json.loads(read('log-rotation.json'))
    if rotation.get('Type') != 'json-file' or rotation.get('Config', {}).get('max-size') != '5m' or rotation.get('Config', {}).get('max-file') != '3':
        raise ValueError('Bukti log rotation belum menunjukkan json-file 5m x 3')
    return rotation

STYLES=getSampleStyleSheet()
NAVY=colors.HexColor('#162B47')
BLUE=colors.HexColor('#2C577D')
TEXT=colors.HexColor('#263646')
MUTED=colors.HexColor('#64748B')
STYLES.add(ParagraphStyle(name='P10Title', fontName='Helvetica-Bold', fontSize=18,
    leading=23, textColor=NAVY, spaceAfter=14))
STYLES.add(ParagraphStyle(name='P10H1', fontName='Helvetica-Bold', fontSize=11,
    leading=15, textColor=NAVY, spaceBefore=15, spaceAfter=8, keepWithNext=True))
STYLES.add(ParagraphStyle(name='P10H2', fontName='Helvetica-Bold', fontSize=9.5,
    leading=13, textColor=BLUE, spaceBefore=10, spaceAfter=5, keepWithNext=True))
STYLES.add(ParagraphStyle(name='P10Body', fontName='Helvetica', fontSize=9,
    leading=13.5, textColor=TEXT, spaceAfter=8))
STYLES.add(ParagraphStyle(name='P10Small', fontName='Helvetica', fontSize=8,
    leading=11.5, textColor=MUTED, spaceAfter=7))
STYLES.add(ParagraphStyle(name='P10Code', fontName='Courier', fontSize=7.25,
    leading=10, leftIndent=5, textColor=TEXT, backColor=colors.HexColor('#F5F7FA'),
    borderPadding=6, spaceAfter=10))

def para(txt, style='P10Body'):
    return Paragraph(escape(txt), STYLES[style])

def code(txt, max_lines=26):
    lines = txt.strip().splitlines()
    if len(lines) > max_lines:
        lines = lines[:max_lines] + ['... (lanjutan tersedia pada berkas bukti lokal)']
    wrapped=[]
    for line in lines:
        if len(line)>106:
            line=line[:103]+'...'
        wrapped.append(line)
    return Preformatted('\n'.join(wrapped), STYLES['P10Code'])

def header_footer(canvas, doc):
    canvas.saveState()
    width, height = A4
    canvas.setStrokeColor(colors.HexColor('#D9E1E8'))
    canvas.line(1.8*cm, 1.65*cm, width-1.8*cm, 1.65*cm)
    canvas.setFillColor(MUTED)
    canvas.setFont('Helvetica',8)
    canvas.drawString(1.8*cm,1.25*cm,'MulaiDulu - Tugas 6 (P10) | Teknik Rekayasa Perangkat Lunak')
    canvas.drawRightString(width-1.8*cm,1.25*cm,str(doc.page))
    canvas.restoreState()


def extract_structured_events(raw):
    selected = {}
    for line in raw.splitlines():
        pos=line.find('{')
        if pos<0:
            continue
        try:
            item=json.loads(line[pos:])
        except json.JSONDecodeError:
            continue
        event=item.get('message')
        if event in ('task.created','task.validation_failed','health.dependency_failed') and event not in selected:
            safe = {
                'level': item.get('level_name'),
                'event': event,
                'context': item.get('context', {}),
            }
            selected[event]=json.dumps(safe,ensure_ascii=False,indent=2)
    missing=[x for x in ('task.created','task.validation_failed','health.dependency_failed') if x not in selected]
    if missing:
        raise ValueError('Log JSON tidak dapat dibaca: '+', '.join(missing))
    return selected


def build():
    rotation=validate()
    selected=extract_structured_events(read('structured-logs.txt'))
    doc = BaseDocTemplate(str(OUT), pagesize=A4, rightMargin=1.8*cm, leftMargin=1.8*cm,
                          topMargin=1.8*cm, bottomMargin=2*cm)
    frame=Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height,
                leftPadding=0,rightPadding=0,topPadding=0,bottomPadding=0)
    doc.addPageTemplates(PageTemplate(id='normal',frames=[frame],onPage=header_footer))
    content=[]
    content += [para('LAPORAN TUGAS 6 - OBSERVABILITY DAN HEALTHCHECK','P10Title'),
        para('Mata kuliah: Konstruksi dan Evolusi Perangkat Lunak'),
        para('Nama: Della Nurizki     |     NIM: 24/541430/SV/24905     |     Kelas: PL5AA'),
        para('Repository: https://github.com/zerosuum/uts-p1-24-541430-SV-24905','P10Small'),
        para('A. Tujuan dan konfigurasi','P10H1'),
        para('Implementasi ini melanjutkan aplikasi MulaiDulu berbasis Laravel, Vue, FrankenPHP, dan PostgreSQL yang telah dijalankan dengan Docker Compose. Fokus tugas adalah observability: aliran log aplikasi ke stdout, log terstruktur, readiness check terhadap database dan cache, healthcheck container, dan pembatasan ukuran log.'),
        para('Laravel mengirimkan log JSON menggunakan Monolog StreamHandler ke php://stdout. Docker menangkap log dari stdout melalui driver json-file. Tidak ada password, token, email, nama pengguna, ataupun isi task yang ditulis pada log aplikasi.'),
        para('B. Pemilihan tiga alur log','P10H1')]
    rows=[['Level / peristiwa','Alur yang dipilih dan alasannya'],
          ['INFO - task.created','Mencatat berhasilnya pembuatan tugas. Konteks berisi aktor pseudonim, operasi, dan alasan. Isi tugas tidak dicatat.'],
          ['WARNING - task.validation_failed','Mencatat permintaan ditolak karena validasi. Hanya nama field invalid yang dicatat, bukan nilai input.'],
          ['ERROR - health.dependency_failed','Mencatat kegagalan pemeriksaan PostgreSQL atau cache. Pesan exception/SQL tidak disertakan.']]
    cells=[[Paragraph(escape(str(v)),STYLES['P10Small' if i else 'P10Body']) for v in row] for i,row in enumerate(rows)]
    tab=Table(cells,colWidths=[5.1*cm,11.4*cm],repeatRows=1,hAlign='LEFT')
    tab.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),colors.HexColor('#E8F0F8')),
        ('GRID',(0,0),(-1,-1),0.4,colors.HexColor('#DBE3EB')),
        ('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),8),
        ('RIGHTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),7),
        ('BOTTOMPADDING',(0,0),(-1,-1),7)]))
    content.extend([tab,Spacer(1,0.35*cm)])
    content.append(para('C. Bukti tiga level log terstruktur','P10H1'))
    for label, event in [('INFO: menyimpan task','task.created'),
                         ('WARNING: validasi gagal','task.validation_failed'),
                         ('ERROR: basis data tidak tersedia','health.dependency_failed')]:
        content.append(para(label,'P10H2'))
        content.append(code(selected[event],max_lines=24))
    content.append(para('Bukti diperoleh dari docker compose logs backend dan disaring berdasarkan event. actor_ref merupakan pseudonim HMAC; tidak merekam ID pengguna sebenarnya.','P10Small'))

    content.append(para('D. Bukti healthcheck saat semua service sehat','P10H1'))
    content.append(para('Output docker compose ps --all (sehat)','P10H2'))
    content.append(code(read('healthy-ps.txt'),max_lines=12))
    content.append(para('GET /health - HTTP '+read('healthy-http-status.txt'),'P10H2'))
    content.append(code(json.dumps(json.loads(read('healthy-health.json')),indent=2),max_lines=20))

    content.append(para('E. Simulasi kegagalan layanan database','P10H1'))
    content.append(para('Layanan PostgreSQL dimatikan menggunakan docker compose stop db. Docker readiness probe pada backend melakukan query database dan baca/tulis cache, sehingga setelah tiga percobaan gagal status backend berubah menjadi unhealthy. Karena cache menggunakan driver database, kedua komponen dapat gagal sekaligus.'))
    content.append(para('Output docker compose ps --all (tidak sehat)','P10H2'))
    content.append(code(read('unhealthy-ps.txt'),max_lines=12))
    content.append(para('GET /health - HTTP '+read('unhealthy-http-status.txt'),'P10H2'))
    content.append(code(json.dumps(json.loads(read('unhealthy-health.json')),indent=2),max_lines=22))

    content.append(para('F. Pemulihan layanan dan pembatasan log','P10H1'))
    content.append(para('Layanan PostgreSQL diaktifkan kembali melalui docker compose start db. Readiness probe berhasil lagi, dan endpoint kembali membalas HTTP 200. Pengujian ini tidak menghapus volume database.'))
    content.append(para('Output docker compose ps --all (pulih)','P10H2'))
    content.append(code(read('restored-ps.txt'),max_lines=12))
    content.append(para('GET /health - HTTP '+read('restored-http-status.txt'),'P10H2'))
    content.append(code(json.dumps(json.loads(read('restored-health.json')),indent=2),max_lines=20))
    content.append(para('Konfigurasi rotasi log yang terpasang pada container backend','P10H2'))
    content.append(code(json.dumps(rotation,indent=2),max_lines=12))
    content.append(para('Docker Compose mengatur logging driver json-file, max-size 5m, serta max-file 3 pada db, backend, dan frontend. Batas ini mencegah pertumbuhan log container tanpa kendali.'))

    content.append(para('G. Kesimpulan','P10H1'))
    content.append(para('Laravel kini menghasilkan log terstruktur melalui stdout dan dapat diperiksa menggunakan docker compose logs. Endpoint /health mengecek dua dependensi nyata, mengembalikan 200 pada kondisi sehat serta 503 ketika tidak sehat. Healthcheck Docker berhasil mengubah status backend menjadi unhealthy ketika database dimatikan dan menjadi healthy kembali setelah dipulihkan. Pengaturan rotasi log membatasi akumulasi ukuran berkas log.'))
    content.append(para('Lampiran bukti mentah: evidence-p10/ (tersimpan secara lokal, tidak di-commit untuk melindungi data diagnostik).','P10Small'))
    doc.build(content)
    print('Report created from VERIFIED local evidence:', OUT)

if __name__ == '__main__':
    try:
        build()
    except (ValueError, FileNotFoundError, KeyError) as exc:
        print('Laporan belum bisa dibuat:',exc,file=sys.stderr)
        sys.exit(1)
