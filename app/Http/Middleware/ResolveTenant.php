<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private TenantResolver $resolver,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        \App\Modules\Board\BoardPolicy::flush(); // cache hak akses hanya berlaku per permintaan
        $school = $this->resolver->resolve($request);

        abort_if($school === null, 404, 'Sekolah tidak ditemukan.');

        $this->context->set($school);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
