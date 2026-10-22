<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardSection;
use App\Modules\Board\Positions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Kolom papan format Shelf (BRD-05). */
class SectionController extends Controller
{
    public function store(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('manage', $board);
        $data = $request->validate(['title' => ['required', 'string', 'max:100']], [], ['title' => 'judul kolom']);

        BoardSection::create([
            'board_id' => $board->id,
            'title' => strip_tags($data['title']),
            'position' => Positions::between(($max = $board->sections()->max('position')) === null ? null : (float) $max, null),
        ]);
        $board->touch();

        return back();
    }

    public function update(Request $request, Board $board, BoardSection $section): RedirectResponse
    {
        $this->ensureBelongs($board, $section);
        Gate::authorize('manage', $board);
        $data = $request->validate(['title' => ['required', 'string', 'max:100']], [], ['title' => 'judul kolom']);

        $section->update(['title' => strip_tags($data['title'])]);

        return back();
    }

    public function move(Request $request, Board $board, BoardSection $section): RedirectResponse
    {
        $this->ensureBelongs($board, $section);
        Gate::authorize('manage', $board);
        $data = $request->validate(['after_id' => ['nullable', 'uuid'], 'before_id' => ['nullable', 'uuid']]);

        $pos = fn (?string $id) => $id ? $board->sections()->whereKey($id)->value('position') : null;
        $after = $pos($data['after_id'] ?? null);
        $before = $pos($data['before_id'] ?? null);
        $section->update(['position' => Positions::between($after === null ? null : (float) $after, $before === null ? null : (float) $before)]);

        return back();
    }

    /** Kartu di kolom yang dihapus pindah ke kolom pertama yang tersisa. */
    public function destroy(Board $board, BoardSection $section): RedirectResponse
    {
        $this->ensureBelongs($board, $section);
        Gate::authorize('manage', $board);

        $target = $board->sections()->whereKeyNot($section->id)->value('id');
        $board->posts()->where('section_id', $section->id)->update(['section_id' => $target]);
        $section->delete();
        $board->touch();

        return back()->with('success', 'Kolom dihapus. Kartunya dipindah ke kolom lain.');
    }

    private function ensureBelongs(Board $board, BoardSection $section): void
    {
        abort_unless($section->board_id === $board->id, 404);
    }
}
