<?php

namespace App\Classes;

class PosterSupplyManager {
    private PosterAPI $api;

    public function __construct(PosterAPI $api) {
        $this->api = $api;
    }

    /**
     * Изменяет счет оплаты у существующей поставки.
     * Чтобы Poster не затер данные, сначала получаем полную поставку,
     * вычищаем readonly-поля и отправляем обратно с новым account_id.
     *
     * @param int $supplyId ID поставки
     * @param int $newAccountId Новый ID счета
     * @return mixed Ответ от API Poster
     * @throws \Exception Если поставка в инвентаризации или нет ингредиентов
     */
    public function changeSupplyAccount(int $supplyId, int $newAccountId) {
        if ($supplyId <= 0) {
            throw new \InvalidArgumentException('Invalid supply_id');
        }
        if ($newAccountId <= 0) {
            throw new \InvalidArgumentException('Invalid account_id');
        }

        // Шаг 1 — читаем текущую поставку
        $supplyRes = $this->api->getSupply($supplyId);
        if (empty($supplyRes)) {
            throw new \Exception('Supply not found');
        }

        // Стоп если поставка в инвентаризации
        if ((int)($supplyRes['in_inventory'] ?? 0) === 1) {
            throw new \Exception("Поставка заблокирована (in_inventory=1), редактирование невозможно");
        }

        // Формируем список ингредиентов (пропускаем удалённые)
        $ingredients = [];
        if (isset($supplyRes['ingredients']) && is_array($supplyRes['ingredients'])) {
            foreach ($supplyRes['ingredients'] as $ing) {
                if ((string)($ing['ing_delete'] ?? '0') === '1') {
                    continue;
                }

                $rawType = (int)$ing['type'];
                
                // В getSupply ингредиенты приходят с ingredient_id, а товары/техкарты с product_id.
                // Нам нужно вытащить правильный ID для отправки обратно в updateSupply.
                $id = (int)($ing['ingredient_id'] ?? 0);
                if ($id === 0) {
                    $id = (int)($ing['product_id'] ?? 0);
                }

                // В getSupply типы: 1 (товар), 2 (техкарта), 3 (полуфабрикат), 8 (модификатор), 10 (ингредиент).
                // В updateSupply типы: 1 (товар/полуфабрикат/техкарта), 4 (ингредиент), 5 (модификатор).
                $type = $rawType;
                if ($rawType === 10) {
                    $type = 4;
                } elseif ($rawType === 8) {
                    $type = 5;
                } elseif ($rawType === 2 || $rawType === 3) {
                    $type = 1;
                }

                $num = (float)$ing['supply_ingredient_num'];
                // Poster отдает общую сумму в supply_ingredient_sum в копейках.
                // В updateSupply параметр sum — это сумма за ЕДИНИЦУ в валюте.
                $totalSumVnd = round((float)$ing['supply_ingredient_sum'] / 100, 2);
                $unitPrice = $num > 0 ? round($totalSumVnd / $num, 2) : 0;

                $item = [
                    'id'   => $id,
                    'type' => $type,
                    'num'  => $num,
                    // Для updateSupply передаем цену за единицу!
                    'sum'  => $unitPrice,
                ];

                if (!empty($ing['pack_id'])) {
                    $item['packing'] = $ing['pack_id'];
                }

                if (!empty($ing['tax_id'])) {
                    $item['tax_id'] = $ing['tax_id'];
                }

                $ingredients[] = $item;
            }
        }

        if (empty($ingredients)) {
            throw new \Exception('Поставка не содержит ингредиентов, обновление невозможно');
        }

        // Шаг 2 — обновляем с новым account_id и строго заданными параметрами поставки
        $supplyData = [
            'supply_id'      => $supplyId,
            'storage_id'     => $supplyRes['storage_id'],
            'supplier_id'    => $supplyRes['supplier_id'],
            'date'           => $supplyRes['date'],
            'supply_comment' => $supplyRes['supply_comment'] ?? '',
            'account_id'     => $newAccountId,
        ];

        $payload = [
            'supply' => $supplyData,
            'ingredient' => $ingredients,
        ];

        return $this->api->updateSupply($payload);
    }
}
