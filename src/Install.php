<?php

namespace GlpiPlugin\Shopmap;

use Migration;

/**
 * Instalação / desinstalação do ShopMap.
 *
 * Cria APENAS tabelas próprias do plugin (prefixo glpi_plugin_shopmap_).
 * Nenhuma tabela nativa é alterada. As conexões lógicas de rede continuam
 * tendo o core como fonte de verdade (NetworkPort/NetworkPort_NetworkPort);
 * as tabelas daqui guardam o que o core NÃO tem: geometria sobre a planta.
 *
 * Decisões de schema (Bloco 1, revisão com benchmark OZmap):
 *
 *  1. SHAPE DESACOPLADO DO ATIVO — o shape é o elemento desenhado na
 *     planta; o ativo do GLPI (itemtype/items_id) é um VÍNCULO opcional.
 *     Substituir o equipamento = trocar o vínculo; desenho e conexões
 *     ficam intactos (requisito "trocar ativo sem recriar o caminho").
 *
 *  2. SHAPE TIPADO — nem tudo na planta é ativo: caixa de passagem,
 *     rack, área de loja/setor são elementos do desenho que podem ou
 *     não ter ativo vinculado (padrão OZmap: caixas, postes, cabos).
 *
 *  3. CONEXÃO = CABO COM ATRIBUTOS — não é só uma linha: tipo do cabo,
 *     comprimento estimado, nº de fibras/pares, etiqueta. Todos
 *     opcionais, para não burocratizar o desenho.
 *
 *  4. DESTINO DA ROTA POR PLANTA — `is_route_target` marca o shape
 *     (rack/DC) onde o BFS da Fase 3 termina. Decisão: marcação manual,
 *     não amarrada aos itemtypes Rack/DCRoom do core.
 *
 * install() é idempotente (guardas tableExists) e reexecutável com
 * `plugin:install --force`. uninstall() PRESERVA tabelas e direitos —
 * purga só será oferecida via configuração em fase futura.
 */
class Install
{
    /**
     * Tabelas próprias do plugin. Ordem estável (diagnóstico futuro).
     */
    public const TABLES = [
        'glpi_plugin_shopmap_floorplans',
        'glpi_plugin_shopmap_shapes',
        'glpi_plugin_shopmap_connections',
    ];

    /**
     * Direitos próprios do plugin. Um único direito no Bloco 1; a
     * separação Admin/NOC/Técnico (Fase 4) criará direitos granulares
     * SEM reaproveitar direito do core já concedido a todos (lição do
     * ProjectPlus: direito reusado não separa papéis).
     */
    public const RIGHTS = [
        'plugin_shopmap',
    ];

    /**
     * Tipos de shape aceitos em `shapetype`. Fonte única para validação
     * de formulário/AJAX nos próximos blocos.
     *
     *  - equipment: equipamento de TI (normalmente com ativo vinculado)
     *  - rack:      rack/armário (candidato natural a is_route_target)
     *  - passbox:   caixa de passagem/emenda (a fibra passa por dentro)
     *  - vago:      ponto de espera (Bloco 4f, decisão do usuário):
     *               fibra lançada aguardando equipamento — sem ativo,
     *               cabos com ponta aqui desenham tracejado
     *  - area:      área de loja/setor (polígono, sem ativo)
     */
    public const SHAPE_TYPES = ['equipment', 'rack', 'passbox', 'vago', 'area'];

