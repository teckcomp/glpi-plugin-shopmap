<?php

namespace GlpiPlugin\Shopmap;

/**
 * Conversão de plantas para SVG (Bloco 2b).
 *
 * Formatos e ferramentas (todas chamadas por CLI, com timeout):
 *  - PDF  -> SVG : pdftocairo (poppler-utils), 1a página
 *  - DXF  -> SVG : python3 + ezdxf (tools/dxf2svg.py)
 *  - DWG  -> DXF : dwg2dxf (libredwg), se instalado; depois DXF -> SVG
 *
 * A conversão roda SÍNCRONA no upload (1–5 s para uma planta típica).
 * Decisão registrada: o job assíncrono via CronTask fica como
 * refinamento futuro se os uploads ficarem pesados — bloco menor e
 * testável primeiro.
 *
 * Segurança: todo caminho passa por escapeshellarg; nomes de arquivo
 * são gerados pelo plugin (plan_<id>/orig_<id>), nunca o nome enviado.
 */
class Converter
{
    /** Segundos máximos por conversão. */
    private const TIMEOUT = 180;

    /**
     * Ferramenta disponível? (cacheado por request)
     *
     * @var array<string,bool>
     */
    private static array $toolCache = [];

    public static function hasTool(string $tool): bool
    {
        if (!isset(self::$toolCache[$tool])) {
            $out = [];
            $rc  = 1;
            if ($tool === 'ezdxf') {
                @exec('python3 -c ' . escapeshellarg('import ezdxf') . ' 2>&1', $out, $rc);
            } else {
                @exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null', $out, $rc);
            }
            self::$toolCache[$tool] = ($rc === 0);
        }
        return self::$toolCache[$tool];
    }

    /**
     * Diagnóstico por extensão: '' quando dá para converter, senão a
     * mensagem do que falta (mostrada ao usuário no upload).
     */
    public static function missingFor(string $ext): string
    {
        switch ($ext) {
            case 'pdf':
                return self::hasTool('pdftocairo')
                    ? ''
                    : 'pdftocairo ausente — instale o pacote poppler-utils no servidor';
            case 'dxf':
                if (!self::hasTool('python3')) {
                    return 'python3 ausente no servidor';
                }
                return self::hasTool('ezdxf')
                    ? ''
                    : 'módulo Python ezdxf ausente — instale python3-ezdxf (ou pip3 install ezdxf)';
            case 'dwg':
                if (!self::hasTool('dwg2dxf')) {
                    return 'dwg2dxf ausente (pacote libredwg-tools). Alternativa: exporte DXF ou PDF no AutoCAD';
                }
                return self::missingFor('dxf');
        }
        return 'formato sem conversor';
    }

    /**
     * Converte $src (pdf/dxf/dwg) para $destSvg.
     *
     * @return array{0:bool,1:string} [sucesso, mensagem de erro]
     */
    public static function toSvg(string $src, string $ext, string $destSvg): array
    {
        $missing = self::missingFor($ext);
        if ($missing !== '') {
            return [false, $missing];
        }

        if ($ext === 'dwg') {
            $tmpDxf = $destSvg . '.tmp.dxf';
            [$ok, $err] = self::run(sprintf(
                'dwg2dxf -o %s %s',
                escapeshellarg($tmpDxf),
                escapeshellarg($src)
            ));
            if (!$ok || !is_file($tmpDxf) || filesize($tmpDxf) === 0) {
                @unlink($tmpDxf);
                return [false, 'DWG -> DXF falhou: ' . $err];
            }
            $res = self::toSvg($tmpDxf, 'dxf', $destSvg);
            @unlink($tmpDxf);
            return $res;
        }

        if ($ext === 'pdf') {
            [$ok, $err] = self::run(sprintf(
                'pdftocairo -svg -f 1 -l 1 %s %s',
                escapeshellarg($src),
                escapeshellarg($destSvg)
            ));
        } else { // dxf
            $script = dirname(__DIR__) . '/tools/dxf2svg.py';
            [$ok, $err] = self::run(sprintf(
                'python3 %s %s %s',
                escapeshellarg($script),
                escapeshellarg($src),
                escapeshellarg($destSvg)
            ));
        }

        if (!$ok) {
            @unlink($destSvg);
            return [false, $err];
        }
        if (!is_file($destSvg) || filesize($destSvg) === 0) {
            @unlink($destSvg);
            return [false, 'conversão terminou sem gerar o SVG'];
        }
        return [true, ''];
    }

    /**
     * Executa comando com timeout, capturando stderr.
     *
     * @return array{0:bool,1:string} [rc==0, últimas linhas da saída]
     */
    private static function run(string $cmd): array
    {
        $out = [];
        $rc  = 1;
        @exec('timeout ' . self::TIMEOUT . ' ' . $cmd . ' 2>&1', $out, $rc);
        if ($rc === 124) {
            return [false, 'tempo limite de conversão excedido (' . self::TIMEOUT . 's)'];
        }
        $tail = implode(' | ', array_slice($out, -3));
        return [$rc === 0, $rc === 0 ? '' : ($tail !== '' ? $tail : "código de saída $rc")];
    }
}
