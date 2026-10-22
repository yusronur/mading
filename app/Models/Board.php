<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Board extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    public const VISIBILITIES = [
        'private' => 'Hanya saya',
        'class' => 'Satu rombel',
        'school' => 'Seluruh sekolah',
        'link' => 'Siapa pun dengan tautan/kode rahasia',
    ];

    public const FORMATS = [
        'wall' => 'Dinding (Wall)',
        'grid' => 'Kisi (Grid)',
        'stream' => 'Linimasa (Stream)',
        'shelf' => 'Rak berkolom (Shelf)',
    ];

    public const SORTS = [
        'newest' => 'Terbaru di atas',
        'oldest' => 'Terlama di atas',
        'manual' => 'Manual (geser kartu)',
    ];

    /** Latar papan (nilai CSS ada di resources/js/lib/backgrounds.js). */
    public const BACKGROUNDS = ['none', 'sunrise', 'ocean', 'forest', 'candy', 'galaxy', 'dots', 'grid', 'paper'];

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Mikrodetik: penanda versi sinkron (ADR-014) peka terhadap perubahan beruntun. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'school_id', 'owner_id', 'class_id', 'folder_id', 'code', 'secret_token', 'title', 'description', 'icon', 'background', 'format', 'visibility',
        'visitor_permission', 'settings', 'theme_id', 'theme_locked', 'is_frozen', 'archived_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'theme_locked' => 'boolean',
        'is_frozen' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Board $board) {
            $board->code ??= static::generateCode();
        });
    }

    public static function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BoardSection::class)->orderBy('position');
    }

    /** Urutan kartu; membaca juga kunci lama new_post_position (0.0.2). */
    public function sortMode(): string
    {
        $sort = $this->settings['sort'] ?? null;
        if (isset(self::SORTS[$sort])) {
            return $sort;
        }

        return ($this->settings['new_post_position'] ?? 'top') === 'bottom' ? 'oldest' : 'newest';
    }

    public function reactionKind(): string
    {
        $kind = $this->settings['reaction'] ?? 'like';

        return isset(Reaction::KINDS[$kind]) ? $kind : 'like';
    }

    public function allowsComments(): bool
    {
        return (bool) ($this->settings['allow_comments'] ?? true);
    }

    public function requiresApproval(): bool
    {
        return (bool) ($this->settings['require_approval'] ?? false);
    }

    public function maxPostsPerStudent(): int
    {
        return max(0, (int) ($this->settings['max_posts'] ?? 0));
    }

    public function ensureSecretToken(): string
    {
        if (! $this->secret_token) {
            $this->secret_token = \Illuminate\Support\Str::random(32);
        }

        return $this->secret_token;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
