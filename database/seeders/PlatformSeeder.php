<?php

namespace Database\Seeders;

use App\Core\Theme\BuiltinThemes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Data platform: tema bawaan (school_id NULL). Aman dijalankan berulang. */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        foreach (BuiltinThemes::all() as $theme) {
            $existing = DB::table('themes')->whereNull('school_id')->where('key', $theme['key'])->first();

            $values = [
                'name' => $theme['name'],
                'tokens' => json_encode($theme['tokens']),
                'is_builtin' => true,
                'xp_required' => $theme['xp_required'] ?? 0,
                'sort' => $theme['sort'],
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('themes')->where('id', $existing->id)->update($values);
            } else {
                DB::table('themes')->insert($values + [
                    'id' => (string) Str::uuid7(),
                    'school_id' => null,
                    'key' => $theme['key'],
                    'created_at' => now(),
                ]);
            }
        }
    }
}
