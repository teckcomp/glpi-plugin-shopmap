<?php

namespace GlpiPlugin\Shopmap;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use Session;

/**
 * ShopMap — aba "ShopMap" na tela de Perfil (Administração → Perfis).
 * Fase 4, Bloco 8a.
 *
 * Desenha a matriz de checkboxes com os direitos granulares do plugin
 * (um por módulo), reaproveitando o componente NATIVO do GLPI
 * (Profile::displayRightsChoiceMatrix) — mesmo padrão já validado no
 * ProjectPlus. O salvamento vai pelo FORMULÁRIO NATIVO do Perfil (post
 * para Profile::getFormURL() com name="update"): como os direitos já
 * existem em glpi_profilerights (criados no Install), o core os
 * reconhece e grava sozinho.
 *
 * Não tem tabela própria: os valores moram em glpi_profilerights, um
 * registro por direito/perfil.
 *
 * ESCOPO DO BLOCO 8a: esta aba só EXIBE e GRAVA a matriz. O gate das
 * telas/endpoints por esses direitos é o Bloco 8b — aqui NENHUM
 * comportamento de tela do plugin muda (o código continua checando
 * `plugin_shopmap`).
 */
class Profile extends CommonDBTM
{
    /** Só quem pode editar perfis vê/salva esta aba. */
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('ShopMap', 'shopmap');
    }

    /**
     * Matriz de direitos do plugin — Bloco 8b (decisão 08/08/2026):
     * modelo simplificado "como o DGO+": UMA linha, o direito único
     * `plugin_shopmap`, com as quatro colunas Ler / Atualizar / Criar /
     * Deletar. Sem amarração por módulo — a granularidade de 9 direitos
     * do Bloco 8a foi descartada por engessar demais a operação.
     *
     * Semântica das colunas (vale para plantas, shapes e cabos):
     *  - Ler:       abrir painel e plantas, ver tudo, buscar (inclusive
     *               dentro do rack), rota BFS, recorte+PNG, PDF, histórico
     *  - Atualizar: mover/editar shape, vincular ativo, editar cabo e
     *               traçado, legenda, registrar em NetworkPort (este
     *               continua exigindo também `networking` UPDATE do core)
     *  - Criar:     criar shape, desenhar cabo, subir planta
     *  - Deletar:   excluir shape, cabo, planta
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'label'  => __('ShopMap', 'shopmap'),
                'field'  => 'plugin_shopmap',
                'rights' => [
                    READ   => __('Ler', 'shopmap'),
                    UPDATE => __('Atualizar', 'shopmap'),
                    CREATE => __('Criar', 'shopmap'),
                    DELETE => __('Deletar', 'shopmap'),
                ],
            ],
        ];
    }

    /**
     * Valor MÁXIMO de cada direito, derivado da própria matriz.
     *
     * É o OR dos bits que a linha oferece: um módulo com
     * Ver/Interagir/Criar/Excluir vale 15; um liga/desliga vale 1 (READ)
     * ou 2 (UPDATE). Derivar de `getAllRights()` evita ter uma segunda
     * lista para sair de sincronia com a matriz.
     *
     * @return array<string,int> nome do direito => bits máximos
     */
    public static function getMaxRights(): array
    {
        $max = [];
        foreach (self::getAllRights() as $row) {
            $bits = 0;
            foreach (array_keys($row['rights']) as $bit) {
                $bits |= (int) $bit;
            }
            $max[(string) $row['field']] = $bits;
        }
        return $max;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int) $item->getID() > 0) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile) {
            $self = new self();
            $self->showRightsMatrix((int) $item->getID());
        }
        return true;
    }

    /**
     * Renderiza a matriz de direitos do plugin para um perfil, dentro do
     * formulário nativo do Perfil (o próprio core grava ao salvar).
     *
     * NÃO chamar de "showForm": CommonDBTM já declara
     * showForm($ID, array $options = []) e uma assinatura diferente gera
     * Compile Error ao carregar a classe (lição do ProjectPlus).
     */
    public function showRightsMatrix(int $profiles_id): void
    {
        $profile = new GlpiProfile();
        $profile->getFromDB($profiles_id);

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('ShopMap — acessos por módulo', 'shopmap'),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
