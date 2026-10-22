<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Konvensi UI (ADR-013): tidak ada dialog bawaan peramban. Gunakan askConfirm()
 * (lib/confirm.js) dan toast() (lib/toast.js).
 */
class NoNativeDialogsTest extends TestCase
{
    public function test_no_native_alert_confirm_or_prompt_in_frontend(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(js|vue)$/', $file->getFilename())) {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                $code = preg_replace('#//.*$#', '', $line);
                if (preg_match('/(?<![\w.$])(?:window\.)?(?:alert|confirm|prompt)\s*\(/', $code)) {
                    $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Dialog bawaan peramban dilarang. Pakai askConfirm()/toast().');
    }

    /** Pemilih tanggal/jam bawaan peramban tampil berbeda di tiap perangkat: pakai DateField. */
    public function test_no_native_date_inputs_in_frontend(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.vue$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/type=["\'](?:date|datetime-local|time|month|week)["\']/', $line)) {
                    $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Input tanggal bawaan peramban dilarang. Pakai Components/DateField.vue.');
    }
}
