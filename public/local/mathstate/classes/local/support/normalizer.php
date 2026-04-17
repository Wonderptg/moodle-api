<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_mathstate\local\support;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared normalization helpers for local_mathstate.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class normalizer {
    public static function encode_json($value): string {
        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    public static function decode_json_list(?string $value): array {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    public static function normalize_string_list(array $items): array {
        $result = [];
        foreach ($items as $item) {
            $text = trim((string)$item);
            if ($text === '') {
                continue;
            }
            $result[$text] = $text;
        }

        return array_values($result);
    }

    public static function optional_text(string $value): ?string {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    public static function clamp_score(float $score): float {
        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 100.0) {
            return 100.0;
        }
        return round($score, 2);
    }
}
