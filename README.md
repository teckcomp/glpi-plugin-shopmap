# ShopMap — plugin GLPI 11

Gestão visual de infraestrutura de TI sobre planta baixa (shoppings e
grandes edifícios). Referência de produto: um "OZmap indoor" dentro do GLPI.

- Planta baixa por piso (DWG/PDF → SVG, zoom sem perda de qualidade)
- Shapes tipados (equipamento, rack, caixa de passagem, área) com vínculo
  opcional a ativos do GLPI — trocar o ativo não desfaz o desenho
- Traçado do cabo (fibra/UTP) entre shapes, com atributos (tipo,
  comprimento, nº de fibras, etiqueta)
- Clique no ativo → ligações + rota destacada até o rack (BFS)
- Recorte de área → exportação PNG/PDF com legenda
- Perfis Admin/NOC/Técnico via direitos + entidades do GLPI

**Requisitos:** GLPI 11.0.x, PHP >= 8.2.

Instalação: extrair em `plugins/shopmap`, depois
`php bin/console plugin:install shopmap && php bin/console plugin:activate shopmap`.
