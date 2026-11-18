<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

/** Serves docs/api/openapi.yaml and an interactive viewer for the integration teams. */
class OpenApiController extends Controller
{
    public function spec(): Response
    {
        $yaml = (string) file_get_contents(base_path('docs/api/openapi.yaml'));
        $yaml = str_replace('https://api.domainname.org/api/v1', rtrim((string) config('uec.urls.api'), '/').'/api/v1', $yaml);

        return response($yaml, 200, ['Content-Type' => 'application/yaml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }

    public function viewer(): View
    {
        return view('admin.api-docs', ['specUrl' => route('admin.api-docs.spec')]);
    }
}
