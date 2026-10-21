<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $guarded = [];

    /**
     * Decode payload safely to array.
     */
    public function getPayloadArrayAttribute(): ?array
    {
        if (empty($this->payload)) {
            return null;
        }

        $data = $this->payload;
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode($data, true);
        if (is_string($decoded)) {
            $nested = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($nested)) {
                return $nested;
            }
        }

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Pretty-print JSON payload for display.
     */
    public function getFormattedPayloadAttribute(): ?string
    {
        if (empty($this->payload)) {
            return null;
        }

        $array = $this->payload_array;
        if ($array !== null) {
            return json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return is_string($this->payload) ? $this->payload : json_encode($this->payload, JSON_PRETTY_PRINT);
    }
}
