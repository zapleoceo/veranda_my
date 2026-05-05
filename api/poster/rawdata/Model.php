<?php

require_once __DIR__ . '/../../../src/classes/PosterAPI.php';

class ApiPosterRawdataModel
{
    private \App\Classes\PosterAPI $api;

    public function __construct(\App\Classes\PosterAPI $api)
    {
        $this->api = $api;
    }

    public function getProductsMap(int $cacheTtlSec = 21600): array
    {
        $productNames = [];
        $productMainCategory = [];
        $productSubCategory = [];

        if (
            !empty($_SESSION['products_cache_ts']) &&
            (time() - (int)$_SESSION['products_cache_ts']) < $cacheTtlSec &&
            isset($_SESSION['products_cache_names'], $_SESSION['products_cache_main'], $_SESSION['products_cache_sub'])
        ) {
            $productNames = (array)$_SESSION['products_cache_names'];
            $productMainCategory = (array)$_SESSION['products_cache_main'];
            $productSubCategory = (array)$_SESSION['products_cache_sub'];

            return [$productNames, $productMainCategory, $productSubCategory];
        }

        $productsRaw = $this->api->request('menu.getProducts');
        foreach ($productsRaw as $p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $productNames[$pid] = $p['product_name'] ?? ('Product #' . $pid);
            $productMainCategory[$pid] = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
            $productSubCategory[$pid] = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);
        }

        $_SESSION['products_cache_ts'] = time();
        $_SESSION['products_cache_names'] = $productNames;
        $_SESSION['products_cache_main'] = $productMainCategory;
        $_SESSION['products_cache_sub'] = $productSubCategory;

        return [$productNames, $productMainCategory, $productSubCategory];
    }
}

