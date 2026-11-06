<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrafficLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "d" => $this['d'],
            "u" => $this['u'],
            "record_at" =>  $this['record_at'],
            "server_rate" => $this['server_rate'],
            "user_id" => $this['user_id'],
        ];
    }
}