    public static function install(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $migration = new Migration(PLUGIN_SHOPMAP_VERSION);

        // Resolvedores do core (lição do ProjectPlus/auditoria 26/07/2026):
        // base atualizada de GLPI antigo pode rodar sem utf8mb4; fixar
        // charset produz erro 1267 em JOIN com tabela do core.
        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        $sign      = \DBConnection::getDefaultPrimaryKeySignOption();

        // ------------------------------------------------------------------
        // 1) Plantas baixas (uma por piso; entidade = shopping)
        //
        //    O arquivo original (DWG/PDF/imagem) fica em glpi_documents
        //    (documents_id). O SVG convertido fica em files/_plugins/shopmap/
        //    (svg_filename), gerado pelo job de conversão do Bloco 2.
        //    `conversion_status`: none | pending | processing | done | error.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_shopmap_floorplans')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_shopmap_floorplans` (
                    `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
                    `name`              VARCHAR(255) NOT NULL DEFAULT '',
                    `entities_id`       INT {$sign} NOT NULL DEFAULT 0,
                    `is_recursive`      TINYINT NOT NULL DEFAULT 0,
                    `comment`           TEXT,
                    `documents_id`      INT {$sign} NOT NULL DEFAULT 0 COMMENT 'arquivo original em glpi_documents',
                    `svg_filename`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SVG convertido em files/_plugins/shopmap/',
                    `conversion_status` VARCHAR(20) NOT NULL DEFAULT 'none',
                    `conversion_error`  TEXT,
                    `svg_width`         INT NOT NULL DEFAULT 0,
                    `svg_height`        INT NOT NULL DEFAULT 0,
                    `date_creation`     TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`          TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `conversion_status` (`conversion_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 2) Shapes — elementos desenhados sobre a planta
        //
        //    `geometry` é JSON: {"kind":"point","x":..,"y":..} para
        //    equipamento/caixa, {"kind":"polygon","points":[[x,y],...]}
        //    para área. Coordenadas no CRS simples do SVG (px), não geo.
        //
        //    itemtype/items_id = vínculo OPCIONAL com ativo do GLPI
        //    (NetworkEquipment, Computer, Printer...). items_id 0 = shape
        //    sem ativo (caixa de passagem, área).
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_shopmap_shapes')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_shopmap_shapes` (
                    `id`                            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_shopmap_floorplans_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `shapetype`                     VARCHAR(30) NOT NULL DEFAULT 'equipment',
                    `itemtype`                      VARCHAR(100) NOT NULL DEFAULT '',
                    `items_id`                      INT {$sign} NOT NULL DEFAULT 0,
                    `label`                         VARCHAR(255) NOT NULL DEFAULT '',
                    `geometry`                      LONGTEXT COMMENT 'JSON: ponto ou poligono, px do SVG',
                    `is_route_target`               TINYINT NOT NULL DEFAULT 0 COMMENT 'destino da rota (rack/DC) nesta planta',
                    `comment`                       TEXT,
                    `date_creation`                 TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`                      TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `floorplan` (`plugin_shopmap_floorplans_id`),
                    KEY `item` (`itemtype`, `items_id`),
                    KEY `route_target` (`plugin_shopmap_floorplans_id`, `is_route_target`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 3) Conexões — o traçado do cabo entre dois shapes
        //
        //    `path` é JSON: {"points":[[x,y],[x,y],...]} — a polilinha
        //    desenhada (o caminho físico da fibra pela planta).
        //
        //    networkports_id_a/b = vínculo OPCIONAL com as portas do core
        //    (preenchido pela Fase 2 quando os dois shapes têm ativo).
        //    A ligação lógica em NetworkPort_NetworkPort continua sendo
        //    do core; aqui fica só a referência + geometria.
        //
        //    Atributos de cabo (benchmark OZmap): todos opcionais.
        //    `cable_type`: fiber_sm | fiber_mm | utp | outro (texto livre
        //    controlado por formulário nos próximos blocos).
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_shopmap_connections')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_shopmap_connections` (
                    `id`                            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_shopmap_floorplans_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `shapes_id_a`                   INT {$sign} NOT NULL DEFAULT 0,
                    `shapes_id_b`                   INT {$sign} NOT NULL DEFAULT 0,
                    `networkports_id_a`             INT {$sign} NOT NULL DEFAULT 0,
                    `networkports_id_b`             INT {$sign} NOT NULL DEFAULT 0,
                    `path`                          LONGTEXT COMMENT 'JSON: polilinha do cabo, px do SVG',
                    `cable_type`                    VARCHAR(50) NOT NULL DEFAULT '',
                    `cable_label`                   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'etiqueta/identificacao do cabo',
                    `length_m`                      DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'comprimento estimado em metros',
                    `strand_count`                  INT NOT NULL DEFAULT 0 COMMENT 'qtde de fibras/pares',
                    `comment`                       TEXT,
                    `date_creation`                 TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`                      TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `floorplan` (`plugin_shopmap_floorplans_id`),
                    KEY `shape_a` (`shapes_id_a`),
                    KEY `shape_b` (`shapes_id_b`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // Direito próprio do plugin
        //
        // addRight é idempotente e não rebaixa valor existente (seguro em
        // reexecução com --force). Perfis com config UPDATE (Super-Admin)
        // recebem o direito completo de saída; os demais perfis ficam
        // desmarcados — a matriz Admin/NOC/Técnico é a Fase 4.
        // READ|UPDATE|CREATE|DELETE = 15 (sem PURGE).
        // ------------------------------------------------------------------
        $migration->addRight('plugin_shopmap', READ | UPDATE | CREATE | DELETE, ['config' => UPDATE]);

        // ------------------------------------------------------------------
        // Migração Bloco 4e — ALTER consolidado de 4e+4f+4g (decisão
        // 01/08/2026: um dump, uma reinstalação, três blocos de código).
        //
        //  - itemtype_a/items_id_a e itemtype_b/items_id_b (4e): o item
        //    EFETIVO da ponta quando o shape é um contêiner (Rack/
        //    Enclosure) — ex.: o cabo chega no rack, mas conecta no SW
        //    que está dentro dele. Vazio = a ponta é o próprio ativo do
        //    shape (comportamento até o 4d).
        //  - porttype_a/b (4g): origem da porta referenciada por lado
        //    ('' = NetworkPort core; 'dgoplus' = porta do DGO+, apenas
        //    REFERÊNCIA — documentação real de emenda fica no DGO+).
        //
        //  O 4f (shape "vago") não precisa de coluna: é valor novo em
        //  `shapetype` (SHAPE_TYPES), entra com o código do 4f.
        //  addField é idempotente (checa fieldExists) — seguro no --force.
        // ------------------------------------------------------------------
        $connTable = 'glpi_plugin_shopmap_connections';
        $migration->addField($connTable, 'itemtype_a', "VARCHAR(100) NOT NULL DEFAULT ''", [
            'after'   => 'shapes_id_b',
            'comment' => 'item efetivo da ponta A (equipamento dentro do rack)',
        ]);
        $migration->addField($connTable, 'items_id_a', "INT NOT NULL DEFAULT 0", [
            'after' => 'itemtype_a',
        ]);
        $migration->addField($connTable, 'itemtype_b', "VARCHAR(100) NOT NULL DEFAULT ''", [
            'after'   => 'items_id_a',
            'comment' => 'item efetivo da ponta B',
        ]);
        $migration->addField($connTable, 'items_id_b', "INT NOT NULL DEFAULT 0", [
            'after' => 'itemtype_b',
        ]);
        $migration->addField($connTable, 'porttype_a', "VARCHAR(20) NOT NULL DEFAULT ''", [
            'after'   => 'networkports_id_b',
            'comment' => 'origem da porta A: vazio=core, dgoplus=referencia DGO+',
        ]);
        $migration->addField($connTable, 'porttype_b', "VARCHAR(20) NOT NULL DEFAULT ''", [
            'after' => 'porttype_a',
        ]);
        $migration->migrationOneTable($connTable);

        $migration->executeMigration();

        return true;
    }

    /**
     * Desinstalação: PRESERVA tabelas, dados e direitos.
     * (Purga opcional virá com a tela de configuração, fase futura.)
     */
    public static function uninstall(): bool
    {
        return true;
    }
}
