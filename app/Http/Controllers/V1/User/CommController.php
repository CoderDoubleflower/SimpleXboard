<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;

class CommController extends Controller
{
    public function config()
    {
        $data = [
            'is_telegram' => (int)admin_setting('telegram_bot_enable', 0),
            'telegram_discuss_link' => admin_setting('telegram_discuss_link'),
            // The bundled theme still reads these values. They cannot enable billing.
            'withdraw_methods' => [],
            'withdraw_close' => 1,
            'currency' => '',
            'currency_symbol' => '',
            'commission_distribution_enable' => 0,
        ];
        return $this->success($data);
    }

}
