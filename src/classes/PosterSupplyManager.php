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
     * @return array Ответ от API Poster
     * @throws \Exception Если поставка в инвентаризации или нет ингредиентов
     */
    public function changeSupplyAccount(int $supplyId, int $newAccountId): array {
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

                $item = [
                    'id'   => $ing['ingredient_id'],
                    'type' => (int)$ing['type'],
                    'num'  => (float)$ing['supply_ingredient_num'],
                    // Переводим копейки в рубли/донги
                    'sum'  => round((float)$ing['supply_ingredient_sum'] / 100, 2),
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

        // Шаг 2 — обновляем с новым account_id и всеми остальными параметрами поставки
        // Копируем все свойства поставки, чтобы ничего не потерять
        $supplyData = $supplyRes;
        
        // Удаляем ingredients, так как они передаются в отдельном массиве
        unset($supplyData['ingredients']);
        
        // Удаляем read-only поля и служебную информацию, которые Poster вычисляет сам
        unset($supplyData['in_inventory']);
        unset($supplyData['total_sum']);
        unset($supplyData['supply_sum']);
        unset($supplyData['supply_sum_netto']);
        unset($supplyData['products']);
        unset($supplyData['delete']);

        // Устанавливаем новый account_id
        $supplyData['account_id'] = $newAccountId;

        $payload = [
            'supply' => $supplyData,
            'ingredient' => $ingredients,
        ];

        return $this->api->updateSupply($payload);
    }
}
