#!/usr/bin/env python3
"""
ShopMap — Bloco 7b: embrulha o PNG do recorte num PDF A4 paisagem com
cabeçalho (nome da planta à esquerda, data/hora à direita), via Pillow.

Uso: png2pdf.py <entrada.png> <saida.pdf> <titulo> <data_exibida>

Decisões (usuário, 06/08/2026): A4 paisagem, cabeçalho com planta+data.
O PDF é efêmero — gerado, entregue ao navegador e apagado; o histórico
guarda só o registro (ExportLog).
"""
import sys

from PIL import Image, ImageDraw, ImageFont

# A4 paisagem a 150 dpi (equilíbrio qualidade x tamanho do arquivo)
DPI = 150
PAGE_W = int(11.69 * DPI)   # 1753
PAGE_H = int(8.27 * DPI)    # 1240
MARGIN = int(0.35 * DPI)    # ~53 px
HEADER_H = int(0.45 * DPI)  # ~67 px (texto + régua)

FONT_CANDIDATES = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
]


def load_font(size):
    for path in FONT_CANDIDATES:
        try:
            return ImageFont.truetype(path, size)
        except OSError:
            continue
    return ImageFont.load_default()


def main():
    if len(sys.argv) != 5:
        print('uso: png2pdf.py entrada.png saida.pdf titulo data', file=sys.stderr)
        return 2

    src, dest, title, date_str = sys.argv[1:5]

    art = Image.open(src)
    if art.mode != 'RGB':
        art = art.convert('RGB')

    page = Image.new('RGB', (PAGE_W, PAGE_H), '#ffffff')
    drawer = ImageDraw.Draw(page)

    # --- cabeçalho: título à esquerda, data à direita, régua abaixo ---
    font_title = load_font(30)
    font_date = load_font(22)
    baseline = MARGIN
    drawer.text((MARGIN, baseline), title, fill='#1a1f3a', font=font_title)
    try:
        dw = drawer.textlength(date_str, font=font_date)
    except AttributeError:  # Pillow antigo
        dw = drawer.textsize(date_str, font=font_date)[0]
    drawer.text((PAGE_W - MARGIN - dw, baseline + 6), date_str, fill='#555555', font=font_date)
    rule_y = MARGIN + HEADER_H - 10
    drawer.line([(MARGIN, rule_y), (PAGE_W - MARGIN, rule_y)], fill='#cccccc', width=2)

    # --- imagem: encaixa na área útil mantendo proporção, centralizada ---
    area_x = MARGIN
    area_y = rule_y + 14
    area_w = PAGE_W - 2 * MARGIN
    area_h = PAGE_H - area_y - MARGIN
    scale = min(area_w / art.width, area_h / art.height)
    new_w = max(1, int(art.width * scale))
    new_h = max(1, int(art.height * scale))
    art = art.resize((new_w, new_h), Image.LANCZOS)
    page.paste(art, (area_x + (area_w - new_w) // 2, area_y + (area_h - new_h) // 2))

    page.save(dest, 'PDF', resolution=DPI)
    return 0


if __name__ == '__main__':
    sys.exit(main())
