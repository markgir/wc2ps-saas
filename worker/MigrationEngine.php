<?php
/**
 * MigrationEngine.php
 * ====================
 * Adapta o motor do wc2ps para correr no servidor dedicado,
 * usando AgentClient para aceder às BDs remotas do cliente
 * em vez de PDO directo.
 *
 * Arquitectura:
 *  - AgentClient($wcCfg) → lê da BD WooCommerce do cliente
 *  - AgentClient($psCfg) → escreve na BD PrestaShop do cliente
 *  - Database            → guarda estado/logs na BD central
 *
 * O estado de cada job (cursor, mapas de ids, progresso) é guardado
 * na coluna JSON 'state' da tabela jobs — exactamente como o wc2ps
 * guarda em migration_progress/progress_{session}.json
 */
declare(strict_types=1);

require_once __DIR__ . '/AgentClient.php';

class MigrationEngine
{
    private AgentClient $wc;   // lê WooCommerce
    private AgentClient $ps;   // escreve PrestaShop
    private Database    $db;
    private int         $jobId;
    private array       $state;
    private string      $wcPrefix;
    private string      $psPrefix;
    private int         $batchSize;
    private int         $idLang;
    private int         $idShop;

    public function __construct(
        AgentClient $wc,
        AgentClient $ps,
        Database    $db,
        int         $jobId,
        int         $batchSize = 50,
        int         $idLang    = 1,
        int         $idShop    = 1,
        string      $wcPrefix  = 'wp_',
        string      $psPrefix  = 'ps_'
    ) {
        $this->wc        = $wc;
        $this->ps        = $ps;
        $this->db        = $db;
        $this->jobId     = $jobId;
        $this->batchSize = $batchSize;
        $this->idLang    = $idLang;
        $this->idShop    = $idShop;
        $this->wcPrefix  = $wcPrefix;
        $this->psPrefix  = $psPrefix;
        $this->state     = $this->loadState();
    }

    // ── Estado ────────────────────────────────────────────────────────────────

