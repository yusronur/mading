<?php

namespace App\Modules\Board;

use App\Core\Realtime\Signal;
use App\Events\BoardUpdated;
use App\Models\ActivityResponse;
use App\Models\Board;
use App\Models\BoardActivity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Aktivitas live di papan (LIV-01..04): timer, poll, word cloud, spin wheel.
 * Satu papan hanya punya satu aktivitas berjalan; yang baru menggantikan yang lama.
 * Semua keputusan (hasil undian, penghitungan suara) dibuat di server.
 */
class Activities
{
    public const MAX_WORDS_SHOWN = 80;

    public const WORD_LENGTH = 40;

    public function __construct(private ProfanityFilter $profanity) {}

    public function current(Board $board): ?BoardActivity
    {
        return BoardActivity::where('board_id', $board->id)->whereIn('status', ['open', 'closed'])
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** Bangun pengaturan aktivitas dari isian guru (sudah divalidasi bentuknya di controller). */
    public function buildConfig(Board $board, string $type, array $d): array
    {
        return match ($type) {
            'timer' => ['seconds' => (int) $d['seconds']],
            'poll' => [
                'question' => trim($d['question']),
                'options' => collect($d['options'])->map(fn ($t) => trim((string) $t))->filter()->values()
                    ->map(fn ($t, $i) => ['id' => 'o'.($i + 1), 'text' => mb_substr($t, 0, 80)])->all(),
                'results' => ($d['results'] ?? 'live') === 'closed' ? 'closed' : 'live',
            ],
            'cloud' => ['prompt' => trim($d['prompt']), 'max_words' => max(1, min(5, (int) ($d['max_words'] ?? 3)))],
            'wheel' => [
                'names' => $this->wheelNames($board, $d),
                'no_repeat' => (bool) ($d['no_repeat'] ?? true),
            ],
        };
    }

    private function wheelNames(Board $board, array $d): array
    {
        if (($d['source'] ?? 'list') === 'class') {
            if (! $board->class_id) {
                throw new RuntimeException('Papan ini tidak terhubung ke rombel. Pilih "Ketik daftar nama".');
            }
            $names = DB::table('class_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.class_id', $board->class_id)->where('u.role', User::ROLE_STUDENT)->whereNull('u.deleted_at')
                ->orderBy('u.name')->pluck('u.name')->all();
        } else {
            $names = preg_split('/\R+/u', (string) ($d['names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        }

        $names = array_values(array_unique(array_filter(array_map(fn ($n) => mb_substr(trim($n), 0, 40), $names))));
        if (count($names) < 2) {
            throw new RuntimeException('Spin wheel butuh minimal 2 nama.');
        }
        if (count($names) > 100) {
            throw new RuntimeException('Maksimal 100 nama untuk satu putaran.');
        }

        return $names;
    }

    public function start(Board $board, User $by, string $type, ?string $title, array $config): BoardActivity
    {
        $state = match ($type) {
            'timer' => ['ends_at' => now()->addSeconds($config['seconds'])->toIso8601ZuluString('millisecond')],
            'wheel' => ['picked' => [], 'spin' => null],
            default => null,
        };

        $activity = DB::transaction(function () use ($board, $by, $type, $title, $config, $state) {
            BoardActivity::where('board_id', $board->id)->whereIn('status', ['open', 'closed'])->update(['status' => 'ended']);

            return BoardActivity::create([
                'board_id' => $board->id,
                'created_by' => $by->id,
                'type' => $type,
                'status' => 'open',
                'title' => $title ? mb_substr(trim($title), 0, 200) : null,
                'config' => $config,
                'state' => $state,
            ]);
        });

        $this->signal($board);

        return $activity;
    }

    // ------------------------------------------------------------ tampilan

    public function present(BoardActivity $a, User $viewer, bool $manage): array
    {
        $c = $a->config;
        $out = [
            'id' => $a->id,
            'type' => $a->type,
            'status' => $a->status,
            'title' => $a->title,
            'config' => $a->type === 'poll'
                ? ['question' => $c['question'], 'options' => $c['options'], 'results' => $c['results']]
                : $c,
            'state' => $a->state,
            'can_manage' => $manage,
            'server_now' => now()->toIso8601ZuluString('millisecond'),
            'mine' => [],
            'responses' => 0,
        ];

        if ($a->type === 'poll') {
            $rows = DB::table('activity_responses')->where('activity_id', $a->id)->selectRaw('value, count(*) as n')->groupBy('value')->pluck('n', 'value');
            $total = (int) $rows->sum();
            $show = $c['results'] === 'live' || $a->status === 'closed' || $manage;
            $out['responses'] = $total;
            $out['results'] = $show ? array_map(fn ($o) => (int) ($rows[$o['id']] ?? 0), $c['options']) : null;
            $out['mine'] = DB::table('activity_responses')->where('activity_id', $a->id)->where('user_id', $viewer->id)->pluck('value')->all();
        } elseif ($a->type === 'cloud') {
            $rows = DB::table('activity_responses')->where('activity_id', $a->id)->selectRaw('value, count(*) as n')
                ->groupBy('value')->orderByDesc('n')->orderBy('value')->limit(self::MAX_WORDS_SHOWN)->get();
            $out['responses'] = (int) DB::table('activity_responses')->where('activity_id', $a->id)->count();
            $out['words'] = $rows->map(fn ($r) => ['text' => $r->value, 'count' => (int) $r->n])->all();
            $out['mine'] = DB::table('activity_responses')->where('activity_id', $a->id)->where('user_id', $viewer->id)->pluck('value')->all();
        }

        return $out;
    }

    // ------------------------------------------------------------ jawaban siswa

    public function respond(BoardActivity $a, User $user, mixed $input): void
    {
        if (! $a->isOpen()) {
            throw new RuntimeException('Aktivitas ini sudah ditutup.');
        }

        match ($a->type) {
            'poll' => $this->vote($a, $user, (string) $input),
            'cloud' => $this->addWords($a, $user, (array) $input),
            default => throw new RuntimeException('Aktivitas ini tidak menerima jawaban.'),
        };

        $this->signal($a->board);
    }

    private function vote(BoardActivity $a, User $user, string $choice): void
    {
        if (! in_array($choice, array_column($a->config['options'], 'id'), true)) {
            throw new RuntimeException('Pilihan tidak dikenal.');
        }

        DB::transaction(function () use ($a, $user, $choice) {
            // Boleh mengganti pilihan selama poll masih dibuka
            ActivityResponse::where('activity_id', $a->id)->where('user_id', $user->id)->delete();
            ActivityResponse::create(['activity_id' => $a->id, 'user_id' => $user->id, 'value' => $choice]);
        });
    }

    private function addWords(BoardActivity $a, User $user, array $words): void
    {
        $max = (int) ($a->config['max_words'] ?? 3);
        $clean = [];
        foreach ($words as $w) {
            $w = $this->normalizeWord((string) $w);
            if ($w !== '') {
                $clean[$w] = true;
            }
        }
        $clean = array_keys($clean);

        if (! $clean) {
            throw new RuntimeException('Ketik kata atau frasa dulu.');
        }
        foreach ($clean as $w) {
            if ($this->profanity->find($w)) {
                throw new RuntimeException('Ada kata yang kurang pantas. Coba kata lain.');
            }
        }

        DB::transaction(function () use ($a, $user, $clean, $max) {
            // Kunci aktivitas agar batas kata per siswa tidak terlewati oleh permintaan bersamaan
            BoardActivity::whereKey($a->id)->lockForUpdate()->first();
            $have = ActivityResponse::where('activity_id', $a->id)->where('user_id', $user->id)->pluck('value')->all();
            $new = array_values(array_diff($clean, $have));

            if (count($have) + count($new) > $max) {
                throw new RuntimeException("Maksimal {$max} kata per orang.");
            }
            foreach ($new as $w) {
                try {
                    ActivityResponse::create(['activity_id' => $a->id, 'user_id' => $user->id, 'value' => $w]);
                } catch (UniqueConstraintViolationException) {
                    // sudah ada: abaikan
                }
            }
        });
    }

    public function normalizeWord(string $w): string
    {
        $w = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($w))));

        return mb_substr($w, 0, self::WORD_LENGTH);
    }

    // ------------------------------------------------------------ aksi guru

    public function act(BoardActivity $a, string $action, array $data = []): void
    {
        $state = $a->state ?? [];

        switch ($action) {
            case 'close':
                $a->status = 'closed';
                break;
            case 'reopen':
                if (in_array($a->type, ['poll', 'cloud'], true)) {
                    $a->status = 'open';
                }
                break;
            case 'end':
                $a->status = 'ended';
                break;
            case 'restart': // timer
                if ($a->type !== 'timer') {
                    throw new RuntimeException('Hanya untuk timer.');
                }
                $seconds = (int) ($data['seconds'] ?? $a->config['seconds']);
                $seconds = max(5, min(7200, $seconds));
                $a->config = ['seconds' => $seconds] + $a->config;
                $state['ends_at'] = now()->addSeconds($seconds)->toIso8601ZuluString('millisecond');
                $a->status = 'open';
                break;
            case 'spin':
                if ($a->type !== 'wheel') {
                    throw new RuntimeException('Hanya untuk spin wheel.');
                }
                $state = $this->spin($a, $state);
                break;
            case 'reset_wheel':
                $state['picked'] = [];
                $state['spin'] = null;
                break;
            case 'remove_word':
                if ($a->type === 'cloud') {
                    ActivityResponse::where('activity_id', $a->id)->where('value', $this->normalizeWord((string) ($data['text'] ?? '')))->delete();
                }
                break;
            default:
                throw new RuntimeException('Aksi tidak dikenal.');
        }

        $a->state = $a->type === 'poll' || $a->type === 'cloud' ? $a->state : $state;
        $a->save();
        $a->touch();
        $this->signal($a->board);
    }

    /** Pemenang diundi di server dengan random_int, bukan di peramban. */
    private function spin(BoardActivity $a, array $state): array
    {
        $names = $a->config['names'];
        $picked = $state['picked'] ?? [];
        $pool = ($a->config['no_repeat'] ?? true) ? array_values(array_diff(array_keys($names), $picked)) : array_keys($names);

        if (! $pool) {
            throw new RuntimeException('Semua nama sudah terpilih. Tekan "Atur ulang" untuk mulai lagi.');
        }

        $index = $pool[random_int(0, count($pool) - 1)];
        $state['picked'] = array_values(array_unique([...$picked, $index]));
        $state['spin'] = ['id' => (string) Str::uuid(), 'index' => $index, 'at' => now()->toIso8601ZuluString('millisecond')];

        return $state;
    }

    private function signal(Board $board): void
    {
        Signal::send(new BoardUpdated($board->school_id, $board->id, 'activity'));
    }

    public function channel(Board $board): string
    {
        return BoardUpdated::channel($board->school_id, $board->id);
    }

    public function canManage(User $user, Board $board): bool
    {
        return Gate::forUser($user)->allows('manage', $board);
    }
}
