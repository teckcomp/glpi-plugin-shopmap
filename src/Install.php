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
        'glpi_plugin_shopmap_exports',
    ];

    /**
     * Direitos do plugin — Bloco 8b (decisão 08/08/2026, modelo DGO+):
     * volta a existir UM direito só, `plugin_shopmap`, com os quatro
     * bits padrão (Ler/Atualizar/Criar/Deletar), configurado na aba
     * "ShopMap" do Perfil (src/Profile.php). A granularidade de 9
     * direitos do Bloco 8a foi descartada por engessar a operação.
     */
    public const RIGHTS = [
        'plugin_shopmap',
    ];

    /**
     * Direitos APOSENTADOS — os 8 granulares criados pelo Bloco 8a.
     * A atualização absorve o que houver neles de volta no direito
     * único (só eleva) e depois APAGA as linhas de glpi_profilerights
     * (ver absorbRetiredRights()). Manter a lista permite reexecutar o
     * install sem efeito colateral: sem linhas, nada a absorver.
     *
     * @var array<int,string>
     */
    public const RETIRED_RIGHTS = [
        'plugin_shopmap_floorplans',
        'plugin_shopmap_shapes',
        'plugin_shopmap_connections',
        'plugin_shopmap_route',
        'plugin_shopmap_export_png',
        'plugin_shopmap_export_pdf',
        'plugin_shopmap_exportlog',
        'plugin_shopmap_netport',
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
     *  - access_point: Access Point (Bloco 6) — ícone fixo, sem depender
     *               do itemtype vinculado (NetworkEquipment é genérico
     *               demais pra distinguir AP de roteador/switch)
     *  - onu_router: ONU / Roteador (Bloco 6) — idem, ícone fixo
     *  - area:      área de loja/setor (polígono, sem ativo)
     */
    public const SHAPE_TYPES = ['equipment', 'rack', 'passbox', 'vago', 'access_point', 'onu_router', 'area'];

    /**
     * Paleta fixa de 6 cores (decisões 06/08/2026: +preto; amarelo→dourado) — chips e
     * cabos escolhem UMA delas; desenho interno sempre branco.
     * Verde também é o estado "cru" do cabo (sem cor definida).
     */
    public const PALETTE = ['#008000', '#D20A2E', '#DAA520', '#0000FF', '#898989', '#000000'];

    /** Vago é SEMPRE laranja escuro (fora da paleta, fixo). */
    public const VAGO_COLOR = '#FF7518';

    /** Cor padrão de shape (existentes migram para ela). */
    public const DEFAULT_SHAPE_COLOR = '#D20A2E';

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
        // Direitos do plugin — Bloco 8b (modelo DGO+): direito único.
        //
        // ATENÇÃO (lição 25): Migration::addRight só INSERE a linha para
        // os perfis que ainda NÃO a têm — com o valor pedido para quem
        // atende ao pré-requisito e 0 para os demais — e nunca eleva
        // linha existente. É seguro reexecutar (plugin:install --force)
        // sem desfazer configuração feita à mão na aba do Perfil.
        // ------------------------------------------------------------------
        $migration->addRight('plugin_shopmap', READ | UPDATE | CREATE | DELETE, ['config' => UPDATE]);

        // Atualização Bloco 8a -> 8b: absorve os bits que estiverem nos
        // 8 direitos granulares aposentados de volta no direito único
        // (só eleva, nunca rebaixa) e apaga as linhas aposentadas.
        self::absorbRetiredRights();

        // Reconciliação do administrador (lição 25): perfis com `config`
        // UPDATE ficam com o direito no máximo da matriz. Só eleva.
        self::ensureAdminRights();

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

        // ------------------------------------------------------------------
        // Migração Bloco 4i — ALTER consolidado nº 2 (decisões 06/08/2026):
        //  - shapes.color: cor do chip (paleta de 5; vago ignora — fixo);
        //  - connections.color: cor do cabo ('' = cru → verde no front);
        //  - connections.power_ref_dbm / power_now_dbm / power_now_date:
        //    potência da fibra — inicial editável, atual preenchida pelo
        //    monitoramento (Fase 5 / scanner via API) — Bloco 4j;
        //  - floorplans.legend: JSON {cor: texto} da legenda por planta.
        // ------------------------------------------------------------------
        $migration->addField('glpi_plugin_shopmap_shapes', 'color', "VARCHAR(7) NOT NULL DEFAULT '" . self::DEFAULT_SHAPE_COLOR . "'", [
            'after'   => 'label',
            'comment' => 'cor do chip (paleta fixa)',
        ]);
        $migration->migrationOneTable('glpi_plugin_shopmap_shapes');

        $connColorAdded = $migration->addField($connTable, 'color', "VARCHAR(7) NOT NULL DEFAULT ''", [
            'after'   => 'cable_type',
            'comment' => 'cor do cabo; vazio = sem definicao (verde)',
        ]);
        $migration->addField($connTable, 'power_ref_dbm', "DECIMAL(6,2) DEFAULT NULL", [
            'after'   => 'strand_count',
            'comment' => 'potencia inicial da fibra (dBm, as built)',
        ]);
        $migration->addField($connTable, 'power_now_dbm', "DECIMAL(6,2) DEFAULT NULL", [
            'after' => 'power_ref_dbm',
            'comment' => 'potencia atual (dBm) - preenchida pelo monitoramento',
        ]);
        $migration->addField($connTable, 'power_now_date', "TIMESTAMP NULL DEFAULT NULL", [
            'after' => 'power_now_dbm',
        ]);
        $migration->migrationOneTable($connTable);

        $migration->addField('glpi_plugin_shopmap_floorplans', 'legend', "TEXT DEFAULT NULL", [
            'comment' => 'legenda por planta: JSON {"#RRGGBB":"texto"}',
        ]);
        $migration->migrationOneTable('glpi_plugin_shopmap_floorplans');

        // ------------------------------------------------------------------
        // Migração Bloco 7b — histórico de exportações (log simples).
        //
        // Decisões do usuário (06/08/2026): só a exportação PDF registra
        // (PNG é 100% local no navegador); exibição no dashboard, todas
        // as plantas juntas; SEM re-download — o PDF não fica no servidor,
        // aqui é apenas o registro (quem, quando, arquivo). Por isso não
        // há coluna de caminho, só o nome do arquivo entregue.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_shopmap_exports')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_shopmap_exports` (
                    `id`                            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_shopmap_floorplans_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`                      INT {$sign} NOT NULL DEFAULT 0,
                    `filename`                      VARCHAR(255) NOT NULL DEFAULT '',
                    `date_creation`                 TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `floorplan` (`plugin_shopmap_floorplans_id`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }


        // Uma única vez (na criação da coluna): cabos EXISTENTES herdam a
        // cor mais próxima da paleta pelo tipo, para nada mudar de cara
        // (decisão 06/08). Novos cabos nascem '' (verde cru).
        if ($connColorAdded) {
            $DB->update($connTable, ['color' => '#DAA520'], ['cable_type' => 'fiber_sm']);
            $DB->update($connTable, ['color' => '#DAA520'], ['cable_type' => 'fiber_mm']);
            $DB->update($connTable, ['color' => '#0000FF'], ['cable_type' => 'utp']);
            $DB->update($connTable, ['color' => '#898989'], ['cable_type' => 'other']);
        }

        $migration->executeMigration();

        return true;
    }

    /**
     * Valor final do direito único após absorver os granulares
     * aposentados. Função PURA — é o que o harness testa.
     *
     * O OR de tudo, limitado aos 4 bits da matriz (READ|UPDATE|CREATE|
     * DELETE): qualquer "Ver" granular vira Ler, qualquer "Interagir"
     * vira Atualizar, e assim por diante. Só ELEVA — bit já ligado no
     * direito único permanece.
     *
     * @param int                   $current       valor atual de plugin_shopmap
     * @param array<int|string,int> $retiredValues valores dos direitos aposentados
     */
    public static function retiredMergeValue(int $current, array $retiredValues): int
    {
        $merged = $current;
        foreach ($retiredValues as $bits) {
            $merged |= (int) $bits;
        }
        return $merged & (READ | UPDATE | CREATE | DELETE);
    }

    /**
     * Atualização Bloco 8a → 8b: para cada perfil, funde os bits dos 8
     * direitos granulares no direito único `plugin_shopmap` (só eleva —
     * ninguém perde acesso na simplificação) e depois APAGA as linhas
     * aposentadas de glpi_profilerights.
     *
     * Idempotente: numa segunda execução as linhas aposentadas já não
     * existem, então não há nada a absorver nem a apagar.
     *
     * `ProfileRight::updateProfileRights()` em vez de UPDATE direto
     * (lição 25): dispara `post_updateItem` → atualiza
     * `glpi_profiles.last_rights_update` → sessão aberta recarrega os
     * direitos sem logout.
     */
    public static function absorbRetiredRights(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_profilerights')) {
            return;
        }

        // Perfis que têm alguma linha aposentada
        $retired = [];
        $it = $DB->request([
            'SELECT' => ['profiles_id', 'name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => self::RETIRED_RIGHTS],
        ]);
        foreach ($it as $row) {
            $retired[(int) $row['profiles_id']][(string) $row['name']] = (int) $row['rights'];
        }

        foreach ($retired as $profileId => $values) {
            $current = \ProfileRight::getProfileRights($profileId, ['plugin_shopmap']);
            $cur     = (int) ($current['plugin_shopmap'] ?? 0);
            $merged  = self::retiredMergeValue($cur, $values);
            if ($merged !== $cur) {
                \ProfileRight::updateProfileRights($profileId, ['plugin_shopmap' => $merged]);
            }
        }

        // Apaga as linhas aposentadas (todos os perfis de uma vez)
        \ProfileRight::deleteProfileRights(self::RETIRED_RIGHTS);
    }

    /**
     * Perfis "administradores" — os que têm o direito NATIVO `config`
     * com o bit UPDATE. Mesmo critério já usado pelo Bloco 1 para
     * conceder o direito do plugin, então não inventa conceito novo.
     *
     * @return array<int,int> ids de perfil
     */
    public static function getAdminProfileIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        if (!$DB->tableExists('glpi_profilerights')) {
            return $ids;
        }

        // O teste de bit é feito em PHP de propósito: "rights & 2 = 2" em
        // QueryExpression funciona, mas some do log e é o tipo de coisa
        // que quebra em silêncio numa troca de versão.
        $it = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config'],
        ]);
        foreach ($it as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                $ids[] = (int) $row['profiles_id'];
            }
        }

        return $ids;
    }

    /**
     * Garante que todo perfil administrador tenha a matriz do ShopMap no
     * nível máximo.
     *
     * REGRA DE OURO — só ELEVA, nunca rebaixa. O valor gravado é
     * `atual | máximo`, então bit já ligado continua ligado, bit fora da
     * matriz é preservado, e reexecutar o install não muda mais nada.
     *
     * `ProfileRight::updateProfileRights()` é usado em vez de UPDATE
     * direto porque dispara `post_updateItem`, que atualiza
     * `glpi_profiles.last_rights_update` — é o que faz a sessão aberta
     * recarregar os direitos SEM exigir logout.
     */
    public static function ensureAdminRights(): void
    {
        $max = Profile::getMaxRights();

        foreach (self::getAdminProfileIds() as $profileId) {
            $current = \ProfileRight::getProfileRights($profileId, array_keys($max));

            $update = [];
            foreach ($max as $name => $bits) {
                $cur = (int) ($current[$name] ?? 0);
                if (($cur | $bits) !== $cur) {
                    $update[$name] = $cur | $bits;
                }
            }
            if ($update !== []) {
                \ProfileRight::updateProfileRights($profileId, $update);
            }
        }
    }

    /**
     * Desinstalação: PRESERVA tabelas, dados e direitos.
     *
     * Direitos NÃO são apagados de propósito (lição do ProjectPlus:
     * desinstalar apagando as linhas de glpi_profilerights faz o
     * administrador perder a configuração em silêncio no próximo
     * install, porque addRight não sobrescreve valor existente).
     * (Purga opcional virá com a tela de configuração, fase futura.)
     */
    public static function uninstall(): bool
    {
        return true;
    }
}