    private function loadState(): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT state FROM jobs WHERE id=? LIMIT 1"
        );
        $stmt->execute([$this->jobId]);
        $row = $stmt->fetch();
        $saved = $row ? @json_decode($row['state'] ?? '{}', true) : [];
        return array_merge($this->defaultState(), $saved ?: []);
    }

    private function saveState(): void
    {
        $this->db->updateJob($this->jobId, [
            'state'           => json_encode($this->state),
            'done_products'   => $this->state['done_products'],
            'total_products'  => $this->state['total_products'],
            'done_categories' => $this->state['done_categories'],
            'total_categories'=> $this->state['total_categories'],
            'done_attrs'      => $this->state['done_attrs'],
            'total_attrs'     => $this->state['total_attrs'],
        ]);
    }

    private function defaultState(): array
    {
        return [
            'step'               => 'categories',
            'total_categories'   => 0,
            'done_categories'    => 0,
            'categories_cursor'  => 0,
            'total_attrs'        => 0,
            'done_attrs'         => 0,
            'attributes_cursor'  => 0,
            'total_products'     => 0,
            'done_products'      => 0,
            'skipped_products'   => 0,
            'last_wc_product_id' => 0,
            'category_id_map'    => [],
            'attr_group_map'     => [],
            'attr_value_map'     => [],
            'product_id_map'     => [],
            'errors'             => [],
        ];
    }

    public function getProgress(): array
    {
        $s = $this->state;
        return [
            'step'              => $s['step'],
            'total_products'    => $s['total_products'],
            'done_products'     => $s['done_products'],
            'total_categories'  => $s['total_categories'],
            'done_categories'   => $s['done_categories'],
            'total_attrs'       => $s['total_attrs'],
            'done_attrs'        => $s['done_attrs'],
            'errors'            => count($s['errors']),
            'pct_products'      => $s['total_products'] > 0
                ? round(($s['done_products'] / $s['total_products']) * 100, 1)
                : 0,
        ];
    }

    // ── Entry point ───────────────────────────────────────────────────────────

    /**
     * Corre um step da migração.
     * Retorna true quando tudo está concluído.
     * Chama-se em loop pelo JobRunner até retornar true.
     */
    public function runStep(): bool
    {
        // Inicialização: contar totais
        if ($this->state['total_products'] === 0) {
            $this->initCounts();
        }

        switch ($this->state['step']) {
            case 'categories':
                if ($this->migrateCategoriesBatch()) {
                    $this->state['step'] = 'attributes';
                    $this->saveState();
                }
                return false;

            case 'attributes':
                if ($this->migrateAttributesBatch()) {
                    $this->state['step'] = 'products';
                    $this->saveState();
                }
                return false;

            case 'products':
                if ($this->migrateProductsBatch()) {
                    $this->state['step'] = 'done';
                    $this->saveState();
                    return true;
                }
                return false;

            case 'done':
                return true;
        }
        return false;
    }

    private function initCounts(): void
    {
        $p = $this->wcPrefix;

        $total = (int)($this->wc->count(
            "{$p}posts",
            "post_type='product' AND post_status='publish'"
        ));
        $cats = (int)($this->wc->count(
            "{$p}term_taxonomy",
            "taxonomy='product_cat'"
        ));
        $attrs = $this->wc->query(
            "SELECT COUNT(*) AS n FROM `{$p}woocommerce_attribute_taxonomies`"
        );

        $this->state['total_products']   = $total;
        $this->state['total_categories'] = $cats;
        $this->state['total_attrs']      = (int)($attrs[0]['n'] ?? 0);
        $this->log('info', 'init', "Totais: {$total} produtos, {$cats} categorias, {$this->state['total_attrs']} grupos atributos");
        $this->saveState();
    }

    // ── Categorias ────────────────────────────────────────────────────────────

    private function migrateCategoriesBatch(): bool
    {
        $p      = $this->wcPrefix;
        $ps     = $this->psPrefix;
        $cursor = $this->state['categories_cursor'];

        // Buscar árvore de categorias via agent
        $cats = $this->wc->query(
            "SELECT t.term_id, t.name, t.slug, tt.parent, tt.count
             FROM `{$p}terms` t
             JOIN `{$p}term_taxonomy` tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_cat' AND t.name != 'Uncategorized'
             ORDER BY tt.parent ASC, t.term_id ASC",
            [], 2000
        );

        $this->state['total_categories'] = count($cats);
        $total  = count($cats);
        $batch  = max(15, $this->batchSize);
        $map    = &$this->state['category_id_map'];

        // Buscar home category id do PS
        $homeRow = $this->ps->query(
            "SELECT id_category FROM `{$ps}category` WHERE is_root_category=0 AND id_parent=1 LIMIT 1"
        );
        $homeCatId = (int)($homeRow[0]['id_category'] ?? 2);

        $processed = 0;
        for ($i = $cursor; $i < $total; $i++) {
            $cat    = $cats[$i];
            $wcId   = (int)$cat['term_id'];
            if (isset($map[(string)$wcId])) { $processed++; continue; }

            $parentId = $cat['parent'] > 0 && isset($map[(string)$cat['parent']])
                ? $map[(string)$cat['parent']]
                : $homeCatId;

            try {
                $psId = $this->insertCategory($cat, $parentId);
                $map[(string)$wcId] = $psId;
                $this->state['done_categories']++;
                $this->log('success', 'categories', "Cat '{$cat['name']}' → PS#{$psId}");
            } catch (Throwable $e) {
                $this->log('warning', 'categories', "Cat '{$cat['name']}' falhou: " . $e->getMessage());
            }

            $processed++;
            if ($processed >= $batch) {
                $this->state['categories_cursor'] = $i + 1;
                $this->saveState();
                return false;
            }
        }

        $this->state['categories_cursor'] = $total;
        $this->log('info', 'categories', "Categorias concluídas: {$this->state['done_categories']}/{$total}");
        $this->saveState();
        return true;
    }

    private function insertCategory(array $cat, int $parentId): int
    {
        $ps   = $this->psPrefix;
        $name = mb_substr($cat['name'], 0, 128);
        $slug = mb_substr($cat['slug'] ?: $this->slugify($cat['name']), 0, 128);

        // Verificar se já existe
        $existing = $this->ps->query(
            "SELECT c.id_category FROM `{$ps}category` c
             JOIN `{$ps}category_lang` cl ON c.id_category=cl.id_category
             WHERE cl.name=? AND cl.id_lang=? LIMIT 1",
            [$name, $this->idLang]
        );
        if ($existing) return (int)$existing[0]['id_category'];

        // Calcular nleft/nright (nested set) — simplificado: append
        $maxRight = $this->ps->query(
            "SELECT COALESCE(MAX(nleft),1) AS m FROM `{$ps}category`"
        );
        $nleft  = (int)($maxRight[0]['m'] ?? 1) + 1;
        $nright = $nleft + 1;
        $level  = 2;

        // INSERT ps_category
        $this->ps->query(
            "INSERT INTO `{$ps}category`
                (id_parent, id_shop_default, level_depth, nleft, nright, active, date_add, date_upd)
             VALUES (?, 1, ?, ?, ?, 1, NOW(), NOW())",
            [$parentId, $level, $nleft, $nright]
        );
        $idRow = $this->ps->query("SELECT LAST_INSERT_ID() AS id");
        $id    = (int)($idRow[0]['id'] ?? 0);
        if (!$id) throw new RuntimeException("Category INSERT failed");

        // INSERT ps_category_lang
        $this->ps->query(
            "INSERT INTO `{$ps}category_lang` (id_category, id_lang, id_shop, name, link_rewrite, description, meta_title)
             VALUES (?, ?, ?, ?, ?, '', '')",
            [$id, $this->idLang, $this->idShop, $name, $slug]
        );

        // INSERT ps_category_shop
        $this->ps->query(
            "INSERT IGNORE INTO `{$ps}category_shop` (id_category, id_shop) VALUES (?, ?)",
            [$id, $this->idShop]
        );

        return $id;
    }

    // ── Atributos ─────────────────────────────────────────────────────────────

    private function migrateAttributesBatch(): bool
    {
        $p      = $this->wcPrefix;
        $cursor = $this->state['attributes_cursor'];

        $taxonomies = $this->wc->query(
            "SELECT attribute_id, attribute_name, attribute_label, attribute_type
             FROM `{$p}woocommerce_attribute_taxonomies`
             ORDER BY attribute_id ASC",
            [], 500
        );

        $total = count($taxonomies);
        $this->state['total_attrs'] = $total;

        if ($cursor >= $total) {
            $this->log('info', 'attributes', "Atributos concluídos: {$this->state['done_attrs']}/{$total}");
            return true;
        }

        $attr      = $taxonomies[$cursor];
        $taxonomy  = 'pa_' . $attr['attribute_name'];
        $attrLabel = $attr['attribute_label'];
        $attrName  = $attr['attribute_name'];

        // Criar grupo de atributos no PS
        if (!isset($this->state['attr_group_map'][$attrName])) {
            $psGroupId = $this->insertAttributeGroup($attrLabel);
            $this->state['attr_group_map'][$attrName] = $psGroupId;
            $this->log('success', 'attributes', "AttrGroup '{$attrLabel}' → PS#{$psGroupId}");
        }
        $psGroupId = $this->state['attr_group_map'][$attrName];

        // Buscar termos desta taxonomia
        $terms = $this->wc->query(
            "SELECT t.term_id, t.name, t.slug
             FROM `{$p}terms` t
             JOIN `{$p}term_taxonomy` tt ON t.term_id=tt.term_id
             WHERE tt.taxonomy=?
             ORDER BY t.term_id ASC",
            [$taxonomy], 2000
        );

        foreach ($terms as $term) {
            try {
                $psAttrId  = $this->insertAttribute($term['name'], $psGroupId);
                $slug      = strtolower(trim($term['slug']));
                $attrSlug  = strtolower(trim($attrName));
                $lookupKey = $attrSlug . ':' . $slug;
                $this->state['attr_value_map'][$lookupKey] = $psAttrId;

                // Alias sem sufixo numérico (-2, -3)
                $slugBase = preg_replace('/-\d+$/', '', $slug);
                if ($slugBase !== $slug) {
                    $baseKey = $attrSlug . ':' . $slugBase;
                    if (!isset($this->state['attr_value_map'][$baseKey])) {
                        $this->state['attr_value_map'][$baseKey] = $psAttrId;
                    }
                }
            } catch (Throwable $e) {
                $this->log('warning', 'attributes', "AttrVal '{$term['name']}': " . $e->getMessage());
            }
        }

        $this->state['done_attrs']++;
        $this->state['attributes_cursor'] = $cursor + 1;
        $this->saveState();

        if ($cursor + 1 >= $total) {
            $this->log('info', 'attributes', "Atributos concluídos: {$this->state['done_attrs']}/{$total}");
            return true;
        }
        return false;
    }

    private function insertAttributeGroup(string $name): int
    {
        $ps  = $this->psPrefix;
        // Verificar existência
        $existing = $this->ps->query(
            "SELECT ag.id_attribute_group FROM `{$ps}attribute_group` ag
             JOIN `{$ps}attribute_group_lang` agl ON ag.id_attribute_group=agl.id_attribute_group
             WHERE agl.name=? AND agl.id_lang=? LIMIT 1",
            [$name, $this->idLang]
        );
        if ($existing) return (int)$existing[0]['id_attribute_group'];

        $this->ps->query(
            "INSERT INTO `{$ps}attribute_group` (is_color_group, group_type, position) VALUES (0, 'select', 0)"
        );
        $row = $this->ps->query("SELECT LAST_INSERT_ID() AS id");
        $id  = (int)($row[0]['id'] ?? 0);

        $this->ps->query(
            "INSERT INTO `{$ps}attribute_group_lang` (id_attribute_group, id_lang, name, public_name) VALUES (?,?,?,?)",
            [$id, $this->idLang, $name, $name]
        );
        return $id;
    }

    private function insertAttribute(string $name, int $groupId): int
    {
        $ps   = $this->psPrefix;
        $slug = strtolower(trim($name));

        $existing = $this->ps->query(
            "SELECT a.id_attribute FROM `{$ps}attribute` a
             JOIN `{$ps}attribute_lang` al ON a.id_attribute=al.id_attribute
             WHERE a.id_attribute_group=? AND LOWER(al.name)=? AND al.id_lang=? LIMIT 1",
            [$groupId, $slug, $this->idLang]
        );
        if ($existing) return (int)$existing[0]['id_attribute'];

        $this->ps->query(
            "INSERT INTO `{$ps}attribute` (id_attribute_group, color, position) VALUES (?, '', 0)",
            [$groupId]
        );
        $row = $this->ps->query("SELECT LAST_INSERT_ID() AS id");
        $id  = (int)($row[0]['id'] ?? 0);

        $this->ps->query(
            "INSERT INTO `{$ps}attribute_lang` (id_attribute, id_lang, name) VALUES (?,?,?)",
            [$id, $this->idLang, $name]
        );
        return $id;
    }

    // ── Produtos ──────────────────────────────────────────────────────────────

    private function migrateProductsBatch(): bool
    {
        $p      = $this->wcPrefix;
        $cursor = $this->state['last_wc_product_id'];
        $batch  = $this->batchSize;

        // Buscar batch de produtos via agent
        $products = $this->wc->query(
            "SELECT p.ID, p.post_title, p.post_status, p.post_content, p.post_excerpt
             FROM `{$p}posts` p
             WHERE p.post_type='product' AND p.post_status='publish' AND p.ID > ?
             ORDER BY p.ID ASC LIMIT ?",
            [$cursor, $batch]
        );

        if (empty($products)) {
            $this->log('success', 'products', "Produtos concluídos: {$this->state['done_products']}/{$this->state['total_products']}");
            return true;
        }

        $ids = array_column($products, 'ID');
        $meta = $this->getProductsMeta($ids);

        foreach ($products as $product) {
            $wcId  = (int)$product['ID'];
            $this->state['last_wc_product_id'] = $wcId;
            $productMeta = $meta[$wcId] ?? [];

            try {
                $psId = $this->insertProduct($product, $productMeta);
                $this->state['product_id_map'][(string)$wcId] = $psId;
                $this->state['done_products']++;

                // Actualizar job
                $this->db->updateJob($this->jobId, [
                    'done_products' => $this->state['done_products'],
                ]);

                $this->log('success', 'products', "'{$product['post_title']}' WC#{$wcId} → PS#{$psId}");
            } catch (Throwable $e) {
                $this->state['skipped_products']++;
                $this->log('error', 'products', "WC#{$wcId}: " . $e->getMessage());
            }
        }

        $this->saveState();
        return false;
    }

    private function getProductsMeta(array $ids): array
    {
        if (empty($ids)) return [];
        $p = $this->wcPrefix;
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $rows = $this->wc->query(
            "SELECT post_id, meta_key, meta_value
             FROM `{$p}postmeta`
             WHERE post_id IN ({$ph})
               AND meta_key IN ('_regular_price','_sale_price','_price','_sku',
                                '_weight','_width','_height','_length',
                                '_stock','_stock_quantity','_stock_status',
                                '_manage_stock','_product_type','_product_attributes',
                                '_thumbnail_id','_product_image_gallery')",
            $ids, count($ids) * 20
        );

        $meta = [];
        foreach ($rows as $r) {
            $meta[(int)$r['post_id']][$r['meta_key']] = $r['meta_value'];
        }
        return $meta;
    }

    private function insertProduct(array $product, array $meta): int
    {
        $ps    = $this->psPrefix;
        $wcId  = (int)$product['ID'];
        $title = mb_substr($product['post_title'], 0, 128);
        $slug  = $this->slugify($title);
        $desc  = $product['post_content'] ?? '';
        $brief = $product['post_excerpt'] ?? '';
        $ref   = mb_substr($meta['_sku'] ?? '', 0, 64);

        // Preço com fallback
        $price = (float)($meta['_regular_price'] ?? 0);
        if ($price <= 0) $price = (float)($meta['_sale_price'] ?? 0);
        if ($price <= 0) $price = (float)($meta['_price'] ?? 0);
        $price = max(0.0, $price);

        $weight = max(0.0, (float)($meta['_weight'] ?? 0));

        // Categoria padrão
        $catId = $this->getProductCategory($wcId);

        // INSERT ps_product
        $this->ps->query(
            "INSERT INTO `{$ps}product`
                (id_supplier, id_manufacturer, id_category_default, id_shop_default,
                 on_sale, online_only, reference, width, height, depth, weight,
                 quantity, price, wholesale_price, unity, unit_price_ratio,
                 additional_shipping_cost, customizable, text_fields, uploadable_files,
                 active, redirect_type, id_type_redirected, available_for_order,
                 available_date, show_condition, condition, show_price, indexed,
                 visibility, cache_is_pack, cache_has_attachments, is_virtual, date_add, date_upd)
             VALUES (0,0,?,1, 0,0,?,0,0,0,?, 0,?,0,'',0,0,0,0,0,1,'404',0,1,'0000-00-00',0,'new',1,0,'both',0,0,0,NOW(),NOW())",
            [$catId, $ref, $weight, $price]
        );

        $psRow = $this->ps->query("SELECT LAST_INSERT_ID() AS id");
        $psId  = (int)($psRow[0]['id'] ?? 0);
        if (!$psId) throw new RuntimeException("Product INSERT failed for WC#{$wcId}");

        // INSERT ps_product_lang
        $this->ps->query(
            "INSERT INTO `{$ps}product_lang`
                (id_product, id_shop, id_lang, description, description_short, link_rewrite, meta_description, meta_keywords, meta_title, name, available_now, available_later)
             VALUES (?,?,?,?,?,?,?,?,?,?,'','')",
            [$psId, $this->idShop, $this->idLang, $desc, $brief, $slug, '', '', $title, $title]
        );

        // INSERT ps_product_shop
        $this->ps->query(
            "INSERT IGNORE INTO `{$ps}product_shop`
                (id_product, id_shop, id_category_default, on_sale, online_only, active, indexed, available_for_order, show_price, visibility, cache_default_attribute, advanced_stock_management, date_add, date_upd)
             VALUES (?,?,?,0,0,1,0,1,1,'both',0,0,NOW(),NOW())",
            [$psId, $this->idShop, $catId]
        );

        // INSERT ps_category_product
        $this->ps->query(
            "INSERT IGNORE INTO `{$ps}category_product` (id_category, id_product, position) VALUES (?,?,0)",
            [$catId, $psId]
        );

        // Stock
        $qty = max(0, (int)($meta['_stock'] ?? $meta['_stock_quantity'] ?? 0));
        $this->ps->query(
            "INSERT INTO `{$ps}stock_available`
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock)
             VALUES (?,0,?,0,?,0,2)
             ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)",
            [$psId, $this->idShop, $qty]
        );

        return $psId;
    }

    private function getProductCategory(int $wcId): int
    {
        $p    = $this->wcPrefix;
        $map  = $this->state['category_id_map'];

        $rows = $this->wc->query(
            "SELECT t.term_id FROM `{$p}terms` t
             JOIN `{$p}term_taxonomy` tt ON t.term_id=tt.term_id
             JOIN `{$p}term_relationships` tr ON tt.term_taxonomy_id=tr.term_taxonomy_id
             WHERE tr.object_id=? AND tt.taxonomy='product_cat' LIMIT 1",
            [$wcId]
        );

        if ($rows && isset($map[(string)$rows[0]['term_id']])) {
            return (int)$map[(string)$rows[0]['term_id']];
        }
        return 2; // home category
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($conv) $text = $conv;
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        return trim($text, '-') ?: 'produto';
    }

    private function log(string $level, string $step, string $msg): void
    {
        $this->db->log($this->jobId, $level, $step, $msg);
    }
}
