<?php

namespace App\Core\Database;

use InvalidArgumentException;
use PDO;

/**
 * Menyiapkan database dan peran aplikasi memakai akun pemilik (superuser).
 * Murni PDO agar bisa dipakai sebelum Laravel tersambung ke database
 * (perintah madingo:install dan bootstrap pengujian).
 */
class Provisioner
{
    public function __construct(
        private string $host,
        private string $port,
        private string $ownerUser,
        private string $ownerPassword,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            (string) (getenv('DB_HOST') ?: '127.0.0.1'),
            (string) (getenv('DB_PORT') ?: '5432'),
            (string) (getenv('DB_OWNER_USERNAME') ?: 'postgres'),
            (string) (getenv('DB_OWNER_PASSWORD') ?: ''),
        );
    }

    public function ensureDatabase(string $database): bool
    {
        self::assertIdent($database);
        $pdo = $this->connect('postgres');

        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$database]);

        if ($exists->fetchColumn()) {
            return false;
        }

        $pdo->exec('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\'');

        return true;
    }

    /**
     * Buat (atau perbarui sandi) peran aplikasi: login, bukan superuser,
     * tidak bisa membuat database/peran, tidak melewati RLS.
     */
    public function ensureAppRole(string $role, string $password, string $database): void
    {
        self::assertIdent($role);
        self::assertIdent($database);
        $pdo = $this->connect('postgres');

        $exists = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);
        $quoted = $pdo->quote($password);

        if ($exists->fetchColumn()) {
            $pdo->exec("ALTER ROLE \"{$role}\" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD {$quoted}");
        } else {
            $pdo->exec("CREATE ROLE \"{$role}\" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD {$quoted}");
        }

        $pdo->exec("GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$role}\"");
    }

    /**
     * Hak akses data untuk peran aplikasi. Dijalankan setiap kali migrasi
     * selesai sehingga tabel baru otomatis ikut.
     */
    public function grantAppRole(string $role, string $database): void
    {
        self::assertIdent($role);
        $pdo = $this->connect($database);

        $exists = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);

        if (! $exists->fetchColumn()) {
            return;
        }

        $pdo->exec("GRANT USAGE ON SCHEMA public TO \"{$role}\"");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"{$role}\"");
        $pdo->exec("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO \"{$role}\"");
        $pdo->exec("REVOKE INSERT, UPDATE, DELETE ON TABLE migrations FROM \"{$role}\"");
    }

    private function connect(string $database): PDO
    {
        return new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$database}",
            $this->ownerUser,
            $this->ownerPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function assertIdent(string $name): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name)) {
            throw new InvalidArgumentException("Nama tidak valid (huruf kecil, angka, garis bawah): {$name}");
        }
    }
}
