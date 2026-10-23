<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Post;
use App\Modules\Board\BoardPresenter;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ekspor papan: Excel/CSV lengkap (SHR-05) dan halaman cetak/PDF (SHR-04). */
class ExportController extends Controller
{
    public function spreadsheet(Request $request, Board $board, string $format): StreamedResponse
    {
        Gate::authorize('manage', $board);
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $posts = app(BoardPresenter::class)->orderedPosts($board)
            ->with(['author:id,name,username', 'attachments', 'section:id,title'])
            ->get();
        $reactionKind = $board->reactionKind();
        $scores = \Illuminate\Support\Facades\DB::table('reactions')->whereIn('post_id', $posts->pluck('id'))->where('kind', $reactionKind)
            ->groupBy('post_id')->selectRaw('post_id, count(*) n, avg(value) a, sum(value) s')->get()->keyBy('post_id');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Kartu');
        $head = ['No', 'Kolom', 'Judul', 'Isi', 'Tautan', 'Lampiran', 'Penulis', 'Username', 'Anonim', 'Status', 'Reaksi ('.$reactionKind.')', 'Komentar', 'Dibuat', 'Diubah'];
        $sheet->fromArray($head, null, 'A1');

        $row = 2;
        foreach ($posts as $i => $p) {
            $r = $scores[$p->id] ?? null;
            $reaction = match ($reactionKind) {
                'star', 'grade' => $r ? round((float) $r->a, 1) : '',
                'vote' => $r ? (int) $r->s : '',
                'like' => $r ? (int) $r->n : '',
                default => '',
            };

            $sheet->fromArray([
                $i + 1,
                $p->section?->title ?? '',
                $p->title ?? '',
                trim(HtmlSanitizer::toText($p->body)."\n".collect($p->checklist ?? [])->map(fn ($i) => ($i['done'] ? '[x] ' : '[ ] ').$i['text'])->implode("\n")),
                $p->link['url'] ?? '',
                $p->attachments->pluck('original_name')->implode(', '),
                $p->author?->name ?? '',
                $p->author?->username ?? '',
                $p->is_anonymous ? 'ya' : '',
                ['published' => 'tayang', 'pending' => 'ditahan', 'hidden' => 'disembunyikan'][$p->status] ?? $p->status,
                $reaction,
                $p->comment_count,
                $p->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                $p->edited_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
            ], null, 'A'.$row++, true);
        }

        if ($format === 'xlsx') {
            $sheet->getStyle('A1:N1')->getFont()->setBold(true);
            $sheet->getStyle('D:D')->getAlignment()->setWrapText(true);
            $sheet->getColumnDimension('D')->setWidth(60);
            foreach (['B', 'C', 'G', 'M', 'N'] as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            $sheet->freezePane('A2');

            $cs = $book->createSheet()->setTitle('Komentar');
            $cs->fromArray(['Judul kartu', 'Komentar', 'Penulis', 'Anonim', 'Balasan untuk', 'Status', 'Waktu'], null, 'A1');
            $cs->getStyle('A1:G1')->getFont()->setBold(true);
            $comments = Comment::whereIn('post_id', $posts->pluck('id'))->with('author:id,name')->orderBy('created_at')->get();
            $titles = $posts->pluck('title', 'id');
            $cr = 2;
            foreach ($comments as $c) {
                $cs->fromArray([
                    $titles[$c->post_id] ?? '', $c->body, $c->author?->name, $c->is_anonymous ? 'ya' : '',
                    $c->parent_id ? 'balasan' : '', $c->status, $c->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ], null, 'A'.$cr++, true);
            }
            $book->setActiveSheetIndex(0);
        }

        $filename = 'papan-'.\Illuminate\Support\Str::slug($board->title).'-'.now()->format('Ymd').'.'.$format;

        return response()->streamDownload(function () use ($book, $format) {
            if ($format === 'csv') {
                echo "\xEF\xBB\xBF"; // BOM agar Excel membaca UTF-8
                (new Csv($book))->setDelimiter(';')->save('php://output');
            } else {
                (new Xlsx($book))->save('php://output');
            }
        }, $filename, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Halaman cetak (simpan sebagai PDF lewat dialog cetak peramban). */
    public function print(Request $request, Board $board, BoardPresenter $presenter): InertiaResponse
    {
        Gate::authorize('view', $board);
        $user = $request->user();

        $posts = array_values(array_filter($presenter->posts($board, $user), fn ($p) => $p['status'] === 'published'));

        return Inertia::render('Boards/Print', [
            'board' => $presenter->board($board),
            'sections' => $presenter->sections($board),
            'posts' => $posts,
            'printedAt' => now()->timezone(config('app.timezone'))->translatedFormat('j F Y H:i'),
        ]);
    }
}
