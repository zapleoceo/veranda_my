<?php

declare(strict_types=1);

namespace App\Actions;

interface ActionInterface
{
    /**
     * Execute the action.
     * Returns a short feedback string shown to the user via answerCallbackQuery.
     */
    public function handle(ActionContext $ctx): string;
}
