#!/usr/bin/env python3
"""
ShopMap — conversor DXF -> SVG (Bloco 2b).

Uso: dxf2svg.py entrada.dxf saida.svg

Usa o backend SVG nativo do ezdxf (>= 1.1). Arquivos malformados passam
pelo modo de recuperação (ezdxf.recover) antes de falhar. Sai com código
0 em sucesso; !=0 com a mensagem de erro no stderr (o PHP captura).
"""

import sys


def fail(msg: str) -> None:
    print(msg, file=sys.stderr)
    sys.exit(1)


def main() -> None:
    if len(sys.argv) != 3:
        fail("uso: dxf2svg.py entrada.dxf saida.svg")

    src, dest = sys.argv[1], sys.argv[2]

    try:
        import ezdxf
        from ezdxf import recover
        from ezdxf.addons.drawing import Frontend, RenderContext, layout, svg
    except ImportError as exc:
        fail(f"ezdxf indisponivel: {exc}")

    # Leitura normal; se falhar, modo de recuperacao (DXF de campo vem
    # de tudo quanto e versao de AutoCAD).
    try:
        doc = ezdxf.readfile(src)
    except Exception:
        try:
            doc, auditor = recover.readfile(src)
            if auditor.has_errors:
                # Erros auditados mas documento utilizavel: segue.
                pass
        except Exception as exc:
            fail(f"DXF ilegivel: {exc}")

    try:
        msp = doc.modelspace()
        context = RenderContext(doc)
        backend = svg.SVGBackend()
        Frontend(context, backend).draw_layout(msp)

        # Page(0, 0) = tamanho automatico pelo conteudo do desenho
        page = layout.Page(0, 0, layout.Units.mm, margins=layout.Margins.all(5))
        svg_string = backend.get_string(page)
    except Exception as exc:
        fail(f"falha ao renderizar DXF: {exc}")

    if not svg_string or "<svg" not in svg_string:
        fail("renderizacao produziu SVG vazio")

    try:
        with open(dest, "w", encoding="utf-8") as fh:
            fh.write(svg_string)
    except OSError as exc:
        fail(f"falha ao gravar SVG: {exc}")

    sys.exit(0)


if __name__ == "__main__":
    main()
