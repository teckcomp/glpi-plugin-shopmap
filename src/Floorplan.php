<?php

namespace GlpiPlugin\Shopmap;

/**
 * Plantas baixas — modelo do Bloco 2a.
 *
 * Neste bloco o plugin aceita SVG (formato-alvo, zoom sem perda) e
 * PNG/JPG (uso imediato, com a ressalva de desfoque no zoom). DWG/PDF
 * entram no Bloco 2b via job de conversão — por ora são recusados com
 * mensagem clara, para não criar registro morto.
 *
 * Armazenamento: files/_plugins/shopmap/plan_<id>.<ext>
 * (GLPI_PLUGIN_DOC_DIR não é servido pelo Apache; o arquivo volta ao
 * navegador só por front/planfile.php, com checagem de direito.)
 */
class Floorplan
{
    /** Extensões aceitas neste bloco (minúsculas). */
    public const ALLOWED_EXT = ['svg', 'png', 'jpg', 'jpeg'];

    /** Extensões reservadas para o Bloco 2b (conversão). */
    public const FUTURE_EXT = ['dwg', 'dxf', 'pdf'];

    /**
     * Diretório físico dos arquivos de planta (cria se não existir).
     */
    public static function dir(): string
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/shopmap';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Caminho físico do arquivo de exibição de uma planta.
     */
    public static function filePath(string $filename): string
    {
        // basename() barra traversal: o nome vem do banco, mas defesa em
        // profundidade não custa nada.
        return self::dir() . '/' . basename($filename);
    }

    /**
     * Busca uma planta por id respeitando a restrição de entidade da
     * sessão. Devolve a linha ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function getById(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_floorplans',
            'WHERE' => [
                'glpi_plugin_shopmap_floorplans.id' => $id,
            ] + getEntitiesRestrictCriteria('glpi_plugin_shopmap_floorplans'),
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Cria a planta a partir de um upload já validado pelo chamador
     * (extensão em ALLOWED_EXT). Insere a linha, move o arquivo para o
     * nome definitivo `plan_<id>.<ext>` e grava nome + dimensões.
     *
     * @param string $name     Nome da planta (já limpo)
     * @param string $tmpFile  Caminho do arquivo temporário do upload
     * @param string $ext      Extensão minúscula validada
     * @return int             id criado, ou 0 em falha
     */
    public static function createFromUpload(string $name, string $tmpFile, string $ext): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = date('Y-m-d H:i:s');
        $ok  = $DB->insert('glpi_plugin_shopmap_floorplans', [
            'name'              => $name,
            'entities_id'       => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'is_recursive'      => 0,
            'conversion_status' => 'done', // svg/png/jpg dispensam conversão
            'date_creation'     => $now,
            'date_mod'          => $now,
        ]);
        if (!$ok) {
            return 0;
        }
        $id = (int) $DB->insertId();

        $filename = 'plan_' . $id . '.' . $ext;
        $dest     = self::filePath($filename);
        if (!self::moveUploaded($tmpFile, $dest)) {
            $DB->delete('glpi_plugin_shopmap_floorplans', ['id' => $id]);
            return 0;
        }

        [$w, $h] = self::probeDims($dest, $ext);

        $DB->update('glpi_plugin_shopmap_floorplans', [
            'svg_filename' => $filename,
            'svg_width'    => $w,
            'svg_height'   => $h,
        ], ['id' => $id]);

        return $id;
    }

    /**
     * Apaga a planta: linha + arquivo + (futuros) shapes/conexões dela.
     */
    public static function purge(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $plan = self::getById($id);
        if ($plan === null) {
            return false;
        }

        if (!empty($plan['svg_filename'])) {
            $path = self::filePath($plan['svg_filename']);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $DB->delete('glpi_plugin_shopmap_shapes', ['plugin_shopmap_floorplans_id' => $id]);
        $DB->delete('glpi_plugin_shopmap_connections', ['plugin_shopmap_floorplans_id' => $id]);
        $DB->delete('glpi_plugin_shopmap_floorplans', ['id' => $id]);

        return true;
    }

    /**
     * Dimensões do arquivo em px: raster via getimagesize; SVG via
     * atributos width/height ou viewBox. 0x0 quando indeterminável —
     * o JS resolve pelo tamanho natural da imagem.
     *
     * @return array{0:int,1:int}
     */
    public static function probeDims(string $path, string $ext): array
    {
        if ($ext !== 'svg') {
            $info = @getimagesize($path);
            return [(int) ($info[0] ?? 0), (int) ($info[1] ?? 0)];
        }

        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        return self::parseSvgDims($head);
    }

    /**
     * Extrai largura/altura do cabeçalho de um SVG (width/height em px
     * ou viewBox). Separado de probeDims para ser testável sem arquivo.
     *
     * @return array{0:int,1:int}
     */
    public static function parseSvgDims(string $svgHead): array
    {
        if (!preg_match('/<svg\b[^>]*>/is', $svgHead, $m)) {
            return [0, 0];
        }
        $tag = $m[0];

        $w = 0;
        $h = 0;
        // (?<![-\w]) impede casar data-width / stroke-width etc.
        if (preg_match('/(?<![-\w])width\s*=\s*["\']?([0-9.]+)\s*(?:px)?["\']?/i', $tag, $mw)) {
            $w = (int) round((float) $mw[1]);
        }
        if (preg_match('/(?<![-\w])height\s*=\s*["\']?([0-9.]+)\s*(?:px)?["\']?/i', $tag, $mh)) {
            $h = (int) round((float) $mh[1]);
        }
        if (($w === 0 || $h === 0)
            && preg_match('/\bviewBox\s*=\s*["\']\s*[0-9.\-]+[\s,]+[0-9.\-]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']/i', $tag, $mv)
        ) {
            $w = $w ?: (int) round((float) $mv[1]);
            $h = $h ?: (int) round((float) $mv[2]);
        }
        return [$w, $h];
    }

    /**
     * move_uploaded_file com fallback para rename (permite teste fora
     * de um request real de upload).
     */
    private static function moveUploaded(string $tmp, string $dest): bool
    {
        if (@move_uploaded_file($tmp, $dest)) {
            return true;
        }
        return @rename($tmp, $dest);
    }

    /**
     * Content-Type de exibição por extensão (para planfile.php).
     */
    public static function mimeFor(string $filename): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'svg'          => 'image/svg+xml',
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            default        => 'application/octet-stream',
        };
    }
}
